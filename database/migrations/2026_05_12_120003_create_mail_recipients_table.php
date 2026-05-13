<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mail_recipients` — one row per (message, address, kind) triple.
 *
 * The denormalized `to`/`cc`/`bcc` JSON columns on `mail_messages`
 * exist for cheap list rendering, but per-recipient lifecycle (when
 * THIS recipient opened the mail, clicked a link, bounced, …) lives
 * here. Splitting into a pivot avoids JSON mutation race conditions
 * when two open-pixel hits land at the same time for different
 * recipients of the same broadcast.
 *
 * `kind` is the `RecipientKind` enum (`to`/`cc`/`bcc`). The denormalized
 * snapshot on `mail_messages` is rebuilt from this table whenever the
 * recipients change (which on outbound is "never" after dispatch; on
 * inbound the snapshot is written once on persist and never touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('mail_message_id')
                ->constrained('mail_messages')
                ->cascadeOnDelete();

            $table->string('kind', 3);  // RecipientKind: to | cc | bcc

            $table->string('address');
            $table->string('name')->nullable();

            // Per-recipient lifecycle. Symfony Mailer fires events that
            // map onto these columns; the tracking pixel + click
            // redirect fill in `opened_at` / `clicked_at`.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->text('bounce_reason')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // Lookup paths:
            //  - "all recipients of this message" — already covered by
            //    the FK index.
            //  - "every message addressed to this email" — drives the
            //    routing engine (find the customer whose email this is).
            $table->index('address');
            // Defensive uniqueness so a buggy poller doesn't insert the
            // same (message, address, kind) twice on retry. Address is
            // case-insensitive in RFC, but we keep the column verbatim
            // and lowercase before write — the unique index then
            // enforces practical uniqueness without an expression index.
            $table->unique(['mail_message_id', 'kind', 'address'], 'mail_recipients_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_recipients');
    }
};
