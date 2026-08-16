<?php

namespace Lightspeed\Protocol;

/**
 * The two request paths the Pusher protocol reserves, the identifiers they
 * carry, and how they are spelled.
 *
 * Why this file exists: several decisions read these paths. The HTTP router
 * picks the publish endpoint out of the app's own routes, the websocket `open`
 * handler decides whether a socket is a Pusher connection at all, the publish
 * verifier has to confirm the app id in the path is this app's, and the probe
 * commands have to dial the very paths the server will accept. A path grammar
 * that its callers re-derive is a path grammar that drifts, and two of those
 * decisions gate authentication. So the grammar is read *and* written here:
 * anything that needs one of these paths asks for it rather than sprintf-ing
 * its own.
 *
 * Owns: the shape of `{prefix}/{app_key}` and `/apps/{app_id}/events`, in both
 * directions, and what a usable path prefix is (trailing slashes trimmed, empty
 * falls back to DEFAULT_PATH_PREFIX).
 * Deliberately does not own: where the prefix comes from (these are pure
 * functions of their inputs, like everything else in this folder, so the caller
 * holding the configuration passes it in), nor whether the extracted key or id
 * is the *right* one, which belongs to the caller holding the credentials.
 */
class PusherPaths
{
    /** The prefix Pusher and Reverb both use, and the fallback for an empty one. */
    public const DEFAULT_PATH_PREFIX = '/app';

    /**
     * The websocket path a client dials for this app key.
     *
     * Query parameters (`?protocol=7&client=...`) are the client's business and
     * are deliberately not added here; the grammar is the path itself.
     */
    public static function websocketPath(?string $prefix, string $appKey): string
    {
        return static::normalizePrefix($prefix).'/'.$appKey;
    }

    /**
     * The app key a websocket path names, if it names one.
     *
     * Anything that is not this exact shape is not a Pusher connection, which
     * is what routes a socket to the diagnostic protocol's own gate instead.
     */
    public static function appKeyFromWebsocketPath(?string $path, ?string $prefix): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        $pattern = '#^'.preg_quote(static::normalizePrefix($prefix), '#').'/([^/]+)$#';

        if (!preg_match($pattern, $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /** Does this path address the Pusher websocket surface? */
    public static function isWebsocketPath(?string $path, ?string $prefix): bool
    {
        return static::appKeyFromWebsocketPath($path, $prefix) !== null;
    }

    /** The signed publish endpoint for this app id. */
    public static function eventsPath(string $appId): string
    {
        return "/apps/{$appId}/events";
    }

    /** The app id a publish path names, if it names one. */
    public static function appIdFromEventsPath(?string $path): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        if (!preg_match('#^/apps/([^/]+)/events$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /** Does this path address the signed Pusher publish endpoint? */
    public static function isEventsPath(?string $path): bool
    {
        return static::appIdFromEventsPath($path) !== null;
    }

    /**
     * Reduce a configured prefix to the one spelling the grammar uses.
     *
     * A prefix is written by hand into config or an env var, so `/app/` and
     * `/app` have to mean the same thing, and an empty value has to mean the
     * default rather than matching every path.
     */
    private static function normalizePrefix(?string $prefix): string
    {
        $normalized = rtrim((string) $prefix, '/');

        return $normalized === '' ? self::DEFAULT_PATH_PREFIX : $normalized;
    }
}
