<?php

declare(strict_types=1);

namespace Blax\Mail\Services;

use Blax\Mail\Contracts\Poller;
use Blax\Mail\Enums\MailDirection;
use Blax\Mail\Enums\MailEventType;
use Blax\Mail\Enums\MailStatus;
use Blax\Mail\Enums\RecipientKind;
use Blax\Mail\Events\InboundMailReceived;
use Blax\Mail\Models\MailEvent;
use Blax\Mail\Models\MailMessage;
use Blax\Mail\Models\MailRecipient;
use Blax\Mail\Models\Mailbox as MailboxModel;
use DirectoryTree\ImapEngine\Mailbox as ImapMailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * IMAP poller — fetches new messages from a Mailbox's configured
 * folder and persists each one as an inbound `MailMessage` row.
 *
 * Backed by `directorytree/imapengine` (pure PHP, no `php-imap`
 * extension). The library exposes a fluent API:
 *
 *     $mailbox->folders()->find('INBOX')->messages()->unseen()->get()
 *
 * The poller asks for unseen messages above the highest UID we've
 * already seen (tracked in `Mailbox.meta.last_imap_uid`). That's
 * cheaper than relying on the IMAP "seen" flag — the user opening a
 * message in their mail client marks it seen and we'd miss it on the
 * next poll.
 *
 * Each persisted row is run through `MessageThreader::thread()` so
 * inbound replies link back to their outbound parents automatically.
 * After persistence the poller fires `InboundMailReceived` so host
 * apps can run their own routing (e.g. attach to the Customer whose
 * email matches the From header).
 *
 * Failures per-mailbox are isolated: a bad credential on one mailbox
 * doesn't abort the whole batch — the error sticks on
 * `Mailbox.last_error` and the next poll retries.
 */
class ImapPoller implements Poller
{
    public function __construct(
        protected MessageThreader $threader,
    ) {
    }

