<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;

/**
 * The two indexes a worker keeps over its own connections, and the ways they
 * can drift apart.
 *
 * `connectionsCarrying()` is what a revoke walks to take connections out of the
 * fan-out, and it is derived state: every write goes through mint() or
 * forget(). Drift in one direction leaves a revoked connection receiving, and
 * in the other drops a subscription the application has just re-approved. Both
 * are silent, which is why they are pinned here rather than left to the paths
 * that read them.
 */

/** Read one of the class's private indexes, to prove nothing is left behind. */
function authMutGrantsIndex(ConnectionGrants $grants, string $property): array
{
    $reflection = new ReflectionProperty(ConnectionGrants::class, $property);
    $reflection->setAccessible(true);

    return $reflection->getValue($grants);
}

function authMutGrant(array $tags, int $issuedAt, ?int $expiresAt = null): Grant
{
    return new Grant($tags, [], $issuedAt, $expiresAt ?? $issuedAt + 300_000_000);
}

test('a re-subscribe stops the tags it replaced from reaching the connection', function () {
    // The application dropped `project:7` from the grant on the second
    // authorization, so a revoke of it must no longer find this connection:
    // dropping it would unsubscribe a user whose access was just re-approved.
    $grants = new ConnectionGrants();

    $grants->mint(7, 'private-doc', authMutGrant(['project:7'], 1_000));
    $grants->mint(7, 'private-doc', authMutGrant(['project:9'], 2_000));

    expect($grants->connectionsCarrying('project:7', 9_000))->toBe([])
        ->and($grants->connectionsCarrying('project:9', 9_000))->toBe([[7, 'private-doc']]);
});

test('a numeric channel name comes back out of both indexes as a string', function () {
    // PHP coerces the array key "7" to the integer 7, and the callers of these
    // two methods hand what they get straight to dropSubscription(), which
    // names the channel in a Redis key and in the frame the client is told to
    // re-authorize with.
    $grants = new ConnectionGrants();

    $grants->mint(7, '7', authMutGrant(['project:7'], 1_000, 2_000));

    expect($grants->connectionsCarrying('project:7', 9_000))->toBe([[7, '7']])
        ->and($grants->expiredBefore(9_000))->toBe([[7, '7']]);
});

test('a grant issued on the same instant as the revoke is dropped', function () {
    // The boundary is the whole comparison: a grant issued at the revocation's
    // own microsecond is one the revoke was meant to catch, and only a grant
    // issued strictly after it belongs to a fresh authorization.
    $grants = new ConnectionGrants();

    $grants->mint(7, 'private-doc', authMutGrant(['project:7'], 5_000));

    expect($grants->connectionsCarrying('project:7', 5_000))->toBe([[7, 'private-doc']])
        ->and($grants->connectionsCarrying('project:7', 4_999))->toBe([]);
});

test('forgetting one channel leaves the connection its other channels', function () {
    // A socket holds several subscriptions and they are judged one at a time.
    // Unwinding the tidy-up too eagerly takes the others with it, and their
    // clients are never told, because nothing decided anything about them.
    $grants = new ConnectionGrants();

    $grants->mint(7, 'private-a', authMutGrant(['project:7'], 1_000));
    $grants->mint(7, 'private-b', authMutGrant(['project:7'], 1_000));

    $grants->forget(7, 'private-a');

    expect($grants->connectionsCarrying('project:7', 9_000))->toBe([[7, 'private-b']])
        ->and($grants->grantFor(7, 'private-b'))->not->toBeNull()
        ->and($grants->grantFor(7, 'private-a'))->toBeNull();
});

test('a closed connection leaves nothing behind in either index', function () {
    // The tag index is keyed by tag and then by fd, and nothing rebuilds it.
    // An entry that survives the connection is a leak that grows for the life
    // of the worker, and a key that a later fd with the same number inherits.
    $grants = new ConnectionGrants();

    $grants->mint(7, 'private-a', authMutGrant(['project:7', 'user:42'], 1_000));
    $grants->mint(7, 'private-b', authMutGrant(['project:7'], 1_000));
    $grants->mint(8, 'private-a', authMutGrant(['project:7'], 1_000));

    $grants->forgetConnection(7);

    expect($grants->connectionsCarrying('project:7', 9_000))->toBe([[8, 'private-a']])
        ->and($grants->connectionsCarrying('user:42', 9_000))->toBe([]);

    $grants->forgetConnection(8);

    expect(authMutGrantsIndex($grants, 'grants'))->toBe([])
        ->and(authMutGrantsIndex($grants, 'byTag'))->toBe([]);
});

test('forgetting the last subscription leaves nothing behind either', function () {
    // Unsubscribing is the ordinary path, and a connection that subscribes and
    // unsubscribes all day must not leave one row per fd behind it. Nothing
    // ever prunes these two arrays apart from these two methods.
    $grants = new ConnectionGrants();

    $grants->mint(7, 'private-a', authMutGrant(['project:7'], 1_000));
    $grants->mint(7, 'private-b', authMutGrant(['project:7'], 1_000));

    $grants->forget(7, 'private-a');
    $grants->forget(7, 'private-b');

    expect(authMutGrantsIndex($grants, 'grants'))->toBe([])
        ->and(authMutGrantsIndex($grants, 'byTag'))->toBe([]);
});

test('a numeric channel name is forgotten as thoroughly as any other', function () {
    // forgetConnection() walks the channel KEYS, which is where "7" has
    // already become the integer 7. A channel that is not forgotten leaves its
    // tag pointing at a connection that has gone.
    $grants = new ConnectionGrants();

    $grants->mint(7, '7', authMutGrant(['project:7'], 1_000));
    $grants->forgetConnection(7);

    expect(authMutGrantsIndex($grants, 'grants'))->toBe([])
        ->and(authMutGrantsIndex($grants, 'byTag'))->toBe([]);
});

test('the grants of one connection come back whole, keyed by channel', function () {
    // What the close path reads to tell the application what the connection was
    // carrying. Every grant, in the order the connection earned them, so a
    // caller reading tags across channels sees all of them.
    //
    // A numeric channel name comes back as an INTEGER key, because PHP array
    // keys have no other option and a cast would be undone by the assignment
    // that applied it. Pinned rather than hidden: a caller that keys off a
    // channel name has to know.
    $grants = new ConnectionGrants();

    $first = authMutGrant(['project:7'], 1_000);
    $second = authMutGrant(['user:42'], 2_000);

    $grants->mint(7, '7', $first);
    $grants->mint(7, 'private-a', $second);

    $found = $grants->grantsFor(7);

    expect(array_keys($found))->toBe([7, 'private-a'])
        ->and($found[7])->toBe($first)
        ->and($found['private-a'])->toBe($second)
        // A connection that carries nothing is an empty list, never a null the
        // caller has to test for.
        ->and($grants->grantsFor(8))->toBe([]);
});
