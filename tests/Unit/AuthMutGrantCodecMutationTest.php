<?php

use Lightspeed\Auth\Grant;

/**
 * The credential itself: what a grant looks like on the wire, and what
 * decode() refuses to rebuild from.
 *
 * A grant is not stored anywhere. It rides inside the auth string the client
 * echoes back, so every property this file pins is a property of the only copy
 * that exists: it must survive the trip through JSON, base64 and a URL without
 * a tag changing shape, and anything that cannot be read back exactly must be
 * refused rather than guessed at. A grant that decodes into something slightly
 * different from what was signed is a connection carrying permissions nobody
 * granted it.
 */

/** The JSON a grant actually carries, read back out of its encoded form. */
function authMutGrantJson(Grant $grant): string
{
    return (string) base64_decode(strtr($grant->encode(), '-_', '+/'), true);
}

/** An encoded grant built from raw JSON, the way a decode caller receives one. */
function authMutEncodeJson(string $json): string
{
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

/** Valid grant JSON with one member replaced or removed, for the refusal cases. */
function authMutGrantJsonWith(array $members): string
{
    return (string) json_encode(array_merge(
        ['t' => ['user:42'], 'p' => ['seat' => 1], 'i' => 1700000000000000, 'e' => 1700000300000000],
        $members,
    ));
}

test('tags encode as a JSON list whatever keys the grant was built with', function () {
    // A map would come back from json_decode with "7" coerced to the integer
    // 7, which is the bug that made revoke() a permanent no-op for numeric
    // tags on a live server. array_values() is what stops the encoder ever
    // writing an object here.
    $grant = new Grant([3 => 'user:42', 9 => 'project:7'], [], 1700000000000000, 1700000300000000);

    expect(authMutGrantJson($grant))->toContain('"t":["user:42","project:7"]');
});

test('an empty payload encodes as an object, not as an empty list', function () {
    // The payload is the application's and its shape is carried untouched;
    // an empty PHP array encodes as `[]` unless it is cast, which would hand
    // every handler a list where it was promised a map.
    $grant = new Grant(['user:42'], [], 1700000000000000, 1700000300000000);

    expect(authMutGrantJson($grant))->toContain('"p":{}');
});

test('the encoded form is base64url with no padding and no escaped slashes', function () {
    // Both properties are about surviving the trip: `=`, `+` and `/` are all
    // re-encoded or split on somewhere between here and the client, and the
    // encoded grant is one field of a `key:signature:grant` string.
    $grant = new Grant(['user:42'], ['path' => 'a/bc'], 1700000000000000, 1700000300000000);

    $encoded = $grant->encode();

    expect($encoded)->not->toContain('=')
        ->and($encoded)->not->toContain('+')
        ->and($encoded)->not->toContain('/')
        ->and(authMutGrantJson($grant))->toContain('"path":"a/bc"');
});

test('an encoded grant decodes back into the same grant', function () {
    $grant = new Grant(['user:42', 'project:7'], ['seat' => 1], 1700000000000000, 1700000300000000);

    $decoded = Grant::decode($grant->encode());

    expect($decoded)->not->toBeNull()
        ->and($decoded->tags)->toBe(['user:42', 'project:7'])
        ->and($decoded->payload)->toBe(['seat' => 1])
        ->and($decoded->issuedAt)->toBe(1700000000000000)
        ->and($decoded->expiresAt)->toBe(1700000300000000);
});

test('a grant that is not exactly base64url is refused rather than repaired', function () {
    // base64_decode's non-strict mode SKIPS characters outside the alphabet,
    // so a grant with junk spliced into it decodes to a perfectly valid one
    // and verifies. Strict mode is what makes the encoded grant a single
    // canonical string rather than a family of strings.
    $encoded = (new Grant(['user:42'], [], 1700000000000000, 1700000300000000))->encode();

    expect(Grant::decode('!'.$encoded))->toBeNull()
        ->and(Grant::decode(''))->toBeNull()
        ->and(Grant::decode('not base64 at all !!'))->toBeNull();
});

test('a grant missing either timestamp is refused, not read as zero', function () {
    // Both have to be numbers. A grant whose expiry decoded to 0 would be
    // refused everywhere, but one whose issuedAt decoded to 0 predates every
    // revocation ever written, which is a connection nothing can revoke.
    expect(Grant::decode(authMutEncodeJson(authMutGrantJsonWith(['e' => null]))))->toBeNull()
        ->and(Grant::decode(authMutEncodeJson(authMutGrantJsonWith(['i' => null]))))->toBeNull()
        ->and(Grant::decode(authMutEncodeJson(authMutGrantJsonWith(['i' => 'soon']))))->toBeNull();
});

test('a numeric tag decodes back as the string it was signed as', function () {
    // `revoke('7')` writes the key for the string "7". A grant holding the
    // integer 7 would never match it.
    $decoded = Grant::decode(authMutEncodeJson(authMutGrantJsonWith(['t' => [7]])));

    expect($decoded)->not->toBeNull()
        ->and($decoded->tags)->toBe(['7']);
});

test('a grant carrying an empty tag is refused', function () {
    // An empty tag is not revocable by anything, so accepting one is a
    // connection that claims to be re-checked and never is.
    expect(Grant::decode(authMutEncodeJson(authMutGrantJsonWith(['t' => ['']]))))->toBeNull()
        ->and(Grant::decode(authMutEncodeJson(authMutGrantJsonWith(['t' => ['user:42', '']]))))->toBeNull()
        ->and(Grant::decode(authMutEncodeJson(authMutGrantJsonWith(['t' => []]))))->toBeNull()
        ->and(Grant::decode(authMutEncodeJson(authMutGrantJsonWith(['t' => 'user:42']))))->toBeNull();
});

test('the decoder accepts payload nesting up to its limit and refuses one level past it', function () {
    // The payload is the application's and its depth is not the package's
    // business, but the limit is the parser's and it has to be a limit: a
    // grant deeper than this must be refused rather than silently truncated
    // into a different payload. The decoder's json_decode depth is 512, and
    // the grant envelope itself spends two levels, so the boundary the wire
    // can reach is at 510 payload levels in and 511 out.
    $nested = static fn (int $levels): string => '{"t":["user:42"],"p":'
        .str_repeat('[', $levels).'1'.str_repeat(']', $levels)
        .',"i":1700000000000000,"e":1700000300000000}';

    expect(Grant::decode(authMutEncodeJson($nested(510))))->not->toBeNull()
        ->and(Grant::decode(authMutEncodeJson($nested(511))))->toBeNull();
});
