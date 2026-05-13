<?php

declare(strict_types=1);

namespace Blax\Mail\Queries;

use Blax\Mail\Models\MailMessage;

/**
 * Look up a `MailMessage` by RFC 5322 `Message-ID`. The threading
 * lookup hot path — runs once per inbound mail to find its outbound
 * parent.
 *
 * Accepts both bracketed (`<id@host>`) and unbracketed (`id@host`)
 * input — header values come in both shapes depending on the mail
 * client.
 *
 * Separate from `ListMessagesQuery` so the threading code path is
 * one clear class without filter combinatorics.
 */
class FindMessageByMessageIdQuery
{
    public function execute(string $messageId): ?MailMessage
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return null;
        }

        // Bracket-normalize for the equality lookup — every value we
        // persist in `mail_messages.message_id` already includes the
        // surrounding `<>`, so unbracketed inputs would silently miss.
        if ($messageId[0] !== '<') {
            $messageId = '<'.trim($messageId, '<>').'>';
        }

        return MailMessage::query()
            ->where('message_id', $messageId)
            ->first();
    }
}
