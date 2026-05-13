<?php

declare(strict_types=1);

namespace Blax\Mail\Commands;

use Blax\Mail\Contracts\Poller;
use Blax\Mail\Models\Mailbox;
use Illuminate\Console\Command;

/**
 * Poll IMAP folders for new inbound messages.
 *
 * The host app schedules this from its console kernel:
 *
 *     $schedule->command('blax-mail:poll')
 *         ->everyFiveMinutes()
 *         ->withoutOverlapping();
 *
 * Polling one specific mailbox by id is supported via the optional
 * argument — useful for manual catch-up after a credentials fix:
 *
 *     php artisan blax-mail:poll 019e1234-5678-9abc-def0-123456789abc
 *
 * Failures on individual mailboxes are logged but don't abort the
 * batch — the command always exits 0 unless something truly fatal
 * (no mailboxes configured, container resolution error) happens.
 */
class PollMailboxesCommand extends Command
{
    protected $signature = 'blax-mail:poll
        {mailbox? : Poll only this mailbox id (default: every enabled mailbox).}
        {--strict : Exit non-zero when any single mailbox errors.}';

    protected $description = 'Fetch new inbound messages from every enabled mailbox via IMAP.';

    public function handle(Poller $poller): int
    {
        $query = Mailbox::query()->where('enabled', true);
        $only = $this->argument('mailbox');
        if ($only) {
            $query->where('id', $only);
        }

        $mailboxes = $query->get();
        if ($mailboxes->isEmpty()) {
            $this->warn($only
                ? "No enabled mailbox with id '{$only}'."
                : 'No enabled mailboxes configured. Add one via the Mailbox model.');

            return self::SUCCESS;
        }

        $totalPersisted = 0;
        $errored = 0;
        foreach ($mailboxes as $mailbox) {
            if (! $mailbox->canReceive()) {
                $this->line("· {$mailbox->name} — receive disabled, skipping.");
                continue;
            }

            try {
                $count = $poller->poll($mailbox);
                $totalPersisted += $count;
                $this->info("✓ {$mailbox->name} — {$count} new message(s).");
            } catch (\Throwable $e) {
                $errored++;
                $this->error("✗ {$mailbox->name} — {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line("Persisted {$totalPersisted} new inbound message(s) across {$mailboxes->count()} mailbox(es); {$errored} error(s).");

        return $errored > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
