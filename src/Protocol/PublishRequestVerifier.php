<?php

namespace Lightspeed\Protocol;

/**
 * The gate on the server-to-server publish endpoint, `POST /apps/{id}/events`.
 *
 * Why this file exists: this endpoint can push any event onto any channel, so
 * its signature check is the only thing standing between an unauthenticated
 * caller and every connected client. The check is a pure function of the
 * request line, the query string and the raw body (nothing about it needs a
 * socket), so it belongs somewhere it can be exercised with plain arrays and
 * proven to reject a tampered body, a wrong secret and a wrong path.
 *
 * The signed string is Pusher's, and each line matters:
 *
 *   POST\n/apps/{id}/events\nauth_key=..&auth_timestamp=..&auth_version=..&body_md5=..
 *
 * with `auth_signature` removed and the remaining parameters sorted. body_md5
 * is what binds the signature to the payload: without it the signature covers
 * only the query string, and any body could ride on a captured one. It is
 * therefore checked against the body *as it arrived on the wire*, which is why
 * decodeRequestBody() exists; see its docblock.
 *
 * Owns: the signing string, the app id / app key / body_md5 preconditions, the
 * constant-time signature comparison, and the raw-body reconstruction the
 * comparison depends on.
 * Deliberately does not own: transport (it never sees a Swoole request), what
 * a valid publish then does (Server fans it out), or the HTTP status a failure
 * is reported with.
 */
class PublishRequestVerifier
{
    /**
     * How far `auth_timestamp` may be from now, in seconds.
     *
     * Pusher's own window, which is what every Pusher server SDK already signs
     * against, so nothing that worked before this check existed stops working.
     *
     * SYMMETRIC, because clock skew is. A publisher ten minutes fast is as
     * broken as one ten minutes slow, and accepting the future half silently
     * would leave a replay window that opens later rather than closing.
     */
    private const AUTH_TIMESTAMP_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly string $appId,
        private readonly string $appKey,
        private readonly string $appSecret,
    ) {
    }

    /**
     * Is this publish request signed by the holder of the app secret?
     *
     * @param  string  $method  HTTP verb, as it appears in the signed string
     * @param  string  $path  request path, e.g. /apps/{id}/events
     * @param  array  $query  decoded query parameters, including auth_signature
     * @param  string  $body  the request body exactly as it arrived on the wire
     * @param  ?int  $nowSeconds  the instant to judge `auth_timestamp` against;
     *                            defaults to this process's clock
     */
    public function verify(string $method, string $path, array $query, string $body, ?int $nowSeconds = null): bool
    {
        $authKey = $query['auth_key'] ?? null;
        $signature = $query['auth_signature'] ?? null;
        $bodyMd5 = $query['body_md5'] ?? null;
        $appId = PusherPaths::appIdFromEventsPath($path);

        if ($authKey !== $this->appKey) {
            return false;
        }

        if ($appId !== $this->appId) {
            return false;
        }

        if (!is_string($signature) || $signature === '') {
            return false;
        }

        if (!is_string($bodyMd5) || $bodyMd5 !== md5($body)) {
            return false;
        }

        // `auth_timestamp` was signed and never compared to anything, which
        // made a captured request a PERMANENT credential: the signature covers
        // the timestamp, so age never invalidated it, and this endpoint can
        // push any event onto any channel. Anyone who ever saw one publish URL
        // (a proxy log, a mirrored packet, a bug report) could replay it for
        // as long as the app secret stayed the same.
        if (!$this->isFresh($query['auth_timestamp'] ?? null, $nowSeconds ?? time())) {
            return false;
        }

        unset($query['auth_signature']);
        ksort($query);

        $stringToSign = "{$method}\n{$path}\n".static::implodeQueryParams($query);
        $expected = hash_hmac('sha256', $stringToSign, $this->appSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Is this request's claimed instant close enough to now to be believed?
     *
     * A missing or unparseable timestamp is refused rather than skipped: the
     * field is part of the signed string every Pusher SDK produces, so its
     * absence is not a legacy caller, it is a caller this server did not sign
     * for, and treating "no timestamp" as "no expiry" is exactly the hole.
     */
    private function isFresh(mixed $timestamp, int $nowSeconds): bool
    {
        if (!is_numeric($timestamp)) {
            return false;
        }

        return abs($nowSeconds - (int) $timestamp) <= self::AUTH_TIMESTAMP_WINDOW_SECONDS;
    }

    /**
     * Preserve the original wire body for body_md5 validation.
     *
     * When Swoole has already decoded form fields into `$request->post`, rebuild
     * the form-encoded body for signature checks but still return the parsed
     * payload array so the events path can proceed without a second decode step.
     *
     * @return array{signatureBody:string,payload:?array}
     */
    public static function decodeRequestBody(string $rawBody, array $post): array
    {
        if ($rawBody !== '') {
            try {
                $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [
                    'signatureBody' => $rawBody,
                    'payload' => null,
                ];
            }

            return [
                'signatureBody' => $rawBody,
                'payload' => is_array($payload) ? $payload : null,
            ];
        }

        if ($post === []) {
            return [
                'signatureBody' => '',
                'payload' => null,
            ];
        }

        return [
            'signatureBody' => http_build_query($post, '', '&', PHP_QUERY_RFC3986),
            'payload' => $post,
        ];
    }

    /**
     * Join query parameters the way Pusher signs them: raw, unencoded, in the
     * order the sort left them. http_build_query() is deliberately not used:
     * it percent-encodes, and the signature the Pusher SDKs produce is over
     * the unencoded pairs.
     */
    private static function implodeQueryParams(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }

            $pairs[] = "{$key}={$value}";
        }

        return implode('&', $pairs);
    }
}
