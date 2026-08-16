<?php

namespace App\Realtime;

use Illuminate\Support\Facades\Broadcast;
use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Contracts\ClientEventHandler;

/**
 * The entire server side of the hello-world demo.
 *
 * A client event arrives here inside the full Laravel container: the container
 * is booted, your models and services are available, and the handler decides
 * what happens next. Returning null instead would let the event fall through to
 * normal Pusher peer relay.
 *
 * It does two different things on purpose, because they travel differently:
 *
 *   broadcast()      -> everyone on the channel, through Redis, so every tab
 *                       and every worker and every instance sees the message
 *   returned result  -> only the socket that asked, on the same connection,
 *                       which is what makes a request/response round trip
 */
class EchoHandler implements ClientEventHandler
{
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        if ($event->event !== 'client-say') {
            return null;
        }

        $data = (array) $event->data;
        $said = trim((string) ($data['message'] ?? ''));

        if ($said === '') {
            return ClientEventResult::error('empty-message', 'Say something first.');
        }

        // Everyone hears it. $event->userId is the identity your channel
        // authorization approved, not something the browser asserted here.
        Broadcast::connection()->broadcast(['presence-lobby'], 'said', [
            'message' => $said,
            'from' => $event->userId,
            'at' => now()->toTimeString(),
        ]);

        // The sender alone gets this, on the socket it asked from.
        return ClientEventResult::response(
            requestId: (string) ($data['requestId'] ?? ''),
            response: [
                'saved' => true,
                'answeredBy' => gethostname(),
            ],
        );
    }
}
