# Extending Lightspeed

How application behaviour plugs into the server: handling a message that arrives over a socket, answering it, being told when a connection goes, and routing stateful work to the worker that owns a resource.

Handlers are registered in config. If you have not published it yet:

```bash
php artisan vendor:publish --tag=lightspeed-config
```

```php
// config/lightspeed.php
'client_event_handlers' => [
    App\Realtime\MyClientEventHandler::class,      // implements Lightspeed\Contracts\ClientEventHandler
],
'connection_closed_handlers' => [
    App\Realtime\MyConnectionClosedHandler::class, // implements Lightspeed\Contracts\ConnectionClosedHandler
],
'owner_command_handlers' => [
    App\Realtime\MyOwnerCommandHandler::class,     // implements Lightspeed\Contracts\OwnerCommandHandler
],
```

## Handling a message from the browser

A client event handler receives `client-*` events server-side, inside the full Laravel container. Return `null` to decline (the event then relays to other subscribers as a normal Pusher client event), or return a result to answer the sender:

```php
// app/Realtime/SaveNoteHandler.php
namespace App\Realtime;

use App\Models\Note;
use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Contracts\ClientEventHandler;

class SaveNoteHandler implements ClientEventHandler
{
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        if ($event->event !== 'client-save-note') {
            return null;                       // not mine, try the next handler
        }

        $data = (array) $event->data;
        $note = Note::findOrFail($data['id']);
        $note->update(['body' => $data['body']]);

        return ClientEventResult::response(
            requestId: (string) $data['requestId'],
            response: ['saved' => true, 'version' => $note->version],
        );
    }
}
```

The reply arrives on the same socket as a `lightspeed:response` event:

```js
// { requestId, status, response }
channel.bind('lightspeed:response', (frame) => {
    if (frame.requestId === myRequestId) {
        console.log(frame.status, frame.response);
    }
});
```

`ClientEventResult` has three factories: `response()` to answer the sender, `handled()` to consume the event silently, and `error()` to push a Pusher-style error frame.

**A client event has a size limit**, 10KB by default, which is Pusher's own. A bigger frame never reaches your handler and is not relayed to the channel. It is a dial (`LIGHTSPEED_MAX_CLIENT_EVENT_BYTES`); [configuration](configuration.md#bounding-what-one-connection-can-send) explains what it is measured on and why the limit is not the place to put a large payload. Size is bounded, frequency is not ([SECURITY.md](../SECURITY.md#known-weaknesses)), so rate-limit inside your own handler if it does expensive work.

The refusal is a **connection-level** `pusher:error` with `code: 'client-event-too-large'`, not a channel event, which means nothing in a default Echo app surfaces it: it arrives at the connection's error handler rather than at anything bound with `channel.bind`. Bind it, or an over-limit event looks to your client exactly like a message that vanished:

```js
pusher.connection.bind('error', (err) => {
    // err.data.code === 'client-event-too-large'
    // err.data.message names the limit in bytes
});
```

**Knowing who sent it.** The event cannot arrive on a channel this socket did not subscribe to, and subscribing ran your own `Broadcast::channel` rule, so the identity on the event is one your app already approved:

```php
$event->userId;             // presence channels: the user_id your auth returned
$event->userInfo;           // presence channels: the payload your auth returned
$event->connectionContext;  // handshake headers and cookies, for resolving your own session
$event->auth;               // the payload your channel callback attached with Lightspeed::tag(...)->with()
$event->socketId;           // this connection's Pusher socket id
```

Private channels carry no user payload in the Pusher protocol, so `userId` is null there. You still have the identity, in whichever of these fits your app: the channel name itself (`private-inbox.42` was authorized for user 42 by your own channel rule, so parsing `$event->channel` is legitimate), the grant payload (`Lightspeed::tag(...)->with(['user_id' => $user->id])` at auth time arrives as `$event->auth['user_id']` on every message, signed), or your session resolved from `connectionContext`.

`auth` is `null` unless your channel callback called `Lightspeed::tag()`. Treat null as "no", not as "no restrictions": it means nothing was re-checked before your handler ran. See [authorization](authorization.md).

