<?php

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Lightspeed\Auth\GrantManager;
use Lightspeed\Auth\PendingGrants;
use Lightspeed\Auth\RevocationLog;

/**
 * The two verbs an application actually calls, and the limits they refuse at.
 *
 * Both limits reject rather than truncate, and that is the whole point of them
 * being here: the reverted build truncated an over-long tag to fit a mirror
 * row, which minted a grant carrying `project:12` while the application
 * revoked `project:1234...`. Neither call failed and neither worked. So what
 * these tests pin is that a tag which will not work fails where it was
 * written, on the exact byte and the exact count.
 */

/** A revocation log that records rather than writing to Redis. */
class AuthMutRecordingRevocationLog extends RevocationLog
{
    /** @var list<string> every tag handed to revoke(), in order */
    public array $revoked = [];

    public function __construct()
    {
    }

    public function revoke(string $tag): int
    {
        $this->revoked[] = $tag;

        return 1_700_000_000_000_000;
    }
}

function authMutGrantManager(): GrantManager
{
    return new GrantManager(
        app(PendingGrants::class),
        app(AuthMutRecordingRevocationLog::class),
        app(ConfigRepository::class),
    );
}

/** Take a dial out of the config entirely, so the code's own default decides. */
function authMutForgetAuthDial(string $dial): void
{
    $auth = config('lightspeed.auth');
    unset($auth[$dial]);

    config()->set('lightspeed.auth', $auth);
}

beforeEach(function () {
    app()->singleton(AuthMutRecordingRevocationLog::class);
    app(PendingGrants::class)->begin();
});

test('a single tag given as a string is one tag, not a list of its characters', function () {
    expect(authMutGrantManager()->tag('user:42')->tags())->toBe(['user:42']);
});

test('a duplicate numeric tag survives as the string it was written as', function () {
    // Deduplication runs through array keys, where "7" becomes the integer 7.
    // A grant carrying the integer 7 would never match the key `revoke('7')`
    // writes, which is the bug that was found on a live server.
    expect(authMutGrantManager()->tag(['7', '7', 'user:42'])->tags())->toBe(['7', 'user:42']);
});

test('an over-long tag is named in the refusal by its first 32 bytes only', function () {
    // The report has to identify the tag without becoming the tag: the value
    // that provoked this can be kilobytes, and the message goes into whatever
    // the application logs its 500s with.
    $tag = str_repeat('a', 32).str_repeat('b', 400);

    expect(fn () => authMutGrantManager()->tag($tag))
        ->toThrow(InvalidArgumentException::class);

    try {
        authMutGrantManager()->tag($tag);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('['.str_repeat('a', 32).'...]')
            ->and($e->getMessage())->not->toContain('bbb')
            ->and($e->getMessage())->toContain('is 432 bytes');
    }
});

test('the tag count limit refuses at one over, and accepts exactly the limit', function () {
    config()->set('lightspeed.auth.max_tags', 3);

    $three = ['a', 'b', 'c'];

    expect(authMutGrantManager()->tag($three)->tags())->toBe($three)
        ->and(fn () => authMutGrantManager()->tag(['a', 'b', 'c', 'd']))
        ->toThrow(InvalidArgumentException::class, 'over the limit of 3');
});

test('the shipped tag limits are 191 bytes and 16 tags', function () {
    // The defaults live in the code as well as in the published config, and
    // they are what an application that never publishes the file runs on.
    authMutForgetAuthDial('max_tag_length');
    authMutForgetAuthDial('max_tags');

    expect(authMutGrantManager()->tag(str_repeat('a', 191))->tags())->toHaveCount(1)
        ->and(fn () => authMutGrantManager()->tag(str_repeat('a', 192)))
        ->toThrow(InvalidArgumentException::class)
        ->and(authMutGrantManager()->tag(array_map('strval', range(1, 16)))->tags())->toHaveCount(16)
        ->and(fn () => authMutGrantManager()->tag(array_map('strval', range(1, 17))))
        ->toThrow(InvalidArgumentException::class);
});

test('a nonsense tag limit floors to one rather than refusing everything', function () {
    // A dial published as text (an unset env var reaching config as a string,
    // a hand-edited config) must not be compared as text: "abc" is greater
    // than any number when PHP compares strings, so an unfloored limit would
    // either refuse every tag or accept every one.
    config()->set('lightspeed.auth.max_tag_length', 'abc');
    config()->set('lightspeed.auth.max_tags', 'abc');

    expect(authMutGrantManager()->tag('a')->tags())->toBe(['a'])
        ->and(fn () => authMutGrantManager()->tag('ab'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => authMutGrantManager()->tag(['a', 'b']))
        ->toThrow(InvalidArgumentException::class);
});

test('a zero tag limit still admits one tag, because no tags is not mintable', function () {
    config()->set('lightspeed.auth.max_tag_length', 0);
    config()->set('lightspeed.auth.max_tags', 0);

    expect(authMutGrantManager()->tag('a')->tags())->toBe(['a']);
});

test('tag() refuses what could never be revoked', function () {
    expect(fn () => authMutGrantManager()->tag([]))
        ->toThrow(InvalidArgumentException::class, 'at least one tag')
        ->and(fn () => authMutGrantManager()->tag(['']))
        ->toThrow(InvalidArgumentException::class, 'non-empty strings')
        ->and(fn () => authMutGrantManager()->tag([['nested']]))
        ->toThrow(InvalidArgumentException::class, 'non-empty strings');
});

test('revoke passes a plain string tag straight through to the log', function () {
    // Both guards on this path have been wrong in the direction that drops the
    // revocation silently: one refused every string, the other refused every
    // non-empty one. A revoke that does nothing and says nothing is the one
    // failure this feature cannot have.
    $manager = authMutGrantManager();

    $manager->revoke('project:7');
    $manager->revoke(['user:42', '7']);

    expect(app(AuthMutRecordingRevocationLog::class)->revoked)->toBe(['project:7', 'user:42', '7']);
});

test('revoke refuses an empty or non-scalar tag rather than writing one', function () {
    $manager = authMutGrantManager();

    expect(fn () => $manager->revoke(''))
        ->toThrow(InvalidArgumentException::class, 'non-empty string tags')
        ->and(fn () => $manager->revoke([['project:7']]))
        ->toThrow(InvalidArgumentException::class, 'non-empty string tags')
        ->and(app(AuthMutRecordingRevocationLog::class)->revoked)->toBe([]);
});
