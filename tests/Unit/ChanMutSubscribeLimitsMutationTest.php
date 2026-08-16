<?php

use Lightspeed\Channels\SubscribeLimits;

/**
 * What the two configurable limits do with configuration that is wrong.
 *
 * Both limits are read straight out of config and both are the only bound on
 * what an anonymous subscribe can make this worker allocate, so the interesting
 * inputs are not the sensible ones. A limit of zero would refuse every channel
 * name on the server; a limit that arrived as a string from an environment file
 * would flow into an int-typed answer as something that is not a number. The
 * clamp and the cast are what stop either, and neither has a caller that would
 * report it.
 */
test('a limit configured as zero is floored at one rather than refusing everything', function () {
    // max(1, ...) is the floor. Below it every name is too long and no
    // connection may hold a channel, so a typo in an environment file would
    // take the realtime surface down while the process stayed healthy.
    config()->set('lightspeed.channels.max_name_length', 0);
    config()->set('lightspeed.channels.max_per_connection', 0);

    expect(SubscribeLimits::maxChannelNameLength())->toBe(1)
        ->and(SubscribeLimits::maxChannelsPerConnection())->toBe(1);
});

test('a limit configured as one is one, not one more', function () {
    // The other side of the same clamp: the floor may not quietly hand back a
    // limit larger than the operator asked for.
    config()->set('lightspeed.channels.max_name_length', 1);
    config()->set('lightspeed.channels.max_per_connection', 1);

    expect(SubscribeLimits::maxChannelNameLength())->toBe(1)
        ->and(SubscribeLimits::maxChannelsPerConnection())->toBe(1);
});

test('a limit that is not a number at all falls back to the floor instead of throwing', function () {
    // Config values come from the environment, where everything is a string and
    // nothing is validated. Without the int cast the comparison inside max()
    // is a string comparison, which hands a non-numeric string back out of a
    // method declared to return int, and the subscribe path dies with a
    // TypeError on a frame an anonymous client sent.
    config()->set('lightspeed.channels.max_name_length', 'unlimited');
    config()->set('lightspeed.channels.max_per_connection', 'lots');

    expect(SubscribeLimits::maxChannelNameLength())->toBe(1)
        ->and(SubscribeLimits::maxChannelsPerConnection())->toBe(1);
});

test('the shipped defaults are the documented ones', function () {
    // These two numbers are Pusher's, which is what makes enforcing them safe
    // for an application that already works against Pusher, so a change to
    // either is a change to that promise.
    expect(SubscribeLimits::maxChannelNameLength())->toBe(164)
        ->and(SubscribeLimits::maxChannelsPerConnection())->toBe(100);
});

test('with the config keys absent entirely, the built-in defaults answer', function () {
    // The second argument to config() is the last line of defence: a published
    // config file that predates these keys leaves them missing, and the answer
    // then has to come from the call itself rather than from nothing.
    config()->set('lightspeed.channels', []);

    expect(SubscribeLimits::maxChannelNameLength())->toBe(164)
        ->and(SubscribeLimits::maxChannelsPerConnection())->toBe(100);
});
