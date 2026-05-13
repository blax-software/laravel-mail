<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mailboxes` — per-mailbox SMTP + IMAP credentials.
 *
 * One row per identity the host app sends from / receives into. The
 * password columns are stored as ciphertext via the model's `encrypted`
 * cast — never write plaintext into these columns directly.
 *
 * `last_polled_at` is the watermark the poller advances after each
 * successful fetch; nulling it forces a full re-scan on the next poll.
 * `last_error` carries the most recent failure message so the admin UI
 * can surface "this mailbox needs attention" without a separate log
 * tail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identity
            $table->string('name');
            $table->string('email');
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();

            // SMTP (outbound)
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            // `tls` | `ssl` | null (none). Mailers map this to the
            // appropriate Symfony Mailer DSN scheme on dispatch.
            $table->string('smtp_encryption', 10)->nullable();
            $table->string('smtp_username')->nullable();
            // Encrypted via Eloquent cast — column is text, ciphertext
            // grows with content + IV + tag.
            $table->text('smtp_password')->nullable();

            // IMAP (inbound)
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_encryption', 10)->nullable();
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable();
            $table->string('imap_folder')->default('INBOX');
            // Poll interval is informational on the model — the actual
            // cadence comes from the host app's scheduler. Surfaced so
            // an admin UI can display "polls every N min" without
            // re-reading the schedule.
            $table->unsignedInteger('poll_interval_minutes')->default(5);

            // Operations
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Hot-path index: "active mailboxes the poller should walk".
            $table->index(['enabled', 'last_polled_at']);
            // Reverse-lookup: routing a received mail by its addressed
            // recipient ("which mailbox owns support@…") needs an index.
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