**Hydrating the user.** `$event->user()` returns the `Authenticatable` for the event's identity; see [moving off the hot path](authorization.md#moving-off-the-hot-path-fully-hydrated-models) for what it costs, when it is null, and how to act as that user for gates and policies.

**Do not log anybody in.** Your handler runs in its own container, cloned for
the event and thrown away afterwards, and the authentication guards are reset
when it returns: a user your handler authenticates in memory is gone before the
next event runs.

That is the container, not the world. `Auth::loginUsingId()` writes the user id
into the **session** as well as into the guard, and only the guard half is
undone — the session keeps it, and the next thing to read that session sees that
user.

**Hold the identity in a variable, not in the guard.** If your handler needs an
identity, read it once into a local and use the local:

```php
$user = $event->user();            // or User::find($event->userId)

Broadcast::connection()->broadcast(['presence-lobby'], 'said', [
    'from' => $user->id,           // the local, not Auth::id()
]);
```

`Auth::guard()->setUser($user)` for the length of the call is fine for gates and
policies, but **do not re-read the `Auth` facade after you broadcast**. A
broadcast can close a dead socket, which resets the guards mid-call, and
`Auth::user()` is null for the rest of your handler's body. Use the local.

The same rule and the same reset apply to every handler this package runs:
client events, connection-closed handlers and owner commands.

## Answering one client, or telling everyone

These are different things and they travel differently. A handler usually wants both.

```php
// Everyone on the channel hears it: out through Redis, so every tab on every
// worker on every machine gets it, including other instances.
Broadcast::connection()->broadcast(['presence-lobby'], 'said', [
    'message' => $said,
    'from' => $event->userId,
]);

// Only the socket that asked gets this, back on the same connection. This is
// what makes the exchange a request/response rather than a broadcast.
return ClientEventResult::response(
    requestId: (string) $data['requestId'],
    response: ['saved' => true],
);
```

If you only return a result, the sender sees a reply and nobody else sees anything, which looks broken in a chat-shaped app. If you only broadcast, everyone sees the message but the sender gets no confirmation and cannot time the round trip. The [hello world example](../example) does both, and watching it in two browser tabs is the quickest way to feel the difference.

## Knowing when a connection goes

A handler runs inside your application when a connection ends, so you can drop a
claim, release a lock or write a last-seen row without waiting for a timeout.

Your handler only ever fires for connections that passed channel authorization.
A connection reaches your code once it held at least one channel subscription or
one grant; a client holding nothing but the public app key can open a socket and
hang up without reaching it.

```php
// app/Realtime/ReleaseClaims.php
namespace App\Realtime;

use Illuminate\Support\Facades\Cache;
use Lightspeed\Connections\ConnectionClosed;
use Lightspeed\Contracts\ConnectionClosedHandler;

class ReleaseClaims implements ConnectionClosedHandler
{
    public function connectionClosed(ConnectionClosed $event): void
    {
        // A swept event has no socket id. Let the TTL expire those.
        if ($event->socketId === null) {
            return;
        }

        foreach ($event->presenceLeaves as $leave) {
            // No id means no claim to release.
            if ($leave['user_id'] === null) {
                continue;
            }

            $key = "editing:{$leave['channel']}:{$leave['user_id']}";

            // Release only the token we stored.
            if (Cache::get($key) === $event->socketId) {
                Cache::forget($key);
            }
        }
    }
}
```

The claim is taken where the connection is authorized, stamped with the socket
id that will hold it:

```php
// routes/channels.php
Broadcast::channel('doc.{doc}', function ($user, string $doc) {
    // Keyed by the name on the wire, `presence-` prefix and all, which is the
    // name the event carries. Laravel strips it before matching this callback.
    Cache::put(
        "editing:presence-doc.{$doc}:{$user->id}",
        (string) request()->input('socket_id'),
        now()->addMinutes(5),   // the TTL is what makes releasing certain
    );

    return ['user_id' => (string) $user->id];
});
```

