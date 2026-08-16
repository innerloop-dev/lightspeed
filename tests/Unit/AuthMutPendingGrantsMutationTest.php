<?php

use Lightspeed\Auth\PendingGrant;
use Lightspeed\Auth\PendingGrants;

/**
 * The slot a grant sits in between `Lightspeed::tag()` and the broadcaster
 * signing it, and who is allowed to see it.
 *
 * Every bug this class has had was one request reading another's slot, and
 * every one of them was silent and in the direction that grants access. So the
 * property below is about the boundary rather than about the walk: a grant
 * described inside a coroutine belongs to that coroutine's request, and the
 * sequential path (no coroutine at all, one request at a time by construction)
 * is a different occupant of a different slot, not the same one.
 */

test('a grant described inside a coroutine is not waiting for the next caller outside it', function () {
    $pending = new PendingGrants();

    \Co\run(function () use ($pending) {
        $pending->put(new PendingGrant(['project:7']));
    });

    // The coroutine has gone, and with it the request it stood for. A take()
    // on the sequential path is a DIFFERENT request asking for its own grant,
    // and handing it this one signs one request's permissions onto another's
    // socket, which is the whole failure this class exists to prevent.
    expect($pending->take())->toBeNull();
});

test('a marked request keeps its own slot while an unmarked sibling runs', function () {
    $pending = new PendingGrants();

    $taken = [];

    \Co\run(function () use ($pending, &$taken) {
        \Swoole\Coroutine::create(function () use ($pending, &$taken) {
            $pending->begin();
            $pending->put(new PendingGrant(['marked']));

            \Swoole\Coroutine::sleep(0.01);

            $taken['marked'] = $pending->take()?->tags();
        });

        \Swoole\Coroutine::create(function () use ($pending, &$taken) {
            $pending->put(new PendingGrant(['unmarked']));

            \Swoole\Coroutine::sleep(0.01);

            $taken['unmarked'] = $pending->take()?->tags();
        });
    });

    expect($taken['marked'])->toBe(['marked'])
        ->and($taken['unmarked'])->toBe(['unmarked']);
});