    public function poll(MailboxModel $mailbox): int
    {
        if (! $mailbox->canReceive()) {
            throw new \RuntimeException(
                "Mailbox '{$mailbox->name}' is not configured for inbound (canReceive === false)."
            );
        }

        $persisted = 0;
        try {
            $imap = $this->connect($mailbox);
            $folderName = $mailbox->imap_folder ?? config('blax-mail.imap.default_folder', 'INBOX');
            $folder = $imap->folders()->find($folderName);

            if (! $folder) {
                throw new \RuntimeException("IMAP folder '{$folderName}' not found on mailbox '{$mailbox->name}'.");
            }

            // Build the message query: unseen + UID greater than last
            // known watermark. UID-greater catches re-seen messages
            // (mail-client mark-as-read happens AFTER our poll) and
            // unseen narrows the cold-start fetch to actually new ones.
            $query = $folder->messages();
            $lastUid = (int) (data_get($mailbox->meta, 'last_imap_uid') ?? 0);
            if ($lastUid > 0) {
                // imapengine: `since` filter on UID isn't 1:1, so we
                // fetch unseen + then filter by UID locally. For a
                // first-time poll (lastUid=0) we cap by fetch_limit
                // so a 50k-message INBOX doesn't OOM the worker.
                $query = $query->unseen();
            } else {
                $query = $query->all();
            }

            // imapengine fetches `uid()` + `size()` only by default — the
            // RFC822 head / body / flags are opt-in via these toggles, and
            // *all* the message accessors (`from()`, `subject()`, `text()`,
            // `html()`, `headers()`, `attachments()`, …) silently return
            // null/empty when their underlying fetch wasn't requested. We
            // need every field down-chain, so opt into headers + body +
            // body structure (the BODYSTRUCTURE is what `attachments()`
            // walks). `withFlags()` keeps `isSeen()`-style checks working
            // for callers that read flags off the persisted row.
            $limit = (int) config('blax-mail.imap.fetch_limit', 200);
            $messages = $query
                ->withHeaders()
                ->withBody()
                ->withBodyStructure()
                ->withFlags()
                ->limit($limit)
                ->get();

            $highWater = $lastUid;
            foreach ($messages as $imapMessage) {
                $uid = (int) $imapMessage->uid();
                if ($lastUid > 0 && $uid <= $lastUid) {
                    continue;
                }
                $highWater = max($highWater, $uid);

                try {
                    $persistedRow = $this->persist($mailbox, $imapMessage);
                    if ($persistedRow) {
                        $persisted++;
                    }
                } catch (\Throwable $e) {
                    // Per-message failures are logged but don't abort
                    // the batch — the next poll will retry the UID
                    // because we only advance the watermark on success.
                    Log::warning('blax-mail: failed to persist inbound message', [
                        'mailbox_id' => $mailbox->id,
                        'uid' => $uid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Watermark only advances when the whole batch ran without
            // a connection-level error. If a single message threw, the
            // batch still moves forward — those individual UIDs are
            // skipped on the next poll, but a fresh `lastUid` lets us
            // catch the next 200.
            if ($highWater > $lastUid) {
                $meta = (array) ($mailbox->meta ?? []);
                $meta['last_imap_uid'] = $highWater;
                $mailbox->meta = $meta;
                $mailbox->save();
            }

            $mailbox->markPolled(now());
        } catch (\Throwable $e) {
            $mailbox->markPollFailed($e->getMessage());
            throw $e;
        }

        return $persisted;
    }

    /**
     * Open an authenticated connection to the mailbox's IMAP server.
     * `imapengine`'s `Mailbox` is a stateless config wrapper — calling
     * `folders()` (and friends) is what actually opens the connection.
     */
    protected function connect(MailboxModel $mailbox): ImapMailbox
    {
        return new ImapMailbox([
            'host' => $mailbox->imap_host,
            'port' => $mailbox->imap_port,
            'encryption' => $mailbox->imap_encryption,
            'validate_cert' => true,
            'username' => $mailbox->imap_username,
            // Decrypted by the model's `encrypted` cast.
            'password' => $mailbox->imap_password,
            'timeout' => 30,
        ]);
    }

    /**
     * Persist one IMAP message as an inbound `MailMessage` row. Idempotent
     * by `message_id` — if the row already exists (the poller re-ran or
     * the IMAP server re-delivered the same UID for some reason) we skip.
     *
     * Returns the persisted row, or null when the message was a duplicate.
     */
    protected function persist(MailboxModel $mailbox, $imapMessage): ?MailMessage
    {
        // Pull threading IDs through the Message's dedicated accessors,
        // not via `headers()->get()` — `headers()` returns a plain array
        // of ZBateson parser objects (no `->get()`), and `inReplyTo()` +
        // `header()` already do the right header lookup + decoding for
        // us. `messageId()` / `inReplyTo()` come back un-bracketed; the
        // normalizer re-applies `<…>` so the canonical comparison key
        // matches what the threader writes for outbound rows.
        $messageId = $this->normalizeHeaderId((string) ($imapMessage->messageId() ?? ''));
        $inReplyToRaw = $imapMessage->inReplyTo();
        $inReplyToFirst = is_array($inReplyToRaw) ? ($inReplyToRaw[0] ?? '') : (string) $inReplyToRaw;
        $inReplyTo = $this->normalizeHeaderId((string) $inReplyToFirst);
        $references = trim((string) ($imapMessage->header('References') ?? ''));

        if ($messageId === '') {
            // No Message-ID is rare-but-legal; synthesize one so the
            // unique threading lookups don't conflate distinct mails.
            $messageId = '<no-id-'.Str::random(24).'@blax-mail>';
        }

        // Idempotency check.
        if (MailMessage::query()->where('message_id', $messageId)->exists()) {
            return null;
        }

        $message = DB::transaction(function () use ($mailbox, $imapMessage, $messageId, $inReplyTo, $references) {
            $from = $imapMessage->from();
            $to = $this->addressList($imapMessage->to());
            $cc = $this->addressList($imapMessage->cc());
            $bcc = $this->addressList($imapMessage->bcc());

            $row = MailMessage::create([
                'mailbox_id' => $mailbox->id,
                'direction' => MailDirection::Inbound,
                'status' => MailStatus::Received,
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo ?: null,
                'references' => $references ?: null,
                'subject' => (string) ($imapMessage->subject() ?? ''),
                'body_text' => (string) ($imapMessage->text() ?? ''),
                'body_html' => (string) ($imapMessage->html() ?? '') ?: null,
                'raw_headers' => $this->serializeHeaders($imapMessage),
                'from_address' => $from?->email(),
                'from_name' => $from?->name(),
                'to' => array_column($to, 'address'),
                'cc' => array_column($cc, 'address'),
                'bcc' => array_column($bcc, 'address'),
                // `date()` already returns a Carbon instance — re-parsing
                // it via `Carbon::parse(Carbon)` is a no-op but more
                // importantly the prior guard (`?->toIso8601String()
                // ? parse : now()`) was always-true for any non-null
                // Carbon, so the ternary was just obscuring the simple
                // null-coalesce we actually want here.
                'received_at' => $imapMessage->date() ?? now(),
                'meta' => [
                    'imap_uid' => $imapMessage->uid(),
                ],
            ]);

            $this->persistRecipients($row, RecipientKind::To, $to);
            $this->persistRecipients($row, RecipientKind::Cc, $cc);
            $this->persistRecipients($row, RecipientKind::Bcc, $bcc);
            $this->persistAttachments($row, $imapMessage);

            MailEvent::record($row->id, MailEventType::Received);

            return $row;
        });

        // Thread + event AFTER the transaction commits — listeners
        // running synchronously will read the persisted row + its
        // recipients, and the threader uses an indexed lookup that
        // benefits from the row already being committed.
        $parent = $this->threader->thread($message);

        InboundMailReceived::dispatch($message, $parent);

        return $message;
    }

    /** RFC 5322 IDs come bracketed (`<id@domain>`); strip for storage. */
    protected function normalizeHeaderId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // Some clients put multiple IDs in In-Reply-To; we keep only the
        // first one for the indexed equality lookup.
        if (preg_match('/<([^>]+)>/', $raw, $matches)) {
            return '<'.$matches[1].'>';
        }

        return '<'.$raw.'>';
    }

    /**
     * Normalize an address-bag (`To` / `Cc` / `Bcc` header) into a flat
     * list of `[address, name]` arrays the persistence code consumes.
     */
    protected function addressList($bag): array
    {
        if (! $bag) {
            return [];
        }
        $out = [];
        foreach ($bag as $entry) {
            $email = method_exists($entry, 'email') ? $entry->email() : null;
            if (! $email) {
                continue;
            }
            $out[] = [
                'address' => MailRecipient::normalizeAddress($email),
                'name' => method_exists($entry, 'name') ? $entry->name() : null,
                'raw' => $email,
            ];
        }

        return $out;
    }

    protected function persistRecipients(MailMessage $row, RecipientKind $kind, array $addresses): void
    {
        foreach ($addresses as $address) {
            if (empty($address['address'])) {
                continue;
            }
            $row->recipients()->create([
                'kind' => $kind,
                'address' => $address['address'],
                'name' => $address['name'] ?? null,
                'meta' => $address['address'] === $address['raw'] ? null : ['raw' => $address['raw']],
            ]);
        }
    }

    protected function persistAttachments(MailMessage $row, $imapMessage): void
    {
        $download = (bool) config('blax-mail.imap.attachments.download', true);
        $disk = (string) config('blax-mail.imap.attachments.disk', 'local');
        $prefix = trim((string) config('blax-mail.imap.attachments.path_prefix', 'blax-mail/attachments'), '/');
        $maxBytes = (int) config('blax-mail.imap.attachments.max_bytes', 25 * 1024 * 1024);

        foreach ($imapMessage->attachments() as $attachment) {
            $bytes = $attachment->content();
            $size = strlen((string) $bytes);
            $tooBig = $maxBytes > 0 && $size > $maxBytes;

            $storagePath = null;
            $storageDisk = null;
            if ($download && ! $tooBig && $bytes !== null) {
                $filename = $attachment->filename() ?: 'attachment-'.Str::random(8);
                $storagePath = $prefix.'/'.$row->id.'/'.Str::uuid().'-'.$filename;
                Storage::disk($disk)->put($storagePath, $bytes);
                $storageDisk = $disk;
            }

            $row->attachments()->create([
                'filename' => $attachment->filename() ?: '(unnamed)',
                'mime_type' => $attachment->contentType(),
                'size_bytes' => $size,
                'storage_disk' => $storageDisk,
                'storage_path' => $storagePath,
                'content_id' => $attachment->contentId(),
                'inline' => $attachment->contentId() !== null,
                'checksum' => $bytes !== null ? hash('sha256', $bytes) : null,
                'meta' => $tooBig ? ['skipped_reason' => 'over_max_bytes'] : null,
            ]);
        }
    }

    protected function serializeHeaders($imapMessage): array
    {
        // `headers()` returns ZBateson parser instances (AddressHeader,
        // GenericHeader, …) whose public API is `getName()` /
        // `getValue()` / `getRawValue()` — not the `name()` / `value()`
        // shortcut on imapengine's own header type. We probe `getName()`
        // first (covers every ZBateson subclass) and fall back to
        // `name()` if a future imapengine version swaps the parser.
        $out = [];
        foreach ((array) $imapMessage->headers() as $header) {
            if (method_exists($header, 'getName')) {
                $name = $header->getName();
                $value = method_exists($header, 'getValue') ? $header->getValue() : null;
            } elseif (method_exists($header, 'name')) {
                $name = $header->name();
                $value = method_exists($header, 'value') ? $header->value() : null;
            } else {
                continue;
            }
            if (! $name) {
                continue;
            }
            $out[$name] = $value;
        }

        return $out;
    }
}
