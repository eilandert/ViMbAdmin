<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Security;

/**
 * Per-response Content-Security-Policy for dynamic HTML, carrying a fresh
 * script nonce.
 *
 * The shipped Angie configuration used to serve one static CSP for every
 * response, and because the templates carry inline `<script>` blocks that policy
 * had to allow `script-src 'self' 'unsafe-inline'` — which disables exactly the
 * script-injection protection CSP exists to provide. A nonce cannot come from
 * the web server: the value has to reach the `.phtml` views so each inline block
 * can stamp `nonce="…"`, and nginx/Angie variables (`$request_id`) are not
 * visible there. So the dynamic-HTML policy is emitted by the application
 * instead, with a nonce minted per response.
 *
 * The nonce is 128 bits of `random_bytes()` in base64, generated once per
 * instance and never reused across responses. `script-src` therefore admits only
 * same-origin script files and the inline blocks this very response stamped;
 * `style-src` deliberately keeps `'unsafe-inline'` — the stylesheet surface is a
 * separate change.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class ContentSecurityPolicy
{
    public const HEADER = 'Content-Security-Policy';

    private readonly string $nonce;

    public function __construct(?string $nonce = null)
    {
        $this->nonce = $nonce ?? base64_encode(random_bytes(16));
    }

    /** The raw nonce value, for stamping onto inline `<script>` tags. */
    public function nonce(): string
    {
        return $this->nonce;
    }

    /**
     * The full policy for a dynamic HTML response. Mirrors the directives the
     * Angie configuration serves for static assets, except that `script-src`
     * trades `'unsafe-inline'` for this response's nonce.
     */
    public function header(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $this->nonce . "'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    /** @return array<string,string> the response header map for this policy */
    public function headers(): array
    {
        return [self::HEADER => $this->header()];
    }
}
