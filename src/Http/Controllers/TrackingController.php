<?php

declare(strict_types=1);

namespace Blax\Mail\Http\Controllers;

use Blax\Mail\Enums\MailEventType;
use Blax\Mail\Events\MailOpened;
use Blax\Mail\Models\MailEvent;
use Blax\Mail\Models\MailMessage;
use Blax\Mail\Services\MailTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Open-tracking + click-redirect endpoints.
 *
 * Both routes look up the `MailMessage` by its `tracking_token` — a
 * random 32-char string set at dispatch. Tokens (not encrypted ids)
 * so URLs stay short + the column can later be rotated/revoked by
 * clearing the value.
 *
 * The open endpoint ALWAYS returns the pixel, even on tampered or
 * expired tokens, so a recipient's mail client never marks the
 * message as broken. Event logging short-circuits silently when the
 * token doesn't resolve or the row is past `token_ttl_days`.
 *
 * The click endpoint signs the redirect target into the URL itself
 * (so the redirect can't be hijacked by editing the URL) and records
 * the click before redirecting.
 */
class TrackingController
{
    /**
     * GET `{prefix}/open/{token}.gif`
     *
     * Returns a 1×1 transparent GIF. Records a `MailOpened` event
     * (and persists a `MailEvent::Opened`) when the token resolves to
     * a known row within `token_ttl_days`. Outside that window the
     * GIF still serves — keeps existing emails from looking broken
     * after the retention cap.
     */
    public function open(Request $request, string $token, MailTracker $tracker): Response
    {
        $message = $token !== ''
            ? MailMessage::query()->where('tracking_token', $token)->first()
            : null;

        if ($message && $tracker->isWithinTtl($message)) {
            MailEvent::record($message->id, MailEventType::Opened, meta: [
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'ip' => $request->ip(),
            ]);
            MailOpened::dispatch($message, $request->userAgent(), $request->ip());
        }

        return $this->pixel();
    }

    /**
     * GET `{prefix}/click/{token}?u={signed-target}`
     *
     * Records a `MailEvent::Clicked` + fires no event class yet (clicks
     * are noisier than opens; consumers that want them can listen to
     * the `MailEvent` model directly). Redirects to the verified target.
     *
     * The target URL is signed via `MailTracker::signId()`/`verifyId()`
     * so a bad-actor can't rewrite the link to point elsewhere.
     */
    public function click(Request $request, string $token, MailTracker $tracker): Response
    {
        $message = $token !== ''
            ? MailMessage::query()->where('tracking_token', $token)->first()
            : null;

        $signed = (string) $request->query('u', '');
        $target = $signed !== '' ? $tracker->verifyId($signed) : null;

        if ($message && $tracker->isWithinTtl($message) && $target) {
            MailEvent::record($message->id, MailEventType::Clicked, meta: [
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'ip' => $request->ip(),
                'url' => $target,
            ]);
        }

        // Defensive fall-back: when verification fails (or no target
        // at all) redirect to the app URL rather than 404, so the
        // recipient still lands somewhere sensible.
        return redirect()->to($target ?? config('app.url'));
    }

    /** 1×1 transparent GIF — 43 bytes, no Cache-Control so each open hits us. */
    protected function pixel(): Response
    {
        $body = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($body, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Length' => strlen($body),
        ]);
    }
}
