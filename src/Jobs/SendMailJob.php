<?php

declare(strict_types=1);

namespace Blax\Mail\Jobs;

use Blax\Mail\DTOs\OutboundMail;
use Blax\Mail\Enums\MailEventType;
use Blax\Mail\Enums\MailStatus;
use Blax\Mail\Events\OutboundMailFailed;
use Blax\Mail\Events\OutboundMailSent;
use Blax\Mail\Models\MailEvent;
use Blax\Mail\Models\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Queued send for one outbound `MailMessage`.
 *
 * Builds a transient Laravel mailer config from the row's Mailbox
 * credentials so every Mailbox can use its own SMTP server without
 * polluting the host app's `config/mail.php`. The mailer name is
 * derived from the mailbox id (`blax-mail-<uuid>`) so concurrent
 * sends from different mailboxes don't fight over the same config
 * key.
 *
 * Retry strategy (3 tries, 60 / 300 sec backoff) matches learn-atc's
 * pattern — transient SMTP errors usually clear within a few minutes,
 * and three attempts is enough to ride out brief outages without
 * spamming a permanently-broken transport. The job marks the row
 * Failed via the `failed()` hook after the last attempt.
 */
class SendMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(
        public readonly string $mailMessageId,
        public readonly OutboundMail $outbound,
    ) {
    }

    public function handle(): void
    {
        $message = MailMessage::find($this->mailMessageId);
        if (! $message || $message->status === MailStatus::Sent || $message->status === MailStatus::Delivered) {
            // Re-run of a job whose row was already sent (manual queue
            // replay, etc.) — bail without double-sending.
            return;
        }

        $message->forceFill([
            'status' => MailStatus::Sending,
            'attempts' => $message->attempts + 1,
        ])->save();

        try {
            $this->ship($message);
        } catch (Throwable $e) {
            // Persist the error message so the operator can see why
            // the row stayed in `sending` between retries. The status
            // only flips to `Failed` from the `failed()` hook after the
            // final retry — keep `Sending` here so a retry can still
            // pick the row up.
            $message->forceFill(['last_error' => $e->getMessage()])->save();
            throw $e;
        }
    }

    /**
     * Actually push the mail through SMTP. Wires a per-Mailbox runtime
     * mailer + uses Laravel's `Mail::html()` so consumers get all the
     * Symfony Mailer events + transport machinery for free.
     */
    protected function ship(MailMessage $message): void
    {
        $mailbox = $message->mailbox;
        if (! $mailbox) {
            throw new \RuntimeException("MailMessage {$message->id} has no mailbox attached.");
        }

        $mailerName = $this->configureMailer($mailbox);

        $body = $message->body_html ?? '';
        $text = $message->body_text;
        $messageId = trim((string) $message->message_id, '<>');
        $extraHeaders = (array) ($message->meta['headers'] ?? []);
        $replyTo = $message->meta['reply_to'] ?? $mailbox->reply_to;

        $sender = Mail::mailer($mailerName);

        $sender->html($body, function (Message $mail) use ($message, $mailbox, $text, $messageId, $extraHeaders, $replyTo) {
            $mail->from($mailbox->email, $mailbox->from_name ?? config('blax-mail.outbound.default_from_name'));
            if ($replyTo) {
                $mail->replyTo($replyTo);
            }
            $mail->subject($message->subject);

            foreach ((array) $message->to as $to) {
                $mail->to($to);
            }
            foreach ((array) $message->cc as $cc) {
                $mail->cc($cc);
            }
            foreach ((array) $message->bcc as $bcc) {
                $mail->bcc($bcc);
            }

            $email = $mail->getSymfonyMessage();

            // Plain-text alternative (for clients that don't render HTML).
            // The Illuminate\Mail\Message `__call` proxy forwards `text()`
            // straight to Symfony's `Email::text($body, ?$charset)`, so
            // passing a view-style ('view-name', [data]) tuple raises a
            // TypeError ("Argument #2 must be of type string, array
            // given"). Reach for the Symfony email directly with the
            // plain-text body — the html() call above already set the
            // text/html alternative.
            if ($text) {
                $email->text($text);
            }

            // Anchor threading by injecting the canonical Message-ID
            // we generated at dispatch — the recipient's reply will
            // carry it as `In-Reply-To`, which the poller uses to link
            // inbound to outbound.
            $email->getHeaders()->remove('Message-ID');
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);

            // RFC 2369 `List-Unsubscribe` — Gmail bulk sender
            // requirements 2024+ flag mail without it.
            if (config('blax-mail.outbound.list_unsubscribe', true)) {
                $unsub = '<mailto:'.$mailbox->email.'?subject=unsubscribe>';
                $email->getHeaders()->addTextHeader('List-Unsubscribe', $unsub);
            }

            foreach ($extraHeaders as $name => $value) {
                $email->getHeaders()->addTextHeader((string) $name, (string) $value);
            }
        });

        $message->forceFill([
            'status' => MailStatus::Sent,
            'sent_at' => now(),
            'last_error' => null,
        ])->save();

        MailEvent::record($message->id, MailEventType::Sent);
        OutboundMailSent::dispatch($message);
    }

    /**
     * Late-bind a Laravel mailer carrying THIS mailbox's SMTP creds.
     * Returns the mailer name so the caller can resolve it via the
     * Mail facade. The name is mailbox-specific so concurrent jobs
     * for different mailboxes don't read each other's config.
     */
    protected function configureMailer($mailbox): string
    {
        $name = 'blax-mail-'.$mailbox->id;

        config([
            "mail.mailers.{$name}" => array_filter([
                'transport' => 'smtp',
                'host' => $mailbox->smtp_host,
                'port' => $mailbox->smtp_port,
                'encryption' => $mailbox->smtp_encryption,
                'username' => $mailbox->smtp_username,
                // Decrypted at access time by the model's `encrypted`
                // cast — we hand the plaintext to Laravel for one send
                // and it never leaves the config bag.
                'password' => $mailbox->smtp_password,
                'timeout' => 30,
                'auth_mode' => 'login',
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        // Force Laravel to re-resolve the mailer next time it's
        // requested — otherwise a re-used worker would keep the first
        // mailbox's transport for every subsequent send.
        app('mail.manager')->forgetMailers();

        return $name;
    }

    /**
     * Hook called by Laravel after retries are exhausted. Persists
     * the terminal failure on the row + fires the lifecycle event so
     * listeners can surface the failure to operators.
     */
    public function failed(Throwable $e): void
    {
        $message = MailMessage::find($this->mailMessageId);
        if (! $message) {
            return;
        }

        $message->forceFill([
            'status' => MailStatus::Failed,
            'last_error' => $e->getMessage(),
        ])->save();

        MailEvent::record($message->id, MailEventType::Failed, meta: [
            'error_class' => get_class($e),
            'error_message' => $e->getMessage(),
        ]);

        OutboundMailFailed::dispatch($message, $e);
    }
}
