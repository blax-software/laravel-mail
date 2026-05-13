<?php

declare(strict_types=1);

namespace Blax\Mail\Services;

use Blax\Mail\Models\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Tracking-pixel + click-rewrite helpers.
 *
 * The pixel URL contains the message's `tracking_token` (a random 32-
 * char string generated at dispatch). The tracking controller looks
 * the token up to find the `MailMessage`, then fires `MailOpened`.
 *
 * Tokens (not encrypted ids) so the URL is short and the token can
 * later be rotated / revoked by clearing the column without leaking
 * the encryption-key relationship. The token has no semantic meaning
 * beyond "find the row".
 *
 * `injectPixel` follows the learn-atc convention: try to find an
 * existing logo `<img id="logo">` and append the tracking param to
 * its `src`. Falls back to a hidden 1×1 pixel before `</body>`. This
 * keeps existing mail templates working without modification — they
 * already render a logo and gain tracking "for free".
 */
class MailTracker
{
    /**
     * Random unique token for a new outbound mail. 32 url-safe chars,
     * which is plenty of entropy for a unique-indexed column.
     */
    public function generateToken(): string
    {
        return (string) Str::random(32);
    }

    /**
     * Build the absolute URL the pixel `src` should hit. The package's
     * route group is mounted under `tracking.route_prefix` from config.
     */
    public function pixelUrl(string $token): string
    {
        $prefix = trim((string) config('blax-mail.tracking.route_prefix', 'blax-mail/track'), '/');
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/'.$prefix.'/open/'.$token.'.gif';
    }

    /**
     * Rewrite an outbound HTML body so opening it pings the tracking
     * endpoint. The strategy mirrors learn-atc's: prefer to attach the
     * token to an existing logo `<img id="logo">` (one fewer request),
     * fall back to a hidden 1×1 pixel.
     *
     * Returns the rewritten body. Safe to call repeatedly — the
     * fallback only injects when no `?<key>=` is already present.
     */
    public function injectPixel(string $body, string $token, string $key = 'm'): string
    {
        if (! config('blax-mail.tracking.enabled', true)) {
            return $body;
        }

        $url = $this->pixelUrl($token);
        $replaced = false;

        // Try to find `<img id="logo">` and append the tracking token
        // to its `src`. One fewer image request than a separate pixel.
        $body = preg_replace_callback(
            '/(<img[^>]+id=["\']logo["\'][^>]*>)/',
            function ($matches) use ($key, $token, &$replaced) {
                $tag = $matches[0];
                if (preg_match('/src=["\']([^"\']+)["\']/', $tag, $srcMatches)) {
                    $src = $srcMatches[1];
                    $separator = str_contains($src, '?') ? '&' : '?';
                    $newSrc = $src.$separator.$key.'='.$token;
                    $replaced = true;

                    return preg_replace('/src=["\'][^"\']+["\']/', 'src="'.$newSrc.'"', $tag);
                }

                return $tag;
            },
            $body,
        );

        if ($replaced) {
            return $body;
        }

        // Fallback: hidden 1×1 pixel right before `</body>`. If the
        // body has no `</body>` tag at all we append at the end —
        // some mail clients still render the pixel even without the
        // surrounding structure.
        [$w, $h] = config('blax-mail.tracking.pixel_dimensions', [1, 1]);
        $pixel = sprintf(
            '<img src="%s" width="%d" height="%d" alt="" style="display:block;width:%dpx;height:%dpx;border:0;" />',
            e($url),
            $w,
            $h,
            $w,
            $h,
        );

        if (str_contains($body, '</body>')) {
            return str_replace('</body>', $pixel.'</body>', $body);
        }

        return $body.$pixel;
    }

    /**
     * Encrypt a `MailMessage` id into a short, opaque token for
     * single-shot URLs (a "confirm subscription" link, a one-click
     * unsubscribe). Different from `generateToken` — that one is the
     * persistent open-tracking token; this is a stateless signed payload.
     *
     * The host application doesn't normally call this — `MailTracker`
     * uses it internally for click-rewrite URLs. Exposed because
     * consumers building custom links sometimes need the same encoding.
     */
    public function signId(string $id): string
    {
        return Crypt::encryptString($id);
    }

    /** Reverse of `signId`. Returns null on tampered/expired payloads. */
    public function verifyId(string $token): ?string
    {
        try {
            return Crypt::decryptString($token);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Has the open-tracking window for this row expired? Used by the
     * tracking controller to short-circuit event logging without
     * declining to serve the pixel.
     */
    public function isWithinTtl(MailMessage $message): bool
    {
        $ttlDays = (int) config('blax-mail.tracking.token_ttl_days', 90);
        if ($ttlDays <= 0) {
            return true;
        }
        $reference = $message->sent_at ?? $message->created_at;
        if (! $reference) {
            return true;
        }

        return $reference->copy()->addDays($ttlDays)->isFuture();
    }
}
