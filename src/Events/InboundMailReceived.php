<?php

declare(strict_types=1);

namespace Blax\Mail\Events;

use Blax\Mail\Models\MailMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires once per inbound mail, immediately after the poller persists
 * the row and links its thread parent (if any).
 *
 * Host applications subscribe to this event to do their own routing —
 * inspect the `From` address, the threading parent's subject_type, or
 * subject-line markers, and link the message to whatever domain entity
 * makes sense (an Auftrag, an Anfrage, a Customer's ticket).
 *
 * The event runs *after* persistence, so listeners can safely call
 * `$event->message->update(['subject_type' => …])` to file the row
 * under their own polymorphic link, or write to a per-app M:N pivot.
 *
 * Threaded? If the poller auto-linked this row to an outbound parent,
 * `$event->threadParent` carries the parent message — handy when the
 * routing inherits subjects from the outbound that started the thread.
 */
class InboundMailReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MailMessage $message,
        public readonly ?MailMessage $threadParent = null,
    ) {
    }
}