```php
// config/lightspeed.php
'connection_closed_handlers' => [
    App\Realtime\ReleaseClaims::class,
],
```

Compare the token before forgetting. A close can arrive minutes after the
browser went away, by which time the same user may have reconnected and
re-claimed, and an unguarded `forget()` would free the new connection's claim.

The read-then-forget above is two operations; under contention use an atomic
compare-and-delete (`Cache::lock()` with an owner token, or a small Lua script)
so a claim taken between the two is not the one released.

Every handler that resolves and implements
`Lightspeed\Contracts\ConnectionClosedHandler` is run, in list order. There is
no result to return and nothing to decline, so unlike a client event handler
this one cannot answer on another's behalf. Four things stop a handler running:

- a class that cannot be resolved, or one that does not implement the contract,
  is logged and skipped, and the handlers after it still run. (`lightspeed:serve`
  refuses to start on either, so this is the config that changed under a running
  server.)
- a handler that throws is logged and stepped over. It does not stop the
  teardown, and it does not stop the handlers after it.
- an event that arrives when no booted application worker is available to run it
  in is logged and dropped.
- a connection whose worker was killed produces no `'closed'` event at all. See
  the bound below.

### What the event carries

Two shapes. Read `reason` first: on `'swept'`, most fields are empty.

| Field | on `'closed'` | on `'swept'` |
|---|---|---|
| `reason` | `'closed'` | `'swept'` |
| `socketId` | the connection's Pusher socket id | always null |
| `channels` | every channel the connection was subscribed to | one channel: the single reaped one |
| `presenceLeaves` | one entry per presence membership that ENDED | exactly one entry, for that channel |
| `presenceLeaves[n]['user_id']` | `?string`; null when the member payload had no id | the reaped member's id |
| `presenceLeaves[n]['user_info']` | `?array`; null when the member payload had none | always null |
| `tags` | the tags this connection's grants carried, deduplicated across its channels | always `[]` |
| `authPayloads` | channel name => the payload your channel callback attached with `Lightspeed::tag(...)->with()` | always `[]` |

`presenceLeaves` follows the same rule as `pusher_internal:member_removed`: a
user with two tabs open on one channel has not left it, so closing one of them
reports the channel in `channels` and nothing in `presenceLeaves`. Closing the
second reports the leave.

`authPayloads` is a map, not one payload, because a connection can hold a grant
on several channels. Look up the channel you care about. A channel with no entry
was never tagged; treat that as "no", not as "no restrictions", exactly as on a
client event. `tags` is a union, deduplicated across channels, because a tag is
only ever compared for equality.

### The cost: handlers run on the worker's event loop

**A handler is not a job. It runs inline, in the worker that was holding the
connection, on the single event loop that serves every other connection there.**

`enable_coroutine` is off by default and `worker_num` defaults to 1, so there is
no scheduler to yield to and no second worker to take over: a handler that
spends 50ms in a database call has stopped every frame, every HTTP request and
every timer on that worker for 50ms.

Your handler is not the whole bill, either. Every authorized close runs a full
Octane task cycle so the handler gets a clean container, and that is paid per
close even by a handler that returns immediately.

Mass disconnects multiply both halves, serially. A deploy that drops a thousand
sockets runs a thousand task cycles and a thousand handler calls, one after
another, on a worker still serving everyone else.

So keep handlers to memory and to a fast local store, and dispatch anything real
to a queue. What the sweep tells you is bounded for exactly this reason
(`presence.sweep_max_notifications_per_tick`); what an orderly close tells you
is not, because it is one connection.

