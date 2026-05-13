<?php

declare(strict_types=1);

namespace Blax\Mail\DTOs;

/**
 * One file to attach to an outbound mail.
 *
 * Two modes — exactly one of `bytes` or `path` must be set:
 *
 *  - `bytes` — the file's contents inline. Used for generated PDFs
 *    (invoices, receipts) that don't exist on disk.
 *  - `path` — absolute path on the application server. Used for
 *    pre-existing assets (the company logo for inline references).
 *
 * `contentId` marks the attachment as inline — the dispatcher passes
 * it through so HTML bodies can reference `cid:<contentId>` images.
 */
final class OutboundAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly ?string $bytes = null,
        public readonly ?string $path = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $contentId = null,
    ) {
        if (($bytes === null) === ($path === null)) {
            throw new \InvalidArgumentException(
                'OutboundAttachment needs exactly one of `bytes` or `path`.'
            );
        }
    }

    public function isInline(): bool
    {
        return $this->contentId !== null;
    }
}
