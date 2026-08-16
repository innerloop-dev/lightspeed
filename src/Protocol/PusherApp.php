<?php

namespace Lightspeed\Protocol;

/**
 * This server's Pusher identity and the dials that go on the wire with it.
 *
 * Read per call rather than cached, for one reason that applies to all four: a
 * worker restart is the only thing that reloads config, and the server object
 * outlives that restart. Each is one array lookup, on paths that already parse
 * a URL or verify an HMAC.
 */
class PusherApp
{
    public function key(): string
    {
        return (string) config('lightspeed.reverb_compat.app_key', '');
    }

    public function secret(): string
    {
        return (string) config('lightspeed.reverb_compat.app_secret', '');
    }

    /** The path prefix the Pusher websocket endpoint is mounted under. */
    public function pathPrefix(): string
    {
        return (string) config('lightspeed.reverb_compat.path_prefix', PusherPaths::DEFAULT_PATH_PREFIX);
    }

    public function activityTimeout(): int
    {
        return (int) config('lightspeed.reverb_compat.activity_timeout', 30);
    }
}
