<?php

use Lightspeed\Protocol\Frames;

/**
 * A wrong frame shape is invisible from the server side.
 *
 * pusher-js drops an envelope it does not recognise without raising anything,
 * so a renamed key or a nested `data` produces a client whose callback simply
 * never fires, no error, no log line, nothing to grep for. These assertions
 * pin the exact shapes, including the one that has already been wrong in
 * practice: a rejected subscription reported as a connection-level
 * `pusher:error` leaves the channel pending forever instead of firing the
 * channel's own error callback.
 */

test('it encodes the pusher data member as a json string, never a nested object', function () {
    expect(Frames::pusherEvent('resource.updated', 'private-resource.1', ['id' => 1]))->toBe([
        'event' => 'resource.updated',
        'data' => '{"id":1}',
        'channel' => 'private-resource.1',
    ]);
});

test('it passes an already encoded data string through untouched', function () {
    expect(Frames::pusherEvent('resource.updated', 'private-resource.1', '{"id":1}')['data'])
        ->toBe('{"id":1}');
});

test('it omits the channel key entirely for connection level frames', function () {
    expect(Frames::pusherEvent('pusher:pong', null, new stdClass()))->toBe([
        'event' => 'pusher:pong',
        'data' => '{}',
    ]);
});

test('it builds a connection established frame carrying the socket id', function () {
    expect(Frames::connectionEstablished('123.456789', 30))->toBe([
        'event' => 'pusher:connection_established',
        'data' => '{"socket_id":"123.456789","activity_timeout":30}',
    ]);
});

test('it builds a pusher error frame with a code and a message', function () {
    expect(Frames::pusherError('invalid-app-key', 'Invalid realtime app key.'))->toBe([
        'event' => 'pusher:error',
        'data' => '{"code":"invalid-app-key","message":"Invalid realtime app key."}',
    ]);
});

test('it reports a rejected subscription on the channel, with status, type and error', function () {
    expect(Frames::subscriptionError('private-resource.1', 401, 'AuthError', 'Subscription authorization failed.'))
        ->toBe([
            'event' => 'pusher_internal:subscription_error',
            'data' => '{"type":"AuthError","error":"Subscription authorization failed.","status":401}',
            'channel' => 'private-resource.1',
        ]);
});

test('it builds a lightspeed response frame with a request id, status and response', function () {
    expect(Frames::lightspeedResponse('req-1', ['ok' => true], 200))->toBe([
        'event' => 'lightspeed:response',
        'data' => '{"requestId":"req-1","status":200,"response":{"ok":true}}',
    ]);
});

test('it names the channel on a response so a client channel binding receives it', function () {
    // pusher-js hands a frame to a channel's bindings only when the frame
    // names that channel, so the documented client snippet depends on this.
    expect(Frames::lightspeedResponse('req-2', ['ok' => true], 200, 'private-notes.7'))->toBe([
        'event' => 'lightspeed:response',
        'data' => '{"requestId":"req-2","status":200,"response":{"ok":true}}',
        'channel' => 'private-notes.7',
    ]);
});

test('it keeps the diagnostic envelope separate from the pusher one', function () {
    expect(Frames::diagnosticError('invalid-message', 'Missing message type.'))->toBe([
        'type' => 'error',
        'code' => 'invalid-message',
        'message' => 'Missing message type.',
    ]);

    expect(Frames::diagnosticHello(7, '/diagnostic'))
        ->toHaveKey('type', 'hello')
        ->toHaveKey('fd', 7)
        ->toHaveKey('path', '/diagnostic')
        ->not->toHaveKey('event');
});

test('a diagnostic response carries data on success and an error on failure, never both', function () {
    expect(Frames::diagnosticResponse('1', true, ['pong' => true]))->toBe([
        'type' => 'response',
        'id' => '1',
        'ok' => true,
        'data' => ['pong' => true],
    ]);

    expect(Frames::diagnosticResponse('1', false, null, 'Send requires an action.'))->toBe([
        'type' => 'response',
        'id' => '1',
        'ok' => false,
        'error' => 'Send requires an action.',
    ]);

    expect(Frames::diagnosticResponse('1', false))->toHaveKey('error', 'unknown-error');
});
