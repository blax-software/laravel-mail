<?php

declare(strict_types=1);

namespace Blax\Mail\Queries;

use Blax\Mail\Models\MailMessage;
use Illuminate\Support\Collection;

/**
 * Return every message in the same thread as the given row,
 * chronologically ascending. Resolution:
 *
 *  - If the row has a `thread_root_id`, every message with that same
 *    root id IS the thread. One indexed lookup, no recursive walking.
 *  - If the row has no root id, it IS the root — fetch all rows whose
 *    `thread_root_id` matches the given row's id, plus the row itself.
 *
 * Includes the input row in the result (it's part of its own thread)
 * so consumers can render the entire chain without an extra append.
 */
class GetThreadQuery
{
    public function forMessage(MailMessage $message): static
    {
        $clone = clone $this;
        $clone->message = $message;

        return $clone;
    }

    protected MailMessage $message;

    public function execute(): Collection
    {
        $message = $this->message;
        $rootId = $message->thread_root_id ?? $message->id;

        return MailMessage::query()
            ->with(['mailbox', 'recipients', 'attachments'])
            ->where(function ($q) use ($rootId) {
                $q->where('id', $rootId)
                    ->orWhere('thread_root_id', $rootId);
            })
            ->orderBy('created_at')
            ->get();
    }
}
