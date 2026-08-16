<?php

use Lightspeed\Channels\ChannelName;

/**
 * The two answers ChannelName gives, pinned at their edges.
 *
 * Everything about this class is a boundary: the length it refuses at, the
 * floor under a misconfigured limit, and how much of a refused name is allowed
 * back out into a log line. Each of those is one integer or one comparison away
 * from being useless, and none of them has a caller that would notice. So the
 * tests below are written at the exact character where the answer changes.
 */
test('the length floor holds a limit of zero at one, and a limit of one refuses two characters', function () {
    // max(1, $maxLength) is the floor, and it is load-bearing in both
    // directions: a limit that fell to zero would refuse every name a
    // misconfigured server was ever asked for, and a floor one higher than it
    // says would accept a name past the limit an operator set.
    expect(ChannelName::rejection('a', 0))->toBeNull()
        ->and(ChannelName::rejection('ab', 1))->toBe('channel-name-too-long')
        ->and(ChannelName::rejection('a', 1))->toBeNull();
});

test('an ordinary name is accepted, and only the empty one is refused as a length', function () {
    // The first clause of the length guard is an equality against the empty
    // string. Inverted, every legitimate channel name on the server becomes
    // too long, which is the whole subscribe surface refusing itself.
    expect(ChannelName::rejection('presence-room.42'))->toBeNull()
        ->and(ChannelName::rejection(''))->toBe('channel-name-too-long');
});

test('a name at the display limit is shown whole and one character more is cut', function () {
    // The comparison decides whether a name is repeated verbatim or truncated,
    // so it is asserted at the character where it flips rather than somewhere
    // safely either side of it.
    $atLimit = str_repeat('a', 32);

    expect(ChannelName::forDisplay($atLimit))->toBe($atLimit)
        ->and(ChannelName::forDisplay($atLimit.'b'))->toBe($atLimit.'...');
});

test('a truncated name is the first characters of the original, with the ellipsis after them', function () {
    // Distinct characters throughout, because a name of repeated bytes cannot
    // tell "the first 32 characters" from "32 characters starting at the
    // second", and the two differ by an off-by-one in the offset.
    $name = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    expect(ChannelName::forDisplay($name))->toBe('abcdefghijklmnopqrstuvwxyz012345...')
        ->and(strlen(ChannelName::forDisplay($name)))->toBe(35);
});

test('a huge name shrinks to the display limit rather than being echoed at length', function () {
    // The reason the truncation exists: what comes back is bounded by the
    // limit, not by the input, so a refused megabyte cannot be turned into a
    // megabyte on the server's disk.
    expect(strlen(ChannelName::forDisplay(str_repeat('z', 64 * 1024))))->toBe(35);
});
