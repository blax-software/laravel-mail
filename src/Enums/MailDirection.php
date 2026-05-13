<?php

declare(strict_types=1);

namespace Blax\Mail\Enums;

/**
 * Which way the mail is moving.
 *
 *  - `outbound` — composed by the host app, dispatched via SMTP. The
 *    `MailDispatcher` writes this on every send.
 *  - `inbound`  — fetched from an IMAP folder. The poller writes this
 *    on every persisted message.
 *
 * The package never persists a row without a direction — every code
 * path that creates a `MailMessage` must set this explicitly. There is
 * no "unknown" or "draft" value; draft state belongs in the consuming
 * app's domain model, not in the canonical mail log.
 */
enum MailDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
