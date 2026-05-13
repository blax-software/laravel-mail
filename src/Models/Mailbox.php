<?php

declare(strict_types=1);

namespace Blax\Mail\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One sending/receiving identity for the host application.
 *
 * Persists both SMTP (outbound) and IMAP (inbound) credentials in a
 * single row, gated by `enabled`. Mailboxes without IMAP credentials
 * are send-only; mailboxes without SMTP credentials are receive-only
 * — both are valid configurations.
 *
 * Credentials are stored as ciphertext via the `encrypted` cast. Never
 * inspect or mutate `smtp_password` / `imap_password` directly without
 * going through Eloquent — touching the raw DB column writes plaintext.
 *
 * The host application creates / edits mailboxes through whatever UI
 * it prefers; the package itself ships no admin UI. See the README's
 * "Adding a mailbox" section for the canonical Eloquent example.
 */
class Mailbox extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'mailboxes';

    protected $fillable = [
        'name',
        'email',
        'from_name',
        'reply_to',

        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',

        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'imap_folder',
        'poll_interval_minutes',

        'enabled',
        'last_polled_at',
        'last_error',

        'meta',
    ];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
            'imap_port' => 'integer',
            'imap_password' => 'encrypted',
            'poll_interval_minutes' => 'integer',
            'enabled' => 'boolean',
            'last_polled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** Every message routed through (sent from or received into) this mailbox. */
    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class, 'mailbox_id');
    }

    /** True when the row carries enough SMTP info to dispatch a mail. */
    public function canSend(): bool
    {
        return $this->enabled
            && filled($this->smtp_host)
            && filled($this->smtp_port)
            && filled($this->smtp_username);
    }

    /** True when the row carries enough IMAP info for the poller to log in. */
    public function canReceive(): bool
    {
        return $this->enabled
            && filled($this->imap_host)
            && filled($this->imap_port)
            && filled($this->imap_username);
    }

    /**
     * Mark a poll attempt as having succeeded. Resets `last_error` so a
     * single transient failure doesn't stick on the row forever.
     */
    public function markPolled(\DateTimeInterface $at): void
    {
        $this->forceFill([
            'last_polled_at' => $at,
            'last_error' => null,
        ])->save();
    }

    /**
     * Record a poll failure. Doesn't advance `last_polled_at`, so the
     * next poll re-attempts the same range.
     */
    public function markPollFailed(string $error): void
    {
        $this->forceFill(['last_error' => $error])->save();
    }
}
