[![Blax Software OSS](https://raw.githubusercontent.com/blax-software/laravel-workkit/master/art/oss-initiative-banner.svg)](https://github.com/blax-software)

# Laravel Mail

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10.x--13.x-orange)](https://laravel.com)

Tracked outbound + IMAP-synced inbound mail for Laravel apps that need more than fire-and-forget SMTP. Per-mailbox SMTP/IMAP credentials, threaded messages, open/click tracking, CQRS read side, and a scheduler-friendly poller.

> [!NOTE]
> v0.1 — public API may still shift between minor releases. Pin to a tag when you depend on it.

## Features

- **Per-mailbox SMTP/IMAP credentials** persisted as Eloquent models, encrypted on disk via Laravel's `encrypted` cast (rotates with `APP_KEY`).
- **Tracked outbound send** — every dispatched mail becomes a `MailMessage` row with `Message-ID`, headers, body, recipients, attachments, and an open/click tracking token.
- **IMAP polling** via `php artisan blax-mail:poll` — reads INBOX (or any folder) per mailbox, batches new messages, persists them as inbound `MailMessage` rows. Idempotent on reconnect thanks to `mailbox.last_polled_at` + UID tracking. Built for the scheduler.
- **Threading** — inbound `In-Reply-To` / `References` are resolved against the canonical `message_id` column on outbound rows, wiring reply chains automatically.
- **CQRS read side** — queries (`ListMessagesQuery`, `FindMessageByMessageIdQuery`, `GetThreadQuery`, …) are first-class objects you `resolve()` from the container, not Eloquent scope chains, so the read path stays composable and testable.
- **Events** — `InboundMailReceived`, `OutboundMailQueued`, `OutboundMailSent`, `OutboundMailFailed`, `MailOpened`, `MailClicked`. Subscribe to wire your own routing/UX (e.g. attach an inbound mail to an `Order` via your own M:N pivot).
- **Tracking pixel + click-through** route published under `blax-mail/track/*`, TTL-capped per `tracking.token_ttl_days`.
- **No opinion on subject linkage** — the package's `subject_type` / `subject_id` columns are a polymorphic *escape hatch*; apps with richer M:N business logic plug in via the `InboundMailReceived` listener.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12 or 13
- IMAP-accessible mailbox (the package uses [`directorytree/imapengine`](https://github.com/DirectoryTree/ImapEngine) — no `ext-imap` required)

## Installation

```bash
composer require blax-software/laravel-mail
php artisan vendor:publish --tag=blax-mail-config
php artisan migrate
```

The service provider is auto-discovered. No manual `config/app.php` edit needed.

## Configuration

`config/blax-mail.php`:

```php
return [
    // Encrypted credentials on the Mailbox model use Laravel's APP_KEY.

    'tracking' => [
        'enabled' => env('BLAX_MAIL_TRACKING', true),
        'route_prefix' => 'blax-mail/track',
        'pixel_dimensions' => [1, 1],
        // Token TTL — how long a tracking pixel keeps reporting. After
        // this window the controller returns the pixel without recording.
        'token_ttl_days' => 90,
    ],

    'imap' => [
        'default_folder' => 'INBOX',
        // How many messages per poll. The poller fetches new messages
        // since `mailbox.last_polled_at`; this cap keeps memory bounded
        // when reconnecting to a long-untouched mailbox.
        'fetch_limit' => 200,
        // Threading: when an inbound In-Reply-To matches a known
        // outbound Message-ID, link the rows automatically.
        'auto_thread' => true,
    ],

    'retention' => [
        // Hard-delete soft-deleted messages older than X days. 0 = never.
        'purge_days' => 365,
    ],
];
```

## Quick Start

### 1. Configure a mailbox

```php
use Blax\Mail\Models\Mailbox;

$box = Mailbox::create([
    'name' => 'Support',
    'email' => 'support@example.com',
    'from_name' => 'ACME Support',
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
    'smtp_username' => 'support@example.com',
    'smtp_password' => 'secret',     // auto-encrypted on save
    'imap_host' => 'imap.example.com',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'imap_username' => 'support@example.com',
    'imap_password' => 'secret',     // auto-encrypted on save
    'imap_folder' => 'INBOX',
    'enabled' => true,
]);
```

### 2. Send

```php
use Blax\Mail\Services\MailDispatcher;
use Blax\Mail\DTOs\OutboundMail;

app(MailDispatcher::class)->dispatch(new OutboundMail(
    mailbox: $box,
    to: ['tim@example.com'],
    subject: 'Re: Lieferung',
    bodyHtml: $html,
    bodyText: $text,
    // Optional polymorphic link the package echoes back on events.
    // Doesn't replace your app's M:N subject system — that's downstream.
    subjectType: 'order',
    subjectId: $order->id,
));
```

### 3. Receive — schedule the poller

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
Schedule::command('blax-mail:poll')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

Subscribe to `InboundMailReceived` to wire app-specific routing:

```php
use Blax\Mail\Events\InboundMailReceived;
use Blax\Mail\Queries\GetThreadQuery;

Event::listen(InboundMailReceived::class, function ($event) {
    // $event->message is a Blax\Mail\Models\MailMessage row, already
    // persisted + threaded if a parent Message-ID was matched.
    $thread = app(GetThreadQuery::class)->forMessage($event->message)->execute();

    // Plug into your own domain — e.g. find the Order this reply
    // belongs to and attach the inbound mail to it.
});
```

### 4. Read (CQRS)

```php
use Blax\Mail\Queries\ListMessagesQuery;
use Blax\Mail\Queries\GetThreadQuery;

$inbox = app(ListMessagesQuery::class)
    ->forMailbox($box->id)
    ->inboundOnly()
    ->unread()
    ->limit(50)
    ->execute();

$thread = app(GetThreadQuery::class)
    ->forMessage($message)
    ->execute();
```

Queries return read-models (DTOs), not Eloquent collections, so consumers can swap the storage layer without breaking call sites.

## Events

| Event                  | Fired when                                                   |
|------------------------|---------------------------------------------------------------|
| `OutboundMailQueued`   | A new outbound `MailMessage` row has been persisted          |
| `OutboundMailSent`     | SMTP accepted the message                                    |
| `OutboundMailFailed`   | SMTP rejected or threw                                       |
| `InboundMailReceived`  | A new inbound `MailMessage` row was inserted by the poller   |
| `MailOpened`           | Tracking pixel was fetched                                   |
| `MailClicked`          | A tracked link was clicked through                            |

## Tracking

`config('blax-mail.tracking.enabled')` toggles the open/click pipeline. When enabled:

- Outbound HTML bodies are rewritten — a 1×1 pixel `<img>` pointing at `/{route_prefix}/open/{token}` is appended, and every `<a href>` is wrapped in `/{route_prefix}/click/{token}?url=…`.
- Both endpoints validate the token (`token_ttl_days` window), record the event, then redirect / serve the pixel.
- Text bodies are left untouched — no tracking on plain-text alternatives.

Set `BLAX_MAIL_TRACKING=false` in `.env` to disable globally (legacy / compliance use cases).

## Console commands

```bash
# Poll every enabled mailbox once. Wire from the scheduler.
php artisan blax-mail:poll

# Restrict to a single mailbox (matches by id, email, or `name`):
php artisan blax-mail:poll --mailbox=support@example.com

# Purge soft-deleted messages older than `retention.purge_days`.
php artisan blax-mail:cleanup
```

## Documentation

- Main README (this file)
- Source-level comments cover non-obvious decisions inside `src/Services/*` and `src/Models/MailMessage.php`.

## Security

Please report vulnerabilities by email: office@blax.at.

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
