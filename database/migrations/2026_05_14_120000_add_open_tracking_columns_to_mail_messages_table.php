<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized open-tracking columns on `mail_messages`.
 *
 * The audit log (`mail_events`) stays the source of truth for every
 * individual open event — these three columns are a read-side
 * optimization so list / dashboard / "did the recipient open this?"
 * queries don't need to join `mail_events` or run a per-row
 * `whereHas('events', …)`.
 *
 *   - `first_opened_at` — when the recipient first opened the mail.
 *     Most useful column for UI: "Geöffnet am 13.05.2026".
 *   - `last_opened_at`  — the most recent open. Lets dashboards spot
 *     "still being read after weeks" vs "opened once and forgotten".
 *   - `open_count`      — how many tracking pixel hits we recorded.
 *     Cap noise from mail-client preview-pane re-renders by reading
 *     `mail_events` if you need de-duplicated stats.
 *
 * All three are nullable + default 0 so existing rows pre-migration
 * read as "never opened" without backfill — which is consistent with
 * the actual state (no tracking pixel was active for those rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->timestamp('first_opened_at')->nullable()->after('read_at');
            $table->timestamp('last_opened_at')->nullable()->after('first_opened_at');
            $table->unsignedInteger('open_count')->default(0)->after('last_opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn(['first_opened_at', 'last_opened_at', 'open_count']);
        });
    }
};
