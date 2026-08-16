<?php

use Illuminate\Support\Facades\Broadcast;
use Lightspeed\Facades\Lightspeed;

/*
 * Channel authorization. This is the whole gate.
 *
 * Lightspeed accepts a subscription only if it carries a signature that this
 * endpoint produced, so whatever you decide here is what decides who may join a
 * channel and, in turn, who may send client events on it. The array you return
 * from a presence channel becomes the member payload every other member sees,
 * and it is the identity your handlers receive as $event->userId.
 */

Broadcast::channel('lobby', function ($user) {
    // Tagging is optional, and it is what buys per-message re-checking:
    // `Lightspeed::revoke("user:{$id}")` drops that connection out of the
    // fan-out and refuses its next message. Leave it out and this callback
    // behaves exactly as it would without it. See docs/authorization.md.
    Lightspeed::tag(["user:{$user->id}"])
              ->with(['can_shout' => true]);

    return ['id' => $user->id, 'name' => $user->name];
});

/*
 * A private (non-presence) channel, so the Echo page can prove that
 * Echo.private() authorizes through /broadcasting/auth too, not just
 * Echo.join(). Only the owner may listen.
 */

Broadcast::channel('inbox.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
