<?php

declare(strict_types=1);

namespace Blax\Mail\Enums;

/**
 * Recipient slot on a `MailRecipient` row.
 *
 *  - `to`   — primary recipients, addressed in the visible `To:` header.
 *  - `cc`   — visible carbon-copy recipients.
 *  - `bcc`  — blind carbon-copy; never serialized into the outbound
 *             headers, but still persisted so the audit log + open
 *             tracking work the same as for `to`.
 *
 * The `MailMessage` row carries denormalized JSON columns for backward
 * compatibility with existing consumers, but per-recipient tracking
 * (delivered/opened/clicked timestamps) lives on the pivot — the JSON
 * is a snapshot, the pivot is the source of truth.
 */
enum RecipientKind: string
{
    case To = 'to';
    case Cc = 'cc';
    case Bcc = 'bcc';
}
