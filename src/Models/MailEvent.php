<?php

declare(strict_types=1);

namespace Blax\Mail\Models;

use Blax\Mail\Enums\MailEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit fact about a `MailMessage` or one of its recipients.
 *
 * Never mutate a `MailEvent` — append a new row with the corrected
 * detail instead. The pair (mail_message_id, occurred_at) is the
 * canonical timeline; `recipient_id` is nullable for events that
 * concern the message as a whole (queued / sent / failed) vs. ones
 * that target a single addressee (delivered / opened / clicked /
 * bounced).
 *
 * `meta` is intentionally schemaless — open-tracking events store
 * `{user_agent, ip}`, clicks add `{url}`, transport failures store
 * `{error_class, error_message, smtp_code}`. Query via `meta->key`
 * only when you control the producers.
 */
class MailEvent extends Model
{
    use HasUuids;

    protected $table = 'mail_events';

    protected $fillable = [
        'mail_message_id',
        'recipient_id',
        'type',
        'occurred_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => MailEventType::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MailRecipient::class, 'recipient_id');
    }

    /** Convenience factory — append a new event without remembering to
     *  set `occurred_at` defaults. Use this everywhere a transport /
     *  tracking callback wants to log; the canonical timeline depends
     *  on `occurred_at` being filled. */
    public static function record(
        string $messageId,
        MailEventType $type,
        ?string $recipientId = null,
        array $meta = [],
        ?\DateTimeInterface $at = null,
    ): self {
        return static::create([
            'mail_message_id' => $messageId,
            'recipient_id' => $recipientId,
            'type' => $type,
            'occurred_at' => $at ?? now(),
            'meta' => $meta,
        ]);
    }
}
