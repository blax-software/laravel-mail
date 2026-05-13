<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mail_messages` — canonical log of every outbound + inbound mail.
 *
 * Outbound rows are created by `MailDispatcher` at queue time and
 * progress through the status enum (queued → sending → sent → …) as
 * Symfony Mailer events fire. Inbound rows are created by `ImapPoller`
 * after each successful fetch.
 *
 * `message_id` is the RFC 5322 Message-ID header — globally unique
 * (within reason) and what threading is built on. Indexed so the
 * threading lookup ("does an outbound row exist for this In-Reply-To?")
 * stays O(1).
 *
 * `thread_root_id` is a self-reference to the first message in a
 * thread; populated by the auto-threading code in the poller. Lets
 * `GetThreadQuery` resolve a thread without recursively walking the
 * `in_reply_to` chain.
 *
 * `subject_type` / `subject_id` is a polymorphic escape hatch — the
 * package never *requires* it, but consumers that want to file each
 * mail under one of their own entities can write to it from the
 * `InboundMailReceived` listener. Apps with M:N subject linkage (e.g.
 * spedition) ignore these columns and use their own pivot.
 *
 * `tracking_token` powers the open-pixel + click-redirect endpoints
 * without exposing the message's primary key in the URL. Unique so
 * the controller can `where('tracking_token', $token)->first()` cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('mailbox_id')->nullable()
                ->constrained('mailboxes')
                ->nullOnDelete();

            // Direction + lifecycle
            $table->string('direction', 10);  // MailDirection enum
            $table->string('status', 20);     // MailStatus enum

            // Threading (RFC 5322).
            //
            // The RFC's 998-char ceiling is the *header line* limit, not
            // the Message-ID length — real-world IDs are 30–200 chars, so
            // varchar(255) is comfortable headroom and stays inside
            // MySQL's 3072-byte per-index limit under utf8mb4 (255*4 =
            // 1020 bytes). Going wider trips the index creation with
            // "Specified key was too long".
            $table->string('message_id', 255)->nullable();
            $table->string('in_reply_to', 255)->nullable();
            // `references` can carry every Message-ID up the chain;
            // stored as longText because long-running threads can blow
            // past the typical varchar length.
            $table->longText('references')->nullable();
            $table->foreignUuid('thread_root_id')->nullable()
                ->constrained('mail_messages')
                ->nullOnDelete();

            // Content
            $table->string('subject')->default('');
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('raw_headers')->nullable();

            // From (denormalized — the recipient pivot handles the rest)
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            // Recipients snapshot (the source of truth for per-recipient
            // events lives in `mail_recipients`; this JSON is for cheap
            // list rendering without joining).
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();

            // Lifecycle timestamps
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Retry tracking on the outbound side
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // Tracking pixel / click rewrite token
            $table->string('tracking_token', 64)->nullable();

            // Polymorphic escape hatch for consumers without M:N needs
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Threading lookup hot path: "is there an outbound row with
            // this Message-ID?" — runs on every inbound mail.
            $table->index('message_id');
            $table->index('in_reply_to');
            $table->index('thread_root_id');
            // Reverse-lookup for tracking endpoints.
            $table->unique('tracking_token');
            // Inbox queries by mailbox + direction + recency.
            $table->index(['mailbox_id', 'direction', 'received_at']);
            $table->index(['mailbox_id', 'direction', 'sent_at']);
            // Polymorphic subject lookup.
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
