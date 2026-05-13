<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mail_attachments` — file attachments on inbound + outbound messages.
 *
 * The package stores attachment metadata in this row and (when
 * `blax-mail.imap.attachments.download = true`) the byte payload on
 * the configured filesystem disk. `storage_disk` + `storage_path`
 * resolve to a Storage::disk call so consumers can move attachments
 * between disks without rewriting the binary.
 *
 * `content_id` carries the RFC 2392 `Content-ID` for inline images
 * (those referenced by `cid:` URLs inside `body_html`); when set, the
 * attachment is "inline" and should be rendered alongside the body
 * rather than as a download.
 *
 * `inline` is a denormalized flag derived from `content_id` for
 * cheap filtering — list views typically want "real" attachments only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('mail_message_id')
                ->constrained('mail_messages')
                ->cascadeOnDelete();

            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Storage. `null` when the package skipped the download
            // (config: imap.attachments.download = false) — in that
            // case the row exists for metadata only and `fetch()` on
            // the model goes back to IMAP to stream the bytes.
            $table->string('storage_disk')->nullable();
            $table->string('storage_path')->nullable();

            // Inline-rendered images
            $table->string('content_id')->nullable();
            $table->boolean('inline')->default(false);

            // SHA-256 of the bytes — lets the host app detect when an
            // inbound attachment is a re-send of an outbound one
            // (typical reply with quoted attachments).
            $table->string('checksum', 64)->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('content_id');
            $table->index('checksum');
            $table->index(['mail_message_id', 'inline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_attachments');
    }
};
