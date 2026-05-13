<?php

declare(strict_types=1);

namespace Blax\Mail\Commands;

use Blax\Mail\Models\MailMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purge soft-deleted mail rows past the retention window.
 *
 * Scheduled invocation:
 *
 *     $schedule->command('blax-mail:cleanup')->dailyAt('03:30');
 *
 * The command never touches active rows — only ones already
 * soft-deleted (`deleted_at IS NOT NULL`) and older than
 * `retention.purge_days`. Set the config to `0` to disable purging
 * entirely (the command exits clean with a "retention disabled" note).
 *
 * Hard-deleting cascades to recipients/attachments/events via the
 * migrations' FK constraints. Attachment bytes on disk are deleted
 * here before the row goes — the cascade can't reach the filesystem.
 */
class CleanupCommand extends Command
{
    protected $signature = 'blax-mail:cleanup
        {--dry : Print what would be deleted without touching the database.}';

    protected $description = 'Hard-delete soft-deleted mail rows older than the configured retention window.';

    public function handle(): int
    {
        $days = (int) config('blax-mail.retention.purge_days', 365);
        if ($days <= 0) {
            $this->line('Retention purging disabled (blax-mail.retention.purge_days = 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $stale = MailMessage::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->with('attachments')
            ->get();

        if ($stale->isEmpty()) {
            $this->line("Nothing to purge — no soft-deleted rows older than {$cutoff->toIso8601String()}.");

            return self::SUCCESS;
        }

        $this->info("Found {$stale->count()} stale row(s) to purge.");

        if ($this->option('dry')) {
            foreach ($stale as $row) {
                $this->line("· {$row->id} (deleted {$row->deleted_at?->toIso8601String()})");
            }
            $this->newLine();
            $this->comment('Dry run — no rows were touched.');

            return self::SUCCESS;
        }

        $bytesFreed = 0;
        foreach ($stale as $row) {
            foreach ($row->attachments as $attachment) {
                if ($attachment->storage_disk && $attachment->storage_path) {
                    Storage::disk($attachment->storage_disk)
                        ->delete($attachment->storage_path);
                    $bytesFreed += (int) $attachment->size_bytes;
                }
            }
            $row->forceDelete();
        }

        $this->info("Purged {$stale->count()} row(s); freed ".number_format($bytesFreed)." attachment bytes.");

        return self::SUCCESS;
    }
}
