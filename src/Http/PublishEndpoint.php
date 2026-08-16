<?php

namespace Lightspeed\Http;

use Lightspeed\Broadcasting\BroadcastBridge;
use Lightspeed\Protocol\PublishRequestVerifier;
use Lightspeed\Protocol\SubscriptionAuthorizer;
use Lightspeed\Logging\RuntimeLogger;

/**
 * The Pusher-compatible publish endpoint: POST /apps/{id}/events.
 *
 * This is how a server-side publisher (Laravel's broadcaster, the Pusher SDK,
 * a curl one-liner) gets an event onto a channel without holding a websocket.
 * This class owns that one request end to end (method check, signature check,
 * body validation, fan-out) and it owns nothing else.
 *
 * It deliberately does NOT own the response or the log line: handle() returns
 * the status, the JSON body and the fields to log, and Http\RequestRouter
 * writes them. That is what makes the endpoint testable on its own: the
 * rejection paths below are reachable with nothing but strings, no Swoole
 * response object and no listening socket. Signature verification itself is
 * pure and lives in Protocol\PublishRequestVerifier.
 *
 *   POST + valid signature + object body + name/channels/data
 *     -> fan out per channel -> 200 {ok, event, channels: {name: delivered}}
 *   anything else
 *     -> 405 | 401 | 400 | 422, and nothing is delivered
 */
class PublishEndpoint
{
    public function __construct(
        private readonly BroadcastBridge $broadcastBridge,
        private readonly RuntimeLogger $runtimeLogger,
    ) {
    }

    /**
     * Handle one publish request.
     *
     * @param  array<string, mixed>  $post   Swoole's parsed form body, if any.
     * @param  array<string, mixed>  $query  Query parameters, which carry the signature.
     * @return array{status: int, body: array<string, mixed>, log: array<string, mixed>}
     */
    public function handle(string $method, string $path, string $rawBody, array $post, array $query): array
    {
        if ($method !== 'POST') {
            return $this->result(405, ['error' => 'Method not allowed.']);
        }

        $decodedRequest = PublishRequestVerifier::decodeRequestBody(
            rawBody: $rawBody,
            post: $post,
        );

        if (!$this->verifier()->verify('POST', $path, $query, $decodedRequest['signatureBody'])) {
            return $this->result(401, ['error' => 'Invalid publish signature.']);
        }

        $payload = $decodedRequest['payload'];
        if ($payload === null) {
            return $this->result(400, ['error' => 'Invalid JSON body.']);
        }

        if (!is_array($payload)) {
            return $this->result(400, ['error' => 'Expected JSON object body.']);
        }

        $event = $payload['name'] ?? null;
        $data = $payload['data'] ?? null;
        $channels = $payload['channels'] ?? null;
        $singleChannel = $payload['channel'] ?? null;
        $socketId = $payload['socket_id'] ?? null;

        if (!is_string($event) || $event === '') {
            return $this->result(422, ['error' => 'Missing event name.']);
        }

        if (is_string($singleChannel) && $singleChannel !== '') {
            $channels = [$singleChannel];
        }

        if (!is_array($channels) || $channels === []) {
            return $this->result(422, ['error' => 'Missing channels.'], [
                'event' => $event,
            ]);
        }

        if (!is_string($data)) {
            return $this->result(422, ['error' => 'Expected event data to be a JSON string.'], [
                'event' => $event,
                'channels' => count($channels),
            ]);
        }

        // The publishing socket is excluded by its socket id, not by an fd: the
        // sender may be connected to a different node, where no local fd for it
        // exists at all.
        $delivered = [];

        foreach ($channels as $channel) {
            if (!is_string($channel) || $channel === '') {
                continue;
            }

            // Nothing can subscribe to an encrypted channel here, because
            // SubscriptionAuthorizer refuses them rather than serve plaintext
            // under a name that promises otherwise. Accepting the publish would
            // therefore return a cheerful 200 for a message that reached nobody,
            // which is the same silent failure one layer up.
            if (SubscriptionAuthorizer::isEncryptedChannel($channel)) {
                return $this->result(422, [
                    'error' => 'Lightspeed does not implement end-to-end encrypted channels, '
                        ."so nothing can subscribe to '{$channel}' and this message would reach nobody. "
                        .'Use a private- channel if payloads visible to the server are acceptable.',
                ], [
                    'reason' => 'encrypted-channel',
                    'channel' => $channel,
                ]);
            }

            $delivered[$channel] = $this->broadcastBridge->fanOut(
                [$channel],
                $event,
                $data,
                is_string($socketId) ? $socketId : null,
            );
        }

        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'event' => $event,
                'channels' => $delivered,
            ],
            'log' => [
                'event' => $event,
                'channels' => count($delivered),
                'socket' => is_string($socketId) ? $socketId : null,
                'payload' => $this->runtimeLogger->maybePayloadSnippet($payload),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $log
     * @return array{status: int, body: array<string, mixed>, log: array<string, mixed>}
     */
    private function result(int $status, array $body, array $log = []): array
    {
        return [
            'status' => $status,
            'body' => $body,
            'log' => $log,
        ];
    }

    /** The publish-endpoint gate, built from this app's credentials. */
    private function verifier(): PublishRequestVerifier
    {
        return new PublishRequestVerifier(
            (string) config('lightspeed.reverb_compat.app_id', ''),
            (string) config('lightspeed.reverb_compat.app_key', ''),
            (string) config('lightspeed.reverb_compat.app_secret', ''),
        );
    }
}
