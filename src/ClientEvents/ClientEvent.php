<?php

namespace Lightspeed\ClientEvents;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Immutable package-level representation of an inbound websocket client event.
 *
 * The server translates the wire frame into this DTO before handing it to the
 * configured application handlers. Keeping the package contract small makes it
 * easier for apps to plug in their own policy without coupling handlers to the
 * transport internals.
 *
 * Identity: the event carries what the socket actually proved. A client event
 * can only arrive on a channel this socket already subscribed to, and
 * subscribing runs the application's own channel authorization, so `userId` on
 * a presence channel is an identity the application itself approved rather than
 * something the client asserted in this frame. The Pusher protocol carries no
 * user payload on private channels, so `userId` is null there and
 * `connectionContext` (the handshake's headers and cookies) is what an app
 * resolves its own session from.
 *
 * About `fd`: it is the raw Swoole file descriptor of the connection the frame
 * arrived on. It is part of this contract because it is the key the package's
 * own per-connection lookups take: `ChannelManager::presenceMember($fd,
 * $channel)` and `ChannelManager::connectionContext($fd)`. That is how a
 * handler asks "who is this, on that channel?" beyond the fields carried here.
 *
 * Safe to use it for: those lookups, and correlating log lines within one
 * worker while the connection is open.
 *
 * It is not an identity and it must not be stored or sent anywhere. An fd is
 * meaningless outside the worker process that owns the connection (every
 * worker numbers its own), and the numbers are small and reused, so an fd kept
 * past a disconnect will eventually name a different client. Anything that has
 * to outlive the connection or cross a process boundary uses `socketId`, which
 * is unique and is what the rest of the protocol speaks.
 *
 * About `auth`: it is the opaque payload the application attached with
 * `Lightspeed::tag(...)->with([...])` when this connection's grant was signed,
 * carried here byte for byte. The package never reads a key of it.
 *
 * It is null when the application signed no grant for this channel, which also
 * means nothing was re-checked before this event ran. A handler that relies on
 * the payload must treat null as "no", never as "no restrictions". Unlike the
 * design this replaces, null here can only mean the application chose not to
 * tag that channel: a grant that was tampered with, or that has expired,
 * refuses the SUBSCRIPTION outright, so it can never turn up as a missing
 * payload on a connection that is already subscribed.
 */
class ClientEvent
{
    public function __construct(
        public readonly int $fd,
        public readonly string $channel,
        public readonly string $event,
        public readonly mixed $data,
        public readonly ?string $socketId,
        public readonly ?string $userId = null,
        public readonly ?array $userInfo = null,
        public readonly array $connectionContext = [],
        public readonly ?array $auth = null,
    ) {
    }

    private bool $userResolved = false;

    private ?Authenticatable $resolvedUser = null;

    /**
     * The application's own user for this event's identity, or null.
     *
     * Calling this is the opt-in and it costs one user provider lookup. The
     * package never calls it: an event whose handlers ignore it does not touch
     * the provider at all, which is what keeps "no database on the message
     * path" true of the events that do not ask.
     *
     * The answer is memoized for the life of the event, misses included, so a
     * handler that asks repeatedly still pays one lookup. That cache is the
     * only mutable state on this object; every carried field stays readonly.
     *
     * `userId` exists on presence channels, where subscribing already ran the
     * application's own channel authorization. On private channels it is null
     * and this returns null without asking the provider anything. Null means
     * "no", never "no restrictions": it is equally the answer for an event
     * with no identity and for an identity the provider no longer has, so a
     * handler that gates on the user must refuse on null rather than fall
     * through.
     *
     * Resolution goes through the provider the application's default guard is
     * configured with, the same one session auth uses, resolved at call time
     * rather than at construction.
     */
    public function user(): ?Authenticatable
    {
        if ($this->userResolved) {
            return $this->resolvedUser;
        }

        if ($this->userId !== null) {
            $guard = config('auth.defaults.guard');

            $this->resolvedUser = Auth::createUserProvider(
                config("auth.guards.{$guard}.provider"),
            )?->retrieveById($this->userId);
        }

        $this->userResolved = true;

        return $this->resolvedUser;
    }
}
