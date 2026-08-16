<?php

namespace Lightspeed\Diagnostics;

/**
 * Who may speak the diagnostic protocol.
 *
 * Two protocols share the websocket port and they are never mixed. Eligibility
 * is decided ONCE, at connection open, and never re-derived per frame: a client
 * that completed the Pusher handshake must never reach the unauthorized
 * diagnostic verbs by changing its frame shape mid-stream. Swoole delivers
 * every frame for a connection to the worker that owns it, so this per-worker
 * map covers the connection's whole life.
 */
class DiagnosticSockets
{
    /**
     * File descriptors allowed to speak the diagnostic protocol.
     *
     * @var array<int, true>
     */
    private array $diagnosticFds = [];

    public function admit(int $fd): void
    {
        $this->diagnosticFds[$fd] = true;
    }

    public function allows(int $fd): bool
    {
        return isset($this->diagnosticFds[$fd]);
    }

    public function forget(int $fd): void
    {
        unset($this->diagnosticFds[$fd]);
    }

    /**
     * Is the diagnostic protocol available to this peer?
     *
     * Three conditions, all required: the operator opted in, the peer address
     * is loopback, and the request did not arrive through a proxy.
     *
     * The proxy check matters because a loopback address is only evidence of a
     * local caller when nothing is forwarding on someone else's behalf. A
     * reverse proxy in front of this server connects from 127.0.0.1 for every
     * request it relays, including ones from the public internet, so peer
     * address alone would hand an anonymous client a protocol that performs no
     * channel authorization. Forwarding headers are the proxy announcing
     * itself; a genuinely local probe never sends them.
     *
     * This is defence in depth, not the whole defence: the deployment must not
     * route this path to the server at all, which is why the shipped nginx
     * example proxies only the Pusher path.
     */
    /**
     * Why this peer may not speak the diagnostic protocol, or null if it may.
     *
     * The three refusals are different situations for the operator reading
     * the log: 'diagnostics-disabled' is the safe default doing its job,
     * 'address-not-allowed' may be a probe pointed at the wrong box, and
     * 'proxied-request' can be a routing mistake exposing this path through a
     * reverse proxy. One label for all three hides the one that matters.
     */
    public function refusalReason(string $remoteAddress, array $headers = []): ?string
    {
        if (!(bool) config('lightspeed.diagnostics.enabled', false)) {
            return 'diagnostics-disabled';
        }

        $allowed = (array) config('lightspeed.diagnostics.allow_from', ['127.0.0.1', '::1']);

        if (!in_array($remoteAddress, $allowed, true)) {
            return 'address-not-allowed';
        }

        foreach (['x-forwarded-for', 'x-real-ip', 'forwarded', 'x-forwarded-host'] as $header) {
            if (($headers[$header] ?? null) !== null) {
                return 'proxied-request';
            }
        }

        return null;
    }
}
