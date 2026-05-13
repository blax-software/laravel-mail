<?php

declare(strict_types=1);

namespace Blax\Mail\Contracts;

use Blax\Mail\DTOs\OutboundMail;
use Blax\Mail\Models\MailMessage;

/**
 * Public surface of the outbound send pipeline. Consumers inject this
 * interface and call `dispatch()` — they never touch `Mail::send()`
 * or `SendMailJob` directly, so the implementation can swap (e.g. a
 * fake in tests) without per-call-site work.
 *
 * Returns the persisted `MailMessage` row immediately. The actual SMTP
 * send happens asynchronously via the queue — the row starts in
 * `MailStatus::Queued` and progresses as the job runs.
 */
interface Dispatcher
{
    /**
     * Persist the outbound row, enqueue the send job, and return the
     * row. The caller can subscribe to `OutboundMailSent` /
     * `OutboundMailFailed` to react to the final state.
     */
    public function dispatch(OutboundMail $mail): MailMessage;
}
