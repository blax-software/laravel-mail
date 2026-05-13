<?php

declare(strict_types=1);

/**
 * Blax Mail package configuration.
 *
 * Every option is overridable via environment so a consuming app can
 * configure per-environment behaviour without forking the published
 * config. The keys are referenced throughout the package via
 * `config('blax-mail.…')` — change a name here and grep the package.
 */
return [
    /*
    |----------------------------------------------------------------------
    | Tracking
    |----------------------------------------------------------------------
    |
    | Open and click tracking are wired through signed tokens persisted on
    | each `MailMessage`. The tracking pixel + click redirect routes are
    | registered when `enabled = true`; disable globally per environment
    | (e.g. local dev) to avoid hitting the database from every email
    | preview render.
    |
    */
    'tracking' => [
        'enabled' => env('BLAX_MAIL_TRACKING', true),
        // Route prefix for the open-pixel and click-redirect endpoints.
        // Must NOT include leading or trailing slashes — Laravel adds
        // them. Change this if your app already owns `/blax-mail`.
        'route_prefix' => env('BLAX_MAIL_ROUTE_PREFIX', 'blax-mail/track'),
        // Tracking pixel dimensions in pixels. Keep at [1, 1] unless you
        // need a visible debug pixel for development.
        'pixel_dimensions' => [1, 1],
        // How long a tracking token keeps recording events. After this
        // window the pixel still returns 200 OK (so mail clients don't
        // mark the message as broken) but `MailEvent`s aren't logged.
        'token_ttl_days' => env('BLAX_MAIL_TOKEN_TTL_DAYS', 90),
        // Middleware applied to the tracking routes. By default no
        // auth — tracking pixels need to load from a customer's inbox.
        // Override for apps that gate everything behind auth.
        'middleware' => ['web'],
    ],

    /*
    |----------------------------------------------------------------------
    | IMAP polling
    |----------------------------------------------------------------------
    |
    | The poller fetches new messages from each enabled Mailbox's IMAP
    | folder and persists them as inbound `MailMessage` rows. Mailboxes
    | with `enabled = false` are skipped. Errors per-mailbox don't fail
    | the whole batch — they're logged and the poller moves on.
    |
    */
    'imap' => [
        'default_folder' => env('BLAX_MAIL_IMAP_FOLDER', 'INBOX'),
        // Max messages per poll, per mailbox. Bounds memory when
        // catching up on a long-untouched mailbox.
        'fetch_limit' => (int) env('BLAX_MAIL_IMAP_FETCH_LIMIT', 200),
        // Default interval between polls, in minutes. The actual cadence
        // is decided by the consuming app's scheduler — this value is
        // surfaced on the `Mailbox` model so admin UIs can show "polls
        // every N min" without re-reading the schedule.
        'default_interval_minutes' => (int) env('BLAX_MAIL_IMAP_INTERVAL', 5),
        // When true, inbound messages whose `In-Reply-To` matches an
        // existing outbound `message_id` are auto-linked into the same
        // thread. Disable for apps that prefer to do their own threading
        // via the `InboundMailReceived` event.
        'auto_thread' => env('BLAX_MAIL_AUTO_THREAD', true),
        // Attachment handling. When `download = true`, attachments are
        // streamed to the configured disk; when false the inbound row
        // is persisted with attachment metadata only (filename, size,
        // mime) and the body bytes stay on the IMAP server.
        'attachments' => [
            'download' => env('BLAX_MAIL_DOWNLOAD_ATTACHMENTS', true),
            'disk' => env('BLAX_MAIL_ATTACHMENT_DISK', 'local'),
            'path_prefix' => env('BLAX_MAIL_ATTACHMENT_PATH', 'blax-mail/attachments'),
            'max_bytes' => (int) env('BLAX_MAIL_ATTACHMENT_MAX_BYTES', 25 * 1024 * 1024),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Outbound dispatcher
    |----------------------------------------------------------------------
    |
    | The `MailDispatcher` service wires its own Symfony Mailer transport
    | per send so different mailboxes can use different SMTP servers
    | inside one Laravel app. Tracking pixels + click rewrites are
    | injected during dispatch when `tracking.enabled` is true.
    |
    */
    'outbound' => [
        // Default `From` name when a Mailbox has no `from_name` set.
        'default_from_name' => env('BLAX_MAIL_DEFAULT_FROM_NAME', config('app.name')),
        // Append a hidden List-Unsubscribe header to outbound mail.
        // Some providers (Gmail bulk sender requirements 2024+) require
        // this — keep on unless you have a reason.
        'list_unsubscribe' => env('BLAX_MAIL_LIST_UNSUBSCRIBE', true),
        // Wrap outbound links with a tracking redirect. Disable for
        // transactional types where click tracking is forbidden
        // (password-reset URLs, bounce-handler endpoints).
        'click_tracking' => env('BLAX_MAIL_CLICK_TRACKING', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Retention
    |----------------------------------------------------------------------
    |
    | The `blax-mail:cleanup` command hard-deletes soft-deleted messages
    | (and their attachments + events) once they exceed `purge_days`.
    | Set to 0 to retain forever. Open/click events on still-active
    | messages are never purged here — they're capped by `token_ttl_days`
    | under `tracking`.
    |
    */
    'retention' => [
        'purge_days' => (int) env('BLAX_MAIL_PURGE_DAYS', 365),
    ],

    /*
    |----------------------------------------------------------------------
    | Model overrides
    |----------------------------------------------------------------------
    |
    | Consuming apps that need to subclass the package's models can swap
    | the class names here. The package always resolves models via these
    | keys (`config('blax-mail.models.mailbox')`) rather than the FQCN,
    | so a subclass slots in without forking.
    |
    */
    'models' => [
        'mailbox' => \Blax\Mail\Models\Mailbox::class,
        'mail_message' => \Blax\Mail\Models\MailMessage::class,
        'mail_recipient' => \Blax\Mail\Models\MailRecipient::class,
        'mail_attachment' => \Blax\Mail\Models\MailAttachment::class,
        'mail_event' => \Blax\Mail\Models\MailEvent::class,
    ],
];
