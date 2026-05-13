<?php

declare(strict_types=1);

namespace Blax\Mail\Models;

use Blax\Mail\Enums\MailDirection;
use Blax\Mail\Enums\MailStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Canonical row for every mail the system has touched.
 *
 * Outbound rows are persisted by `MailDispatcher` immediately (status
 * `queued`) and progress through the lifecycle as transport events
 * fire. Inbound rows are persisted by `ImapPoller` once per IMAP UID
 * we haven't seen before.
 *
 * The model exposes typed accessors for `direction` and `status` so
 * callers never compare against raw strings. Threading helpers
 * (`parent()`, `thread()`) round-trip via `message_id` / `in_reply_to`
 * so they work even after a soft-delete wipes the original row.
 */
class MailMessage extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'mail_messages';

    protected $fillable = [
        'mailbox_id',
        'direction',
        'status',
        'message_id',
        'in_reply_to',
        'references',
        'thread_root_id',
        'subject',
        'body_text',
        'body_html',
        'raw_headers',
        'from_address',
        'from_name',
        'to',
        'cc',
        'bcc',
        'queued_at',
        'sent_at',
        'received_at',
        'read_at',
        // Denormalized open-tracking counters. The audit log
        // (`mail_events`) is still the source of truth for every
        // individual hit; these three columns let `RelatedMailList` /
        // dashboards / queries answer "did the recipient open it?"
        // with a single column read + no `mail_events` join.
        'first_opened_at',
        'last_opened_at',
        'open_count',
        'attempts',
        'last_error',
        'tracking_token',
        'subject_type',
        'subject_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MailDirection::class,
            'status' => MailStatus::class,
            'raw_headers' => 'array',
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'read_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'open_count' => 'integer',
            'attempts' => 'integer',
            'meta' => 'array',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MailRecipient::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MailEvent::class)->orderBy('occurred_at');
    }

    /**
     * The first message in this row's thread. Populated by the poller
     * via auto-threading; null when the row IS the root (or threading
     * is disabled). Returns the row itself when the FK points back at
     * `$this->id` — guards against the `BelongsTo` returning the same
     * object as `->thread` in self-referencing rows.
     */
    public function threadRoot(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'thread_root_id');
    }

    /**
     * Polymorphic escape hatch for consumers without M:N subject linkage.
     * Apps with richer business logic (e.g. spedition's `mail_subjects`
     * pivot) leave this empty and use their own relation. Returns null
     * when no subject is bound.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }

    // ── Threading helpers ────────────────────────────────────────────

    /**
     * The outbound mail this inbound row replies to, or null when the
     * In-Reply-To header doesn't match anything we've sent. Looks up
     * by `message_id` (RFC 5322) — the threading watermark every mail
     * client speaks.
     */
    public function parent(): ?MailMessage
    {
        if (! $this->in_reply_to) {
            return null;
        }

        return static::query()
            ->where('message_id', $this->in_reply_to)
            ->first();
    }

    /**
     * Every message in this row's thread, ordered chronologically.
     * Includes `$this`. Resolves via `thread_root_id` when present,
     * otherwise treats `$this` as the root.
     */
    public function thread()
    {
        $rootId = $this->thread_root_id ?? $this->id;

        return static::query()
            ->where(fn ($q) => $q
                ->where('id', $rootId)
                ->orWhere('thread_root_id', $rootId))
            ->orderBy('created_at')
            ->get();
    }

    // ── Status convenience ───────────────────────────────────────────

    public function isOutbound(): bool
    {
        return $this->direction === MailDirection::Outbound;
    }

    public function isInbound(): bool
    {
        return $this->direction === MailDirection::Inbound;
    }

    public function markRead(\DateTimeInterface $at = null): void
    {
        $this->forceFill([
            'status' => MailStatus::Read,
            'read_at' => $at ?? now(),
        ])->save();
    }
}
