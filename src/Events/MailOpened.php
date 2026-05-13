<?php

declare(strict_types=1);

namespace Blax\Mail\Events;

use Blax\Mail\Models\MailMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when the tracking pixel for a mail loads in a recipient's
 * inbox. The pixel endpoint dedupes within a short window so a single
 * preview-pane render doesn't flood listeners with events, but
 * multiple opens over hours/days will each fire one event so heatmap
 * analytics can reconstruct engagement timelines.
 *
 * `userAgent` + `ip` come from the HTTP request that loaded the pixel
 * — useful for distinguishing real human opens from mail-client
 * security scanners that prefetch all images.
 */
class MailOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MailMessage $message,
        public readonly ?string $userAgent = null,
        public readonly ?string $ip = null,
    ) {
    }
}
