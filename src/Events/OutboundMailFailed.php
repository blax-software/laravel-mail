<?php

declare(strict_types=1);

namespace Blax\Mail\Events;

use Blax\Mail\Models\MailMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Fires when `SendMailJob` exhausts its retries (or hits an
 * unrecoverable transport error). The row's `status` is `Failed` and
 * `last_error` carries the exception message.
 *
 * The original throwable is included so listeners can choose to alert
 * on specific kinds of failures (authentication problems vs. quota
 * exceeded vs. invalid recipient) without re-parsing `last_error`.
 */
class OutboundMailFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MailMessage $message,
        public readonly Throwable $exception,
    ) {
    }
}
