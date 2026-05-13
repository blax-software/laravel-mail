<?php

declare(strict_types=1);

namespace Blax\Mail\Queries;

use Blax\Mail\Enums\MailDirection;
use Blax\Mail\Enums\MailStatus;
use Blax\Mail\Models\MailMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read-side query: list `MailMessage` rows by mailbox / direction /
 * status / date range / unread, paginated or capped.
 *
 * CQRS conventions:
 *
 *  - Constructors only take a `Container` (or nothing) — every option
 *    is set via a fluent `withX(...)` style method that returns a
 *    *new* instance so call sites can compose: `$base->unread()`.
 *  - `execute()` runs the query. Two call sites: `execute()` returns
 *    a `Collection`, `paginate(perPage)` returns a paginator.
 *  - The class never mutates the database — it's pure read. For
 *    writes (mark-as-read, attach to subject, …) use a Command class.
 *
 * The query produces Eloquent collections of `MailMessage` rather than
 * a custom DTO so consumers can lean on Eloquent helpers (eager-load
 * recipients/attachments, etc.). If a future revision needs to swap
 * storage, the model is the seam — every callsite already uses the
 * model's accessors, not raw arrays.
 */
class ListMessagesQuery
{
    protected ?string $mailboxId = null;
    protected ?MailDirection $direction = null;
    protected ?MailStatus $status = null;
    protected ?string $subjectType = null;
    protected ?string $subjectId = null;
    protected ?\DateTimeInterface $since = null;
    protected ?\DateTimeInterface $until = null;
    protected bool $unreadOnly = false;
    protected int $limit = 0; // 0 = no cap

    public function forMailbox(string $mailboxId): static
    {
        $clone = clone $this;
        $clone->mailboxId = $mailboxId;

        return $clone;
    }

    public function direction(MailDirection $direction): static
    {
        $clone = clone $this;
        $clone->direction = $direction;

        return $clone;
    }

    public function inboundOnly(): static
    {
        return $this->direction(MailDirection::Inbound);
    }

    public function outboundOnly(): static
    {
        return $this->direction(MailDirection::Outbound);
    }

    public function withStatus(MailStatus $status): static
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function unread(): static
    {
        $clone = clone $this;
        $clone->unreadOnly = true;

        return $clone;
    }

    public function forSubject(string $type, string $id): static
    {
        $clone = clone $this;
        $clone->subjectType = $type;
        $clone->subjectId = $id;

        return $clone;
    }

    public function since(\DateTimeInterface $at): static
    {
        $clone = clone $this;
        $clone->since = $at;

        return $clone;
    }

    public function until(\DateTimeInterface $at): static
    {
        $clone = clone $this;
        $clone->until = $at;

        return $clone;
    }

    public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->limit = max(0, $limit);

        return $clone;
    }

    /** Build a base Eloquent query carrying every set filter. */
    protected function build()
    {
        $q = MailMessage::query()
            ->with(['mailbox', 'recipients', 'attachments'])
            ->orderByDesc('received_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at');

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }
        if ($this->direction) {
            $q->where('direction', $this->direction);
        }
        if ($this->status) {
            $q->where('status', $this->status);
        }
        if ($this->subjectType && $this->subjectId) {
            $q->where('subject_type', $this->subjectType)
                ->where('subject_id', $this->subjectId);
        }
        if ($this->since) {
            $q->where(function ($inner) {
                $inner->where('received_at', '>=', $this->since)
                    ->orWhere('sent_at', '>=', $this->since);
            });
        }
        if ($this->until) {
            $q->where(function ($inner) {
                $inner->where('received_at', '<=', $this->until)
                    ->orWhere('sent_at', '<=', $this->until);
            });
        }
        if ($this->unreadOnly) {
            // Inbound rows: read when status === Read. Outbound rows
            // are never "read" in this sense — they're always sent.
            // Restricting to inbound + unread covers the "show me my
            // open inbox tickets" use case.
            $q->where('direction', MailDirection::Inbound)
                ->where('status', '!=', MailStatus::Read);
        }
        if ($this->limit > 0) {
            $q->limit($this->limit);
        }

        return $q;
    }

    /** Run the query, returning a flat collection of MailMessage rows. */
    public function execute(): Collection
    {
        return $this->build()->get();
    }

    /** Run the query with pagination — drop-in for inbox UIs. */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return $this->build()->paginate($perPage);
    }
}
