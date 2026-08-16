<?php

use Lightspeed\Protocol\Frames;

/**
 * The two envelopes FramesTest left half pinned.
 *
 * `lightspeed:stale` and the diagnostic greeting were asserted key by key, or
 * not at all, so a member could go missing without a red test. Both are read by
 * something that was not written here: a client library decides how long to wait
 * from `retry_in_ms` and which channel to re-subscribe from `channel`, and a
 * probe reads the greeting to find out which verbs it may speak. A dropped
 * member in either is silent on this side and only shows up as a client that
 * never comes back, or a probe that asks for a verb it was never offered.
 *
 * So these assert the WHOLE array rather than the members that felt important.
 */
test('a stale frame names the channel twice, on the envelope and inside the data', function () {
    // Both, on purpose. pusher-js routes the frame to a channel's bindings by
    // the envelope member, and the payload member is what a handler reads to
    // know which subscription to renew, so dropping either one leaves a client
    // that cannot act on the refusal.
    expect(Frames::stale('private-orders.7', 1234, 'expired'))->toBe([
        'event' => 'lightspeed:stale',
        'data' => '{"channel":"private-orders.7","reason":"expired","retry_in_ms":1234}',
        'channel' => 'private-orders.7',
    ]);
});

test('the diagnostic greeting advertises every verb the diagnostic protocol answers', function () {
    // The greeting IS the protocol's documentation: a probe reads it to learn
    // what it may send. Adversarial review caught this test pinning the greeting's
    // literal against a copy of itself, which enshrined a greeting that
    // advertised two server-to-client types the match refuses; the advertised
    // list now IS DiagnosticProtocol::CLIENT_TYPES, the list the match
    // enforces, so the two cannot drift.
    expect(Frames::diagnosticHello(7, '/diagnostics'))->toBe([
        'type' => 'hello',
        'server' => 'lightspeed',
        'fd' => 7,
        'path' => '/diagnostics',
        'protocol' => [
            'name' => 'lightspeed-diagnostic',
            'version' => '1',
            'messages' => \Lightspeed\Diagnostics\DiagnosticProtocol::CLIENT_TYPES,
        ],
        'capabilities' => [
            'send' => ['ping', 'whoami', 'broadcast-test'],
        ],
    ]);
});

test('every advertised verb is one the protocol actually answers', function () {
    // The other half of the drift guard: the constant the greeting reads must
    // match the arms the match dispatches. A bare type with no arguments may
    // earn any refusal EXCEPT "unsupported-type", which is the refusal for a
    // verb that does not exist.
    $protocol = app(\Lightspeed\Diagnostics\DiagnosticProtocol::class);

    // The negative control first, proving this extraction sees the refusal
    // at all: an unknown verb must produce exactly the code the loop below
    // asserts against. Without it, a renamed frame key would turn every
    // assertion in the loop vacuous.
    $unknown = $protocol->handle(7, ['type' => 'no-such-verb']);
    expect($unknown['code'] ?? null)->toBe('unsupported-type');

    foreach (\Lightspeed\Diagnostics\DiagnosticProtocol::CLIENT_TYPES as $type) {
        $reply = $protocol->handle(7, ['type' => $type]);

        expect($reply['code'] ?? null)->not->toBe('unsupported-type', "verb {$type}");
    }
});
