<?php

uses(Lightspeed\Tests\TestCase::class)->in(__DIR__);

/**
 * The surface of the server that owns a given private method or property.
 *
 * `Lightspeed\Server` composes the websocket surfaces (the handshake, the frame
 * router, subscriptions, the client-event gate, the two sweepers, the
 * revocation drain, the boot refusals) rather than holding all of them itself.
 * The tests below reach into those internals by name, which is a thing tests
 * should not need to do, but they predate the split and their assertions are
 * about behaviour, not about which object the method lives on. This walks the
 * composed graph so the assertions stay exactly as they were.
 *
 * Breadth-first from the root, so a member the root itself has (the guarded
 * Swoole callbacks) still resolves to the root.
 *
 * @param 'method'|'property' $kind
 */
function lightspeedSurface(object $root, string $member, string $kind = 'method'): object
{
    $has = static fn (object $node): bool => $kind === 'method'
        ? method_exists($node, $member)
        : property_exists($node, $member);

    if ($has($root)) {
        return $root;
    }

    $queue = [$root];
    $seen = [spl_object_id($root) => true];

    while ($queue !== []) {
        $node = array_shift($queue);

        foreach ((new ReflectionObject($node))->getProperties() as $property) {
            $property->setAccessible(true);

            if (!$property->isInitialized($node)) {
                continue;
            }

            $value = $property->getValue($node);

            if (!is_object($value) || !str_starts_with($value::class, 'Lightspeed\\')) {
                continue;
            }

            if (isset($seen[spl_object_id($value)])) {
                continue;
            }

            $seen[spl_object_id($value)] = true;

            if ($has($value)) {
                return $value;
            }

            $queue[] = $value;
        }
    }

    throw new RuntimeException('No Lightspeed surface reachable from ['.$root::class."] has the {$kind} [{$member}].");
}

/** Call a private method on the server, or on whichever surface it lives on. */
function driveLightspeed(object $root, string $method, array $arguments = []): mixed
{
    $target = lightspeedSurface($root, $method);

    $handler = new ReflectionMethod($target::class, $method);
    $handler->setAccessible(true);

    return $handler->invokeArgs($target, $arguments);
}

/** Read a private property of the server, or of whichever surface holds it. */
function readLightspeedProperty(object $root, string $property): mixed
{
    $target = lightspeedSurface($root, $property, 'property');

    $handle = new ReflectionProperty($target::class, $property);
    $handle->setAccessible(true);

    return $handle->getValue($target);
}

/**
 * Say that this server's worker finished starting.
 *
 * `Server::handleOpen()` refuses a websocket upgrade on a worker that did not
 * come up, because a worker whose relay never attached completes the handshake,
 * accepts every subscribe and delivers nothing (see Workers\WorkerHealth). A
 * test that drives handleOpen directly has skipped the workerStart callback, so
 * without this it is asserting against an unready worker and every refusal it
 * observes is that one rather than the one it is about.
 *
 * The one file that must NOT call this is tests/Unit/WorkerStartHealthTest.php,
 * whose subject is precisely what an unready worker does.
 */
function markLightspeedWorkerReady(object $root): void
{
    lightspeedSurface($root, 'markReady')->markReady();
}

/** Write a private property of the server, or of whichever surface holds it. */
function writeLightspeedProperty(object $root, string $property, mixed $value): void
{
    $target = lightspeedSurface($root, $property, 'property');

    $handle = new ReflectionProperty($target::class, $property);
    $handle->setAccessible(true);
    $handle->setValue($target, $value);
}
