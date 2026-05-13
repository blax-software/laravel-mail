<?php

declare(strict_types=1);

namespace Blax\Mail\Contracts;

use Blax\Mail\Models\Mailbox;

/**
 * Public surface of the inbound IMAP fetch pipeline. Backed by
 * `Services\ImapPoller` using `directorytree/imapengine`; the contract
 * exists so tests + alternative implementations (per-provider APIs,
 * mock pollers) can substitute.
 */
interface Poller
{
    /**
     * Fetch new messages from the mailbox's configured IMAP folder
     * (typically INBOX). Persists each new message as an inbound
     * `MailMessage` row, runs threading, and fires `InboundMailReceived`
     * per row.
     *
     * Idempotent: re-running on the same mailbox skips messages whose
     * `Message-ID` is already in the database. Returns the number of
     * newly-persisted rows.
     */
    public function poll(Mailbox $mailbox): int;
}
