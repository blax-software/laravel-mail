<?php

declare(strict_types=1);

namespace Blax\Mail\Services;

use Blax\Mail\Enums\MailEventType;
use Blax\Mail\Models\MailEvent;
use Blax\Mail\Models\MailMessage;

/**
 * Threading helper — links an inbound `MailMessage` to its parent in a
 * conversation chain via RFC 5322 `In-Reply-To` / `References` headers.
 *
 * Resolution order:
 *
 *  1. If `in_reply_to` matches a known `message_id`, that's the parent.
 *  2. Else walk `references` (most recent → oldest) and use the first
 *     match. (Many mail clients copy the full chain into References.)
 *  3. Else leave the row unthreaded — `thread_root_id` stays null and
 *     the row IS its own root.
 *
 * After resolving the parent, the row inherits the parent's
 * `thread_root_id` (falling back to the parent's own id when the
 * parent is itself a root). This means every row in a thread shares
 * one `thread_root_id`, so `MailMessage::thread()` resolves with one
 * cheap indexed lookup.
 *
 * Threading is best-effort — losing a thread link is never fatal,
 * just suboptimal display. The threader logs a `Threaded` event when
 * it succeeds so the audit log can show "this reply was auto-linked".
 */
class MessageThreader
{
    /**
     * Attempt to thread the given inbound row. Mutates `thread_root_id`
     * on the row when a parent is found. Returns the resolved parent
     * (or null when standalone).
     */
    public function thread(MailMessage $message): ?MailMessage
    {
        if (! config('blax-mail.imap.auto_thread', true)) {
            return null;
        }

        $parent = $this->findParent($message);
        if (! $parent) {
            return null;
        }

        // Every row in a thread carries the SAME `thread_root_id` — the
        // id of the first message. So a reply inherits whatever root
        // its parent already has; the parent's own id only kicks in
        // when the parent itself is the root.
        $rootId = $parent->thread_root_id ?? $parent->id;

        $message->forceFill(['thread_root_id' => $rootId])->save();

        MailEvent::record($message->id, MailEventType::Threaded, meta: [
            'parent_id' => (string) $parent->id,
            'thread_root_id' => (string) $rootId,
        ]);

        return $parent;
    }

    /**
     * Look up a parent for an inbound row. Tries `in_reply_to` first
     * (the strict 1:1 link), then walks `references` newest-to-oldest
     * for the first match. Returns null when nothing in the chain has
     * a row in this database.
     */
    protected function findParent(MailMessage $message): ?MailMessage
    {
        if ($message->in_reply_to) {
            $parent = MailMessage::query()
                ->where('message_id', $message->in_reply_to)
                ->first();
            if ($parent) {
                return $parent;
            }
        }

        if ($message->references) {
            // `References` is whitespace-separated; recent clients put
            // the immediate parent last, the original at the start.
            // Walking in reverse picks the closest known ancestor.
            $refs = preg_split('/\s+/', trim($message->references)) ?: [];
            foreach (array_reverse($refs) as $ref) {
                $ref = trim($ref);
                if ($ref === '') {
                    continue;
                }
                $parent = MailMessage::query()
                    ->where('message_id', $ref)
                    ->first();
                if ($parent) {
                    return $parent;
                }
            }
        }

        return null;
    }
}
