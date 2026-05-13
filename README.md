[![Blax Software OSS](https://raw.githubusercontent.com/blax-software/laravel-workkit/master/art/oss-initiative-banner.svg)](https://github.com/blax-software)

# Laravel Mail

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10.x--13.x-orange)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Per-mailbox SMTP + IMAP, threaded message storage, open / click tracking, CQRS read queries, and a scheduler that polls itself — for Laravel apps that need more than fire-and-forget `Mail::send()`.

> [!NOTE]
> Public API may still shift between minor releases. Pin to a tag when you depend on it in production.

## Table of contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Quick start](#quick-start)
6. [Sending mail](#sending-mail)
7. [Receiving mail](#receiving-mail)
8. [Threading](#threading)
9. [Tracking (open / click)](#tracking-open--click)
10. [Events](#events)
11. [CQRS queries](#cqrs-queries)
12. [Models](#models)
13. [Enums](#enums)
14. [Console commands](#console-commands)
15. [Scheduler](#scheduler)
16. [Extending](#extending)
17. [Security](#security)
18. [Credits](#credits)
19. [License](#license)

## Features

### Multi-mailbox identity

- **Per-mailbox SMTP + IMAP credentials** stored as Eloquent rows. Each `Mailbox` carries its own host / port / encryption / username / password for both directions. The dispatcher builds a one-shot Laravel mailer per send, so you can ship from `support@`, `billing@`, and `noreply@` from the same Laravel app without touching `config/mail.php`.
- **Encrypted password columns** via Laravel's `encrypted` cast — rotates with `APP_KEY`.
- **Per-row enable flag** + `last_error` + `last_polled_at` so admin UIs can show health without re-reading logs.

### Outbound

- **`MailDispatcher::dispatch(OutboundMail)`** — single entry point. Persists a `MailMessage` row (status = `Queued`, with `Message-ID` + tracking token), then queues `SendMailJob` for the real SMTP handshake.
- **3 tries, 60 / 300 second backoff** on transport errors. Terminal failures flip the row to `Failed` and fire `OutboundMailFailed`.
- **Idempotent re-runs** — a queued job whose row is already `Sent` / `Delivered` returns early instead of double-sending.
- **Custom headers, attachments, `Reply-To`, `In-Reply-To`** all first-class on the `OutboundMail` DTO.

### Inbound

- **`blax-mail:poll`** command — fetches new messages from each enabled mailbox's IMAP folder, dedupes by `Message-ID`, persists them as inbound `MailMessage` rows with full headers / body / attachments.
- **UID watermarking** (`mailbox.meta.last_imap_uid`) so a mid-batch crash doesn't re-process what already landed.
- **Per-message failure isolation** — a single malformed message logs a warning and the batch moves on. The watermark only advances on successful persists for that UID.
- **Attachment download** to any Laravel `Storage` disk, with size cap.
- **Pure PHP** — uses [`directorytree/imapengine`](https://github.com/DirectoryTree/ImapEngine), no `ext-imap` required.

### Threading

- **Automatic `In-Reply-To` / `References` matching** against existing outbound `message_id`s — inbound replies attach to their parent without listener wiring.
- **`thread_root_id`** column on every message so a single indexed query returns the whole thread.

### Tracking

- **Open pixel** + **click rewrite** added to outbound HTML during dispatch.
- Both endpoints validate a per-message token (TTL-capped via `tracking.token_ttl_days`), record a `MailEvent`, then redirect / serve the pixel.
- Disable globally via `BLAX_MAIL_TRACKING=false`; per-send opt-out is on the roadmap.

### Read side (CQRS)

- **Three query objects** — `ListMessagesQuery`, `GetThreadQuery`, `FindMessageByMessageIdQuery` — resolved from the container. Composable, mockable, no leaky Eloquent scope chains in your controllers.

### Operational

- **Auto-scheduled poller** — the package's service provider registers `blax-mail:poll` on the host scheduler (default: every minute, `withoutOverlapping`). No `routes/console.php` boilerplate needed.
- **`blax-mail:cleanup`** purges soft-deleted messages older than `retention.purge_days`.
- **Six events** for routing / observability (see [Events](#events)).
- **Model overrides** via config — swap any of the package's five models for a subclass without forking.

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| Laravel | 10, 11, 12, or 13 |
| Queue driver | any (database, redis, sqs, …) — `SendMailJob` implements `ShouldQueue` |
| Inbound | An IMAP-accessible mailbox |
| Outbound | An SMTP-accessible mailbox |

## Installation

```bash
composer require blax-software/laravel-mail
php artisan vendor:publish --tag=blax-mail-config
php artisan migrate
```

The service provider is auto-discovered. The poller registers itself on the scheduler automatically — you don't need to add anything to `routes/console.php`.

## Configuration

All keys are environment-overridable. Defaults in `config/blax-mail.php`:

```php
return [
    'tracking' => [
        'enabled'        => env('BLAX_MAIL_TRACKING', true),
        'route_prefix'   => env('BLAX_MAIL_ROUTE_PREFIX', 'blax-mail/track'),
        'token_ttl_days' => env('BLAX_MAIL_TOKEN_TTL_DAYS', 90),
        'middleware'     => ['web'],
    ],

    'imap' => [
        'default_folder'                 => env('BLAX_MAIL_IMAP_FOLDER', 'INBOX'),
        'fetch_limit'                    => (int) env('BLAX_MAIL_IMAP_FETCH_LIMIT', 200),
        'default_interval_minutes'       => (int) env('BLAX_MAIL_IMAP_INTERVAL', 1),
        'schedule_enabled'               => env('BLAX_MAIL_SCHEDULE_ENABLED', true),
        'poll_cron'                      => env('BLAX_MAIL_POLL_CRON', '* * * * *'),
        'schedule_without_overlapping'   => env('BLAX_MAIL_SCHEDULE_NO_OVERLAP', true),
        'auto_thread'                    => env('BLAX_MAIL_AUTO_THREAD', true),
        'attachments' => [
            'download'    => env('BLAX_MAIL_DOWNLOAD_ATTACHMENTS', true),
            'disk'        => env('BLAX_MAIL_ATTACHMENT_DISK', 'local'),
            'path_prefix' => env('BLAX_MAIL_ATTACHMENT_PATH', 'blax-mail/attachments'),
            'max_bytes'   => (int) env('BLAX_MAIL_ATTACHMENT_MAX_BYTES', 25 * 1024 * 1024),
        ],
    ],

    'outbound' => [
        'default_from_name' => env('BLAX_MAIL_DEFAULT_FROM_NAME', config('app.name')),
        'list_unsubscribe'  => env('BLAX_MAIL_LIST_UNSUBSCRIBE', true),
        'click_tracking'    => env('BLAX_MAIL_CLICK_TRACKING', true),
    ],

    'retention' => [
        'purge_days' => (int) env('BLAX_MAIL_PURGE_DAYS', 365),
    ],

    'models' => [
        'mailbox'         => \Blax\Mail\Models\Mailbox::class,
        'mail_message'    => \Blax\Mail\Models\MailMessage::class,
        'mail_recipient'  => \Blax\Mail\Models\MailRecipient::class,
        'mail_attachment' => \Blax\Mail\Models\MailAttachment::class,
        'mail_event'      => \Blax\Mail\Models\MailEvent::class,
    ],
];
```

## Quick start

### 1. Configure a mailbox

```php
use Blax\Mail\Models\Mailbox;

$box = Mailbox::create([
    'name'            => 'Support',
    'email'           => 'support@example.com',
    'from_name'       => 'ACME Support',

    'smtp_host'       => 'smtp.example.com',
    'smtp_port'       => 587,
    'smtp_encryption' => 'tls',
    'smtp_username'   => 'support@example.com',
    'smtp_password'   => 'secret',     // auto-encrypted on save

    'imap_host'       => 'imap.example.com',
    'imap_port'       => 993,
    'imap_encryption' => 'ssl',
    'imap_username'   => 'support@example.com',
    'imap_password'   => 'secret',     // auto-encrypted on save
    'imap_folder'     => 'INBOX',

    'enabled'         => true,
]);
```

### 2. Send

```php
use Blax\Mail\Services\MailDispatcher;
use Blax\Mail\DTOs\OutboundMail;

app(MailDispatcher::class)->dispatch(new OutboundMail(
    mailbox:  $box,
    to:       ['tim@example.com'],
    subject:  'Re: Delivery',
    bodyHtml: $html,
    bodyText: $text,
));
```

### 3. Receive

The poller is already scheduled (every minute by default — see [Scheduler](#scheduler)). To process the queue and the scheduler in development:

```bash
php artisan queue:work       # processes SendMailJob
php artisan schedule:work    # runs blax-mail:poll on its cron
```

Listen for new inbound mail:

```php
use Blax\Mail\Events\InboundMailReceived;

Event::listen(InboundMailReceived::class, function (InboundMailReceived $event) {
    // $event->message — the persisted MailMessage row
    // $event->threadParent — the matched outbound parent (null if first contact)
});
```

## Sending mail

### `OutboundMail` DTO

The full constructor signature:

```php
new OutboundMail(
    mailbox:      $box,                    // Blax\Mail\Models\Mailbox — must canSend()
    to:           ['a@example.com'],       // string[]
    subject:      'Hello',                 // string
    bodyHtml:     '<p>Hi</p>',             // string|null
    bodyText:     'Hi',                    // string|null
    cc:           [],                      // string[]
    bcc:          [],                      // string[]
    replyTo:      'support@example.com',   // string|null — overrides mailbox.reply_to for this send
    inReplyTo:    '<msg-id@example.com>',  // string|null — stamps In-Reply-To + References headers
    attachments:  [$outboundAttachment],   // OutboundAttachment[]
    headers:      ['X-Campaign-Id' => 'spring-2026'],  // extra mail headers
    subjectType:  'order',                 // string|null — polymorphic hint persisted on the row
    subjectId:    (string) $order->id,     // string|null
    meta:         ['app_mail_id' => 'abc'],// array — free-form, persisted on the row + on every event
);
```

One of `bodyHtml` or `bodyText` is required. The DTO is `final` + readonly — pass it to `MailDispatcher::dispatch()` and that's it.

### What `dispatch()` does

1. Builds an inbound `MailMessage` row with status `Queued`, a generated `Message-ID`, the canonical body, recipients, attachments, and a tracking token.
2. Logs a `MailEvent` of type `Queued`.
3. Fires `OutboundMailQueued`.
4. Queues `SendMailJob` with the row's id + the DTO.

When the job runs, it:

5. Builds a transient Laravel mailer using the `Mailbox`'s SMTP credentials (mailer name is `blax-mail-<mailbox-id>` — concurrent sends from different mailboxes don't fight over the same config key).
6. Sends through Symfony Mailer, stamps the canonical `Message-ID`, injects the tracking pixel + link rewrites.
7. On success: row → `Sent`, fires `OutboundMailSent`.
8. On all retries exhausted: row → `Failed`, fires `OutboundMailFailed`.

## Receiving mail

The poller (`Blax\Mail\Services\ImapPoller`) iterates every enabled mailbox, fetches messages above the watermark, persists them as inbound `MailMessage` rows, and fires `InboundMailReceived` for each.

### Watermarking

`mailbox.meta.last_imap_uid` advances only when a UID processes cleanly. A mid-batch failure leaves the watermark where it was, so the next poll retries the same UIDs — no message loss on transient errors.

### What lands on the row

| Column | Source |
|---|---|
| `message_id` | RFC 5322 `Message-ID` header, normalized to `<id@host>` (synthesized when missing) |
| `in_reply_to` | First `In-Reply-To` value (multi-value headers collapsed) |
| `references` | Raw `References` value |
| `subject`, `body_text`, `body_html`, `raw_headers` | Parsed from the IMAP message |
| `from_address`, `from_name` | Decoded address header |
| `to`, `cc`, `bcc` | Address lists (also persisted to `MailRecipient` rows for indexed lookups) |
| `received_at` | IMAP date header, falls back to `now()` |
| `meta.imap_uid` | The fetched UID for diagnostics |

Attachments are persisted to `MailAttachment` rows. When `imap.attachments.download = true` the bytes are streamed to the configured disk; oversized attachments (> `max_bytes`) skip the download but keep the metadata.

## Threading

When `imap.auto_thread = true` (default), the poller's `MessageThreader` matches each inbound's `In-Reply-To` / `References` against existing outbound `message_id`s. On a hit:

- The inbound row's `thread_root_id` points at the outbound parent.
- The `parent_id` column points at the immediate ancestor in the thread.
- `MailEvent::Threaded` records the match.

To walk the whole thread:

```php
use Blax\Mail\Queries\GetThreadQuery;

$thread = app(GetThreadQuery::class)
    ->forMessage($message)
    ->execute();   // Collection<MailMessage>, ordered by created_at
```

Apps that prefer their own threading set `BLAX_MAIL_AUTO_THREAD=false` and subscribe to `InboundMailReceived`.

## Tracking (open / click)

`config('blax-mail.tracking.enabled')` controls the full open/click pipeline. When enabled, outbound HTML is rewritten at dispatch time:

- A 1×1 transparent GIF `<img>` pointing at `/{route_prefix}/open/{token}.gif` is appended.
- Every `<a href>` is rewritten to `/{route_prefix}/click/{token}?u={signed-target}`.

Both endpoints validate the token, record a `MailEvent` (`Opened` / `Clicked`), then redirect / serve the pixel. Past `token_ttl_days` the pixel still returns 200 OK (mail clients don't mark the message broken) but no event is logged.

| Route name | URI | Behaviour |
|---|---|---|
| `blax-mail.tracking.open` | `GET {prefix}/open/{token}.gif` | 1×1 GIF, fires `MailOpened` |
| `blax-mail.tracking.click` | `GET {prefix}/click/{token}?u={target}` | 302 to `target`, records `MailEvent::Clicked` |

The text body is never rewritten — plain-text alternatives stay untouched.

## Events

| Event | Payload | When |
|---|---|---|
| `OutboundMailQueued` | `MailMessage` | `dispatch()` persisted the row + queued the send |
| `OutboundMailSent` | `MailMessage` | SMTP accepted the message |
| `OutboundMailFailed` | `MailMessage`, `Throwable` | All retries exhausted |
| `InboundMailReceived` | `MailMessage`, `?MailMessage $threadParent` | Poller persisted an inbound row |
| `MailOpened` | `MailMessage`, `?string $userAgent`, `?string $ip` | Tracking pixel hit |

> Click events are currently persisted as `MailEvent::Clicked` rows but no dedicated `MailClicked` event class is fired yet — subscribe to the underlying model events if you need the hook today.

## CQRS queries

Three query objects in `Blax\Mail\Queries`. Resolve from the container, chain builders, call `execute()`:

### `ListMessagesQuery`

```php
$inbox = app(ListMessagesQuery::class)
    ->forMailbox($box->id)
    ->inboundOnly()
    ->unread()
    ->since(now()->subWeek())
    ->limit(50)
    ->execute();          // Collection<MailMessage>

// or paginate:
$page = app(ListMessagesQuery::class)
    ->forMailbox($box->id)
    ->outboundOnly()
    ->withStatus(MailStatus::Sent)
    ->paginate(25);
```

Builders: `forMailbox()`, `direction()`, `inboundOnly()`, `outboundOnly()`, `withStatus()`, `unread()`, `forSubject($type, $id)`, `since()`, `until()`, `limit()`, `execute()`, `paginate()`.

### `GetThreadQuery`

```php
$thread = app(GetThreadQuery::class)
    ->forMessage($message)
    ->execute();          // Collection<MailMessage>, ordered chronologically
```

### `FindMessageByMessageIdQuery`

```php
$msg = app(FindMessageByMessageIdQuery::class)
    ->execute('<msg-id@example.com>');   // ?MailMessage
```

## Models

| Class | Table | Purpose |
|---|---|---|
| `Blax\Mail\Models\Mailbox` | `mailboxes` | Per-identity SMTP + IMAP config + watermark |
| `Blax\Mail\Models\MailMessage` | `mail_messages` | One row per sent / received message |
| `Blax\Mail\Models\MailRecipient` | `mail_recipients` | Normalized address-per-row for indexed `forSubject` lookups |
| `Blax\Mail\Models\MailAttachment` | `mail_attachments` | Filename, mime, size, storage path |
| `Blax\Mail\Models\MailEvent` | `mail_events` | Audit log: `Queued` / `Sent` / `Opened` / `Clicked` / … |

`MailMessage` also exposes:

```php
$message->mailbox;      // BelongsTo Mailbox
$message->recipients;   // HasMany MailRecipient
$message->attachments;  // HasMany MailAttachment
$message->events;       // HasMany MailEvent (audit timeline)
$message->subject;      // MorphTo — resolves the polymorphic subject if set
$message->thread();     // Whole thread as a Collection
$message->parent();     // Immediate parent in the thread, or null
$message->isInbound();  // bool
$message->isOutbound(); // bool
$message->markRead();   // status → Read
```

### Swapping a model

```php
// config/blax-mail.php
'models' => [
    'mail_message' => \App\Models\MyMailMessage::class,   // extends Blax\Mail\Models\MailMessage
],
```

The package resolves every model via `config('blax-mail.models.X')`, so a subclass slots in without touching the core code.

## Enums

`Blax\Mail\Enums\MailDirection`:

| Case | Value |
|---|---|
| `Outbound` | `outbound` |
| `Inbound` | `inbound` |

`Blax\Mail\Enums\MailStatus`:

| Case | Value | Notes |
|---|---|---|
| `Queued` | `queued` | Outbound — persisted, awaiting SMTP |
| `Sending` | `sending` | Outbound — `SendMailJob` is running |
| `Sent` | `sent` | Outbound — SMTP accepted |
| `Delivered` | `delivered` | Outbound — confirmed delivered (provider-dependent) |
| `Bounced` | `bounced` | Outbound — provider reported bounce |
| `Failed` | `failed` | Outbound — all retries exhausted |
| `Received` | `received` | Inbound — fresh from poller |
| `Read` | `read` | Inbound — `markRead()` was called |

`Blax\Mail\Enums\MailEventType` (audit log entries): `Queued`, `Sent`, `Delivered`, `Bounced`, `Complaint`, `Failed`, `Opened`, `Clicked`, `Received`, `Threaded`.

## Console commands

```bash
# Poll every enabled mailbox once (typically invoked by the scheduler).
php artisan blax-mail:poll

# Restrict to one mailbox (matches by id, email, or name):
php artisan blax-mail:poll --mailbox=support@example.com

# Hard-delete soft-deleted messages older than retention.purge_days.
php artisan blax-mail:cleanup
```

## Scheduler

The package self-registers `blax-mail:poll` on the host scheduler via `callAfterResolving(Schedule::class, …)` — the binding only resolves inside `schedule:run` / `schedule:work`, so web requests and other commands pay nothing.

Defaults:

```
cron expression       * * * * *      every minute
without overlapping   true           mutex prevents queue-up
```

Override per environment:

```dotenv
BLAX_MAIL_POLL_CRON="*/5 * * * *"          # poll every 5 minutes
BLAX_MAIL_SCHEDULE_NO_OVERLAP=false        # allow parallel polls
BLAX_MAIL_SCHEDULE_ENABLED=false           # disable auto-schedule, register manually
```

If you set `BLAX_MAIL_SCHEDULE_ENABLED=false`, register the command yourself:

```php
// routes/console.php
Schedule::command('blax-mail:poll')->everyTenMinutes()->withoutOverlapping();
```

## Extending

### React to inbound mail

```php
use Blax\Mail\Events\InboundMailReceived;

Event::listen(InboundMailReceived::class, function (InboundMailReceived $event) {
    // $event->message       — Blax\Mail\Models\MailMessage (already persisted)
    // $event->threadParent  — ?MailMessage (the matched outbound, or null)

    // Typical pattern: file the inbound onto your own domain pivot.
    // Walk threadParent → meta.app_mail_id → your domain Mail row, then clone
    // its M:N subject linkage onto the inbound so it surfaces on every
    // entity feed the original outbound was filed under.
});
```

### Custom threading

```dotenv
BLAX_MAIL_AUTO_THREAD=false
```

Then subscribe to `InboundMailReceived` and set `thread_root_id` / `parent_id` yourself.

### Custom transport / storage

Bind your own implementation:

```php
// AppServiceProvider::register
$this->app->singleton(\Blax\Mail\Contracts\Dispatcher::class, MyDispatcher::class);
$this->app->singleton(\Blax\Mail\Contracts\Poller::class, MyPoller::class);
```

Both contracts have one method (`dispatch(OutboundMail): MailMessage`, `poll(Mailbox): int`).

## Security

Please report vulnerabilities by email: **office@blax.at**. We'll acknowledge within 72 hours.

## Credits

- [Fabian Wagner](https://github.com/fabianwagner)
- [All Contributors](../../contributors)

## License

MIT. See [LICENSE](LICENSE).

## Star History

<a href="https://www.star-history.com/?repos=blax-software%2Flaravel-mail&type=date&legend=top-left">
 <picture>
   <source media="(prefers-color-scheme: dark)" srcset="https://api.star-history.com/chart?repos=blax-software/laravel-mail&type=date&theme=dark&legend=top-left" />
   <source media="(prefers-color-scheme: light)" srcset="https://api.star-history.com/chart?repos=blax-software/laravel-mail&type=date&legend=top-left" />
   <img alt="Star History Chart" src="https://api.star-history.com/chart?repos=blax-software/laravel-mail&type=date&legend=top-left" />
 </picture>
</a>
