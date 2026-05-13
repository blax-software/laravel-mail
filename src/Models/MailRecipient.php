<?php

declare(strict_types=1);

namespace Blax\Mail\Models;

use Blax\Mail\Enums\RecipientKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One (message, address, kind) triple, plus per-recipient lifecycle.
 *
 * The model is the source of truth for "did THIS recipient open the
 * mail?" — the JSON snapshot on `MailMessage.to/cc/bcc` is a cheap
 * denormalization for list rendering, not a place to record events.
 *
 * Addresses are lowercased before write so the unique index on
 * (message_id, kind, address) works without a CITEXT column or an
 * expression index. The original casing is preserved in `meta.raw`
 * for display when the header had mixed case.
 */
class MailRecipient extends Model
{
    use HasUuids;

    protected $table = 'mail_recipients';

    protected $fillable = [
        'mail_message_id',
        'kind',
        'address',
        'name',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'bounced_at',
        'bounce_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RecipientKind::class,
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'bounced_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MailEvent::class, 'recipient_id')
            ->orderBy('occurred_at');
    }

    /**
     * Normalize the address: trim + lowercase, preserve the raw value
     * in `meta.raw` for display. Call from save() in the dispatcher /
     * poller — not in a model `creating` hook, because some test
     * fixtures intentionally bypass normalization.
     */
    public static function normalizeAddress(string $address): string
    {
        return mb_strtolower(trim($address));
    }
}
