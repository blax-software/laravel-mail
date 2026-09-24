<?php

declare(strict_types=1);

namespace Blax\Mail\Tests;

use Blax\Mail\MailServiceProvider;
use Blax\Mail\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Testbench base: in-memory sqlite, the package provider, fresh
 * package migrations per test. Tests build their fixtures through the
 * helpers below instead of factories — the models are small enough.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [MailServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // `Mailbox` encrypts the SMTP/IMAP passwords — that needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('blax-mail.imap.schedule_enabled', false);
    }

    /** A mailbox with enough SMTP config for `canSend()`. */
    protected function sendingMailbox(array $overrides = []): Mailbox
    {
        return Mailbox::create(array_merge([
            'name' => 'Shop',
            'email' => 'shop@example.test',
            'from_name' => 'Example Shop',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'shop@example.test',
            'smtp_password' => 'secret',
            'enabled' => true,
        ], $overrides));
    }
}
