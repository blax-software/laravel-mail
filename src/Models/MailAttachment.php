<?php

declare(strict_types=1);

namespace Blax\Mail\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One file attached to (or inline-referenced from) a `MailMessage`.
 *
 * The package keeps the row's metadata in the DB and the byte payload
 * on a configured filesystem disk (`storage_disk` + `storage_path`).
 * When `blax-mail.imap.attachments.download = false`, only the
 * metadata is persisted — the bytes stay on the IMAP server and are
 * fetched on demand. In that mode `storage_disk` is null and
 * `fetch()` re-opens the IMAP connection.
 *
 * `inline = true` marks attachments referenced by `cid:` URLs inside
 * `body_html`. List views typically filter to `inline = false` so the
 * decorative logos / signatures don't clutter the attachment column.
 */
class MailAttachment extends Model
{
    use HasUuids;

    protected $table = 'mail_attachments';

    protected $fillable = [
        'mail_message_id',
        'filename',
        'mime_type',
        'size_bytes',
        'storage_disk',
        'storage_path',
        'content_id',
        'inline',
        'checksum',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'inline' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    /**
     * Stream the attachment's bytes. Returns null when the row was
     * persisted with `download = false` AND the IMAP server has since
     * deleted the message — callers should treat null as "attachment
     * unavailable" and let the user re-fetch from the source.
     */
    public function bytes(): ?string
    {
        if (! $this->storage_disk || ! $this->storage_path) {
            return null;
        }

        $disk = Storage::disk($this->storage_disk);

        return $disk->exists($this->storage_path)
            ? $disk->get($this->storage_path)
            : null;
    }

    /** True when the attachment is stored locally (vs. metadata-only). */
    public function isAvailable(): bool
    {
        if (! $this->storage_disk || ! $this->storage_path) {
            return false;
        }

        return Storage::disk($this->storage_disk)->exists($this->storage_path);
    }
}
