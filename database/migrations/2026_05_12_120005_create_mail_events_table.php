<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mail_events` — append-only audit log of everything that happened to
 * a `MailMessage` after persistence.
 *
 * Why a dedicated table and not just status mutations on `MailMessage`?
 *
 *  - Bounces can happen twice (provider re-tries delivery). The
 *    `MailMessage.status` column reflects the *current* state, but the
 *    history needs to be queryable for support / debugging.
 *  - Per-recipient events (Recipient A opened the mail at T1, Recipient
 *    B at T2) don't fit on the single-row `MailMessage`. They live here.
 *  - Click tracking generates many rows per message (one per URL hit).
 *    Mutating `MailMessage` for each would create write amplification.
 *
 * `recipient_id` is nullable: message-level events (e.g. "queued",
 * "failed") carry no recipient; per-recipient events ("opened",
 * "clicked", "bounced") set the column.
 *
 * `meta` carries event-specific detail: user-agent + IP for Opened /
 * Clicked, URL for Clicked, transport error for Failed, etc. Search
 * the JSON only via the consuming app's analytics layer — there's no
 * structured index here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('mail_message_id')
                ->constrained('mail_messages')
                ->cascadeOnDelete();
            $table->foreignUuid('recipient_id')->nullable()
                ->constrained('mail_recipients')
                ->nullOnDelete();

            $table->string('type', 20);  // MailEventType enum
            $table->timestamp('occurred_at');

            $table->json('meta')->nullable();
            $table->timestamps();

            // Timeline queries: "every event on this message, in order".
            $table->index(['mail_message_id', 'occurred_at']);
            // Type filters: "every Bounced event in the last 24h".
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_events');
    }
};
