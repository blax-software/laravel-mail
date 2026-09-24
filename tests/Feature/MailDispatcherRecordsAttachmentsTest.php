<?php

declare(strict_types=1);

namespace Blax\Mail\Tests\Feature;

use Blax\Mail\Contracts\Dispatcher;
use Blax\Mail\DTOs\OutboundAttachment;
use Blax\Mail\DTOs\OutboundMail;
use Blax\Mail\Enums\MailStatus;
use Blax\Mail\Jobs\SendMailJob;
use Blax\Mail\Models\MailAttachment;
use Blax\Mail\Models\MailMessage;
use Blax\Mail\Services\MailDispatcher;
use Blax\Mail\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * `MailDispatcher::dispatch()` persists one `mail_attachments` row per
 * `OutboundAttachment` — metadata + sha256, never storage_* — so the
 * read side can show what was sent while the bytes keep travelling in
 * the queued `SendMailJob`.
 */
class MailDispatcherRecordsAttachmentsTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blax-mail-test-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function dispatch(array $attachments, ?MailDispatcher $dispatcher = null): MailMessage
    {
        $dispatcher ??= app(Dispatcher::class);

        return $dispatcher->dispatch(new OutboundMail(
            mailbox: $this->sendingMailbox(),
            to: ['tim@example.com'],
            subject: 'Invoice 2026-00001',
            bodyHtml: '<p>Your invoice is attached.</p>',
            bodyText: 'Your invoice is attached.',
            attachments: $attachments,
        ));
    }

    public function test_it_records_one_row_per_attachment_from_path_and_from_bytes(): void
    {
        $pdf = '%PDF-1.4 fake invoice body '.str_repeat('x', 500);
        $path = $this->tempFile($pdf);
        $terms = "Terms and conditions\n".str_repeat('lorem ', 40);

        $message = $this->dispatch([
            new OutboundAttachment(filename: '2026-00001.pdf', path: $path, mimeType: 'application/pdf'),
            new OutboundAttachment(filename: 'terms.txt', bytes: $terms, mimeType: 'text/plain'),
        ]);

        $this->assertSame(MailStatus::Queued, $message->status);
        Queue::assertPushed(SendMailJob::class, fn (SendMailJob $job) => $job->mailMessageId === $message->id
            && count($job->outbound->attachments) === 2);

        $rows = $message->attachments()->orderBy('filename')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(2, MailAttachment::count());

        $fromPath = $rows->firstWhere('filename', '2026-00001.pdf');
        $this->assertSame('application/pdf', $fromPath->mime_type);
        $this->assertSame(strlen($pdf), $fromPath->size_bytes);
        $this->assertSame(hash('sha256', $pdf), $fromPath->checksum);
        $this->assertNull($fromPath->storage_disk);
        $this->assertNull($fromPath->storage_path);
        $this->assertFalse($fromPath->inline);
        $this->assertNull($fromPath->content_id);
        $this->assertSame(['source' => 'outbound', 'path' => $path], $fromPath->meta);
        $this->assertFalse($fromPath->isAvailable());

        $fromBytes = $rows->firstWhere('filename', 'terms.txt');
        $this->assertSame('text/plain', $fromBytes->mime_type);
        $this->assertSame(strlen($terms), $fromBytes->size_bytes);
        $this->assertSame(hash('sha256', $terms), $fromBytes->checksum);
        $this->assertNull($fromBytes->storage_disk);
        $this->assertNull($fromBytes->storage_path);
        $this->assertSame(['source' => 'outbound'], $fromBytes->meta);
    }

    public function test_the_concrete_dispatcher_records_attachments_too(): void
    {
        $message = $this->dispatch(
            [new OutboundAttachment(filename: 'a.txt', bytes: 'alpha')],
            app(MailDispatcher::class),
        );

        $this->assertSame(1, $message->attachments()->count());
        $this->assertSame(hash('sha256', 'alpha'), $message->attachments()->first()->checksum);
    }

    public function test_inline_attachments_carry_the_content_id_and_the_inline_flag(): void
    {
        $message = $this->dispatch([
            new OutboundAttachment(filename: 'logo.png', bytes: 'png-bytes', mimeType: 'image/png', contentId: 'logo@example'),
        ]);

        $row = $message->attachments()->firstOrFail();
        $this->assertTrue($row->inline);
        $this->assertSame('logo@example', $row->content_id);
        $this->assertSame('image/png', $row->mime_type);
    }

    public function test_a_mail_without_attachments_records_nothing(): void
    {
        $message = $this->dispatch([]);

        $this->assertSame(0, $message->attachments()->count());
        $this->assertSame(0, MailAttachment::count());
        Queue::assertPushed(SendMailJob::class, 1);
    }

    public function test_recording_can_be_switched_off_without_touching_the_send(): void
    {
        config(['blax-mail.outbound.record_attachments' => false]);

        $message = $this->dispatch([
            new OutboundAttachment(filename: 'terms.txt', bytes: 'terms'),
        ]);

        $this->assertSame(0, MailAttachment::count());
        Queue::assertPushed(SendMailJob::class, fn (SendMailJob $job) => $job->mailMessageId === $message->id
            && $job->outbound->attachments[0]->filename === 'terms.txt');
    }

    public function test_an_unreadable_path_is_recorded_as_metadata_and_never_blocks_the_send(): void
    {
        $missing = sys_get_temp_dir().'/blax-mail-does-not-exist-'.bin2hex(random_bytes(4)).'.pdf';

        $message = $this->dispatch([
            new OutboundAttachment(filename: 'missing.pdf', path: $missing, mimeType: 'application/pdf'),
        ]);

        $this->assertSame(MailStatus::Queued, $message->status);
        Queue::assertPushed(SendMailJob::class, 1);

        $row = $message->attachments()->firstOrFail();
        $this->assertSame('missing.pdf', $row->filename);
        $this->assertSame(0, $row->size_bytes);
        $this->assertNull($row->checksum);
        $this->assertNull($row->storage_path);
        $this->assertSame(['source' => 'outbound', 'path' => $missing], $row->meta);
    }
}
