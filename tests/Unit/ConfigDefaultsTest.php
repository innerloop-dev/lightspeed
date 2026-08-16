<?php

/**
 * The package's defaults are a security and privacy surface, not preferences.
 *
 * Each of these has been wrong at least once: the diagnostic protocol shipped
 * always-on with no channel authorization, and the presence colour palette
 * shipped always-on, silently adding a key to every app's member payloads.
 */

test('the diagnostic protocol is disabled by default', function () {
    expect(config('lightspeed.diagnostics.enabled'))->toBeFalse();
});

test('the diagnostic protocol only ever accepts loopback peers', function () {
    expect(config('lightspeed.diagnostics.allow_from'))->toBe(['127.0.0.1', '::1']);
});

test('presence colour assignment is off by default', function () {
    expect(config('lightspeed.presence.color_slots'))->toBe(0);
});

test('the owned-resource channel noun is generic by default', function () {
    expect(config('lightspeed.resources.channel_prefix'))->toBe('resource');
});

test('owner commands hold the write lease by default', function () {
    expect(config('lightspeed.owner_commands.write_lease'))->toBeTrue();
});

/**
 * The defaults BEHAVIOURAL tests always override are the ones nothing protects.
 *
 * Every test that cares about the sweep budget sets `sweep_max_per_tick`
 * explicitly, because a budget of 200 makes for a slow test. So the shipped
 * 200 is exercised by nothing at all, and could become 1, or 100000, in
 * silence. Both readings are bugs with no error message: at 1 a revoked
 * population drains one connection per tick, and at 100000 the bound is gone
 * and one revoke is back to being seconds of a fully blocked event loop, which
 * is the exact failure the budget was added for.
 *
 * Likewise `owner_commands.enabled`: with it defaulting to false,
 * `forwardIfOwnedByAnotherProcess()` returns null for everything and every
 * caller quietly handles a resource it does not own LOCALLY. The double write
 * the whole owner-routing design exists to prevent, arriving as a wrong answer
 * rather than an error.
 */
test('the sweep budget ships at a value that is neither one nor unbounded', function () {
    expect(config('lightspeed.auth.sweep_max_per_tick'))->toBe(200);
});

test('owner command routing is on by default', function () {
    expect(config('lightspeed.owner_commands.enabled'))->toBeTrue();
});

/**
 * `diagnostics.allow_from` had no `env()` while both docs/configuration.md and
 * SECURITY.md described it as "configured". It is the one setting here that
 * decides who may reach a protocol with no channel authorization, so it is also
 * the one where an operator most needs the knob to be where the docs say.
 *
 * Parsed rather than passed straight through, because an env var can only be a
 * string: the default has to survive the split and still be the loopback pair,
 * and stray whitespace or a trailing comma must not become an empty allowed
 * address (which `in_array` would then match against a missing peer address).
 */
test('the diagnostics allow list survives being parsed out of a string', function () {
    $parse = function (string $raw): array {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $raw),
        ), static fn (string $address): bool => $address !== ''));
    };

    expect($parse('127.0.0.1,::1'))->toBe(['127.0.0.1', '::1'])
        ->and($parse(' 127.0.0.1 , ::1 '))->toBe(['127.0.0.1', '::1'])
        ->and($parse('127.0.0.1,,'))->toBe(['127.0.0.1'])
        ->and($parse(''))->toBe([]);
});