Guards, sessions and the task boundary work here exactly as they do for client
events. See [do not log anybody
in](#handling-a-message-from-the-browser).

### The bound: this hook is best effort

**It is a fast path, not a guarantee, and an application that needs correctness
still needs TTLs or leases.**

A worker killed without warning (SIGKILL, OOM, a container stop) never runs a
teardown, so no `'closed'` event is emitted for the connections it was holding.
What happens instead:

- if the connection was on a **presence channel**, the presence sweeper reaps
  the abandoned membership later and your handler runs with `reason` `'swept'`.
  That event is thin: see the table above. It also arrives late, up to
  `lightspeed.presence.connection_ttl_seconds`
  (`LIGHTSPEED_PRESENCE_CONNECTION_TTL_SECONDS`, default 60) after the process
  died.
- one killed connection that was on **several presence channels** produces one
  `'swept'` event per channel, and nothing correlates them: there is no socket
  id in any of them to tie them together. Handle each channel on its own terms.
- if the connection was on **no presence channel**, nothing fires at all.

Events can also arrive LATE rather than not at all, and late is the case that
bites: a half-open connection is closed when the TCP layer gives up on it, which
can be minutes after the browser went away and well after the same user has
reconnected. So an event can be about a connection that is no longer the user's
current one. Key anything you release on something that identifies the
connection, such as `socketId`, rather than on the user alone; the sample above
does exactly that.

## Routing work to the owning worker

When the same resource can be mutated from several workers or instances, ask the bus whether this worker owns it. `forwardIfOwnedByAnotherProcess()` returns `null` when the current worker is the owner (so you execute locally), or the owner's response array when it forwarded the work:

```php
use Lightspeed\Owner\OwnerCommandBus;
use Lightspeed\Owner\ResourceRouter;

$forwarded = app(OwnerCommandBus::class)->forwardIfOwnedByAnotherProcess(
    resourceId: $note->id,
    command: 'save-note',
    payload: $data,
);

if ($forwarded !== null) {
    // The owner ran it. This can still be a failure (it could not take the
    // lease, or lost it mid-flight), so check rather than assuming success.
    if (($forwarded['ok'] ?? false) !== true) {
        report(new RuntimeException($forwarded['error'] ?? 'Owner command failed.'));
    }

    return $forwarded;
}

// This worker owns the resource, so take the lease yourself before writing:
// forwarding takes it for you, executing locally does not.
$router = app(ResourceRouter::class);
$lease = $router->acquireWriteLease((string) $note->id);

if ($lease === null) {
    return ['ok' => false, 'error' => 'Another writer holds this resource.'];
}

try {
    return $this->saveLocally($note, $data);
} finally {
    $router->releaseWriteLease((string) $note->id, $lease);
}
```

The owning worker runs the command through an owner command handler:

```php
use Lightspeed\Contracts\OwnerCommandHandler;
use Lightspeed\Owner\OwnerCommand;

class SaveNoteOwnerHandler implements OwnerCommandHandler
{
    public function handle(OwnerCommand $command): ?array
    {
        if ($command->command !== 'save-note') {
            return null;
        }

        return ['ok' => true, 'version' => $this->saveLocally($command->resourceId, $command->payload)];
    }
}
```

This is the mechanism behind collaborative editing in Lightwave, the app Lightspeed was extracted from: every document mutation, whichever instance receives it, executes on the document's owning worker.

### Forwarding work you do not need an answer to

`forwardIfOwnedByAnotherProcess()` waits. It writes the command and then polls for the owner's response, and with `enable_coroutine` off (the default) that poll is a `usleep()` on the single event loop serving every connection this worker holds — so the calling worker answers nothing at all while it waits. For a mutation whose result you need, that is the price of the result. For a **stream** of commands whose result you do not, it is pure loss.

`forwardWithoutReply()` is the same delivery without the wait:

```php
app(OwnerCommandBus::class)->forwardWithoutReply(
    resourceId: (string) $arena->id,
    command: 'player-input',
    payload: $intent,
);
```

If this worker owns the resource, the handler runs immediately. If another worker owns it, the signed command is appended to that worker's stream and `forwardWithoutReply()` returns: no response is written, nothing is polled, nothing sleeps. The owner runs it on its next drain, one command at a time, in the order the entries were appended. The receiving end is the same `OwnerCommandHandler` as above, and its return value is simply not sent anywhere; `$command->expectsReply` is `false`, so a handler that serves both paths can tell them apart.

**Use it when** the caller has no use for the result: input frames, telemetry, cursor positions — anything where the next command is more useful than the last one's receipt.

**Do not use it when** you need the result, need to know the command ran, or need to react to a failure. There is **no return value and no retry**: a handler that fails on the owner is logged (see below) and the command is gone. Use `forwardIfOwnedByAnotherProcess()` for those and pay for the answer you are getting.

`forwardWithoutReply()` can still **throw**, in one case and for the same reason the forwarding path does: if Redis is unreachable, the ownership lookup or the stream write raises, and that reaches your handler. "Fire and forget" means nobody waits for the owner, not that the call cannot fail before it gets there.

When `owner_commands.enabled` is `false` there is no cross-process routing at all, so `forwardWithoutReply()` runs the command **on the calling worker**. That is the same answer `forwardIfOwnedByAnotherProcess()` gives by returning `null`; `forwardWithoutReply()` has no return value to say it with, so it acts on it.

Three more things it deliberately does not do:

- **It takes no write lease.** The lease's job is to be able to refuse, and a refusal has no caller left to reach, so taking it would mean dropping commands in silence. The ordering a no-reply command gets is the owning worker's single-threaded drain, and nothing stronger. If your command needs the lease, it needs a reply too.
- **It does not report failure to the caller.** A handler that throws, on the owning worker or the local one, is caught and logged as `Lightspeed no-reply owner command failed on the owning worker`, with the resource id and the command. That log line is the *only* report: there is no response key and no caller. It is rate-limited to one line per resource and command per `LIGHTSPEED_OWNER_COMMANDS_NO_REPLY_REPORT_INTERVAL_SECONDS` (default 60), because a broken handler fails at whatever rate the stream arrives.
- **It drops a command with no resolvable owner**, logged as `Lightspeed dropped a no-reply owner command: no resolvable owner`, per resource and rate-limited the same way. An *unowned* resource does not reach this: resolving the owner claims it, so an unowned resource makes the calling worker the owner and the command runs locally. A drop means Redis could neither grant the claim nor report who holds it. With no caller to fail, a log line is the only honest alternative to silence.

Everything else is identical to the forwarding path: same signature, same process addressing, same freshness window, same spend-once request id. A no-reply command is exactly as trustworthy as a forwarded one, and the owner's drain applies every one of those checks to both.

#### Deploying it: what a mixed-version fleet does

The "no reply" instruction rides inside the signed message, and an **old worker does not know the field**. So during a rolling deploy, a command written by a new worker and drained by one still running the previous build is executed correctly — and then treated as a forwarded command anyway. Two consequences, for the length of the mixed-version window only:

- The old worker **writes a response key** nobody will ever read. It expires on its own after `response_ttl_seconds` (default 30), so this costs Redis memory proportional to your no-reply rate for half a minute, not a leak.
- The old worker **takes the resource write lease** to run it. If the lease is contended, that command is refused, and the refusal is returned to a caller that has already gone: **the command is dropped silently.**

If your no-reply stream is contended and you cannot afford those drops, drain the old workers before pointing traffic at `forwardWithoutReply()` — deploy the package, restart every worker, and only then ship the code that calls it. Forwarded commands are unaffected in both directions: their bytes are unchanged by this feature, and there is a test that fails if that stops being true.

## The write lease and its bound

The write lease is a single Redis key with a fixed TTL (`LIGHTSPEED_RESOURCE_WRITE_LEASE_TTL_SECONDS`, default 10 seconds), and it is **not renewed while your handler runs**. So the guarantee is precise: **one writer per resource at a time, provided every mutation finishes inside the lease TTL.** Size the TTL above your slowest mutation.

A handler that overruns the TTL loses the key to Redis expiry mid-execution, and another worker can then acquire the same resource and execute concurrently. The package does not prevent that, but it does not hide it either: releasing is a compare-and-delete, so a command that no longer owns the key at the end is logged with its resource id and returned to the caller as a structured failure instead of a success. Detection is after the fact (the handler has already run), so what to do about a mutation that ran unserialized is your call.

