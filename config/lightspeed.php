<?php

$appUrl = env('APP_URL', 'http://localhost');
$appScheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
$appHost = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
$appPort = (int) (parse_url($appUrl, PHP_URL_PORT) ?: ($appScheme === 'https' ? 443 : 80));
$defaultBindPort = (int) env('LIGHTSPEED_SERVER_PORT', env('LIGHTSPEED_HTTP_PORT', env('REVERB_SERVER_PORT', 8000)));

return [

    /*
    |--------------------------------------------------------------------------
    | Lightspeed Server
    |--------------------------------------------------------------------------
    |
    | Lightspeed defaults to one public app + realtime origin. The Swoole
    | runtime can still be split across different bind ports if needed, but the
    | default local and production shape is same-host / same-port.
    |
    */

    'server' => [
        'host' => env('LIGHTSPEED_SERVER_HOST', env('REVERB_SERVER_HOST', '0.0.0.0')),
        'http_port' => (int) env('LIGHTSPEED_HTTP_PORT', $defaultBindPort),
        'port' => $defaultBindPort,

        // OFF by default, and the default is the one this package is sized
        // for. Turning it on runs every callback inside a coroutine, which
        // means the host application's own request handling becomes
        // concurrent inside one process, and a Laravel container, its
        // singletons and its facade roots are not built for that. Correctness
        // of the application comes before latency of the package's own waits.
        //
        // One named instance of that class of problem, because it is this
        // package's own: the auth manager is shared between the application
        // and every Octane sandbox cloned from it, so the guard reset at each
        // task boundary (see Http\OctaneWorker::runTask) is process-wide. With
        // this off, task boundaries are serial and a request only ever sees
        // one that it interrupted itself. With it on, a request suspended at
        // an await can have its guard state reset by any concurrent websocket
        // frame, at any yield point, as often as a client sends one.
        //
        // The waits that would otherwise be sized for a scheduler know about
        // this and bound themselves accordingly: see
        // `owner_commands.blocking_wait_timeout_ms` and
        // `resources.blocking_write_lease_retries`.
        'enable_coroutine' => (bool) env('LIGHTSPEED_ENABLE_COROUTINE', false),
        'http_compression' => (bool) env('LIGHTSPEED_HTTP_COMPRESSION', false),
        'worker_num' => (int) env('LIGHTSPEED_WORKER_NUM', 1),

        // Swoole task workers, for the host application's own use. This
        // package dispatches no tasks, so neither the realtime surface nor the
        // HTTP surface changes with this number. What needs it is
        // `Octane::concurrently()`: Lightspeed binds the Swoole server into the
        // application container, which is what makes Octane route that helper
        // through Swoole tasks, and a task with no task worker to run in is a
        // task that cannot be served.
        //
        // Each task worker is a separate process that boots its own copy of the
        // application, so 0 is the right default for an application that never
        // calls it. `Server::serve()` registers the `task` and `finish`
        // callbacks unconditionally, because Swoole refuses to start a server
        // that has task workers and no `onTask`.
        'task_worker_num' => (int) env('LIGHTSPEED_TASK_WORKER_NUM', 0),

        // The largest single request or websocket frame Swoole will assemble
        // before it drops the connection, in bytes. It bounds the buffer a
        // client can make one worker allocate BEFORE any of this package's code
        // runs, which is the only place a bound can be cheap.
        //
        // THIS IS NOT THE CLIENT-EVENT LIMIT, and it cannot be: one process
        // serves the host application's HTTP on the same port, so this number
        // is also the largest file your application can accept as an upload.
        // Sized down to the 10KB a client event is allowed
        // (`client_events.max_frame_bytes`), it would refuse every upload the
        // application has. The default is Swoole's own 2MB; raise it for an
        // application that accepts larger uploads.
        'package_max_length' => (int) env('LIGHTSPEED_PACKAGE_MAX_LENGTH', 2 * 1024 * 1024),

        // Swoole's own reaping of connections that have gone quiet: it closes
        // any connection that has SENT nothing for `heartbeat_idle_time`
        // seconds, checking every `heartbeat_check_interval` seconds, and the
        // close runs the same teardown an orderly disconnect does.
        //
        // OFF BY DEFAULT. What it would reap is the half-open socket: a client
        // whose network vanished without a FIN, which this server otherwise
        // holds open forever. What it would ALSO reap is a perfectly healthy
        // client: Swoole's heartbeat CLOSES an idle connection rather than
        // pinging it (verified on Swoole 6.2.0), and pusher-js only pings after
        // `activity_timeout` of hearing NOTHING, resetting that timer on every
        // frame it RECEIVES. So a browser that only listens, on a channel that
        // is busy, sends nothing at all and is reaped while it is working
        // perfectly.
        //
        // Turn it on only if every client of yours sends something more often
        // than the idle time: a client that both sends and receives, or one you
        // have given its own ping. Pusher's and Reverb's equivalent is a
        // SERVER-SIDE ping to quiet connections, which this package does not
        // have yet; when it does, this becomes safe to default on. 120/60 are
        // the values to use when you turn it on, matching the ecosystem's
        // idle-timeout shape against the 30s `activity_timeout` above.
        //
        // SET BOTH OR NEITHER. Swoole arms its reaper on the idle time ALONE,
        // checking on a cadence you did not choose, so one without the other is
        // passed to Swoole as neither.
        'heartbeat_idle_time' => (int) env('LIGHTSPEED_HEARTBEAT_IDLE_TIME', 0),
        'heartbeat_check_interval' => (int) env('LIGHTSPEED_HEARTBEAT_CHECK_INTERVAL', 0),

        'instance_id' => env('LIGHTSPEED_INSTANCE_ID'),
        'log_file' => env('LIGHTSPEED_LOG_FILE', storage_path('logs/lightspeed.log')),
        'pid_file' => env('LIGHTSPEED_PID_FILE', storage_path('framework/lightspeed.pid')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Worker Relay
    |--------------------------------------------------------------------------
    |
    | Broadcasts fan out to every worker on every instance through a Redis
    | stream. Each worker polls the stream and delivers to its own sockets.
    |
    */

    'relay' => [
        'enabled' => (bool) env('LIGHTSPEED_RELAY_ENABLED', true),
        'redis_connection' => env('LIGHTSPEED_RELAY_REDIS_CONNECTION', 'default'),
        'broadcast_stream' => env('LIGHTSPEED_RELAY_BROADCAST_STREAM', 'lightspeed:broadcasts'),
        'max_entries' => (int) env('LIGHTSPEED_RELAY_MAX_ENTRIES', 10000),
        'poll_interval_ms' => (int) env('LIGHTSPEED_RELAY_POLL_INTERVAL_MS', 25),
        'read_count' => (int) env('LIGHTSPEED_RELAY_READ_COUNT', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Ownership & Write Leases
    |--------------------------------------------------------------------------
    |
    | One worker owns each active resource at a time. Owner claims are active
    | routing metadata; non-owner workers forward mutations through the
    | owner-command bus. The write lease is a short Redis lease shared by every
    | mutation path.
    |
    */

    'resources' => [
        // Which channels name an owned resource. With the default, the channels
        // `private-resource.42` and `presence-resource.42` both route to
        // resource id 42. Set it to your own noun (`document`, `board`, `game`)
        // to match the channel names your clients already subscribe to.
        'channel_prefix' => env('LIGHTSPEED_RESOURCE_CHANNEL_PREFIX', 'resource'),
        'redis_connection' => env('LIGHTSPEED_RESOURCE_REDIS_CONNECTION', env('LIGHTSPEED_RELAY_REDIS_CONNECTION', 'default')),
        'owner_ttl_seconds' => (int) env('LIGHTSPEED_RESOURCE_OWNER_TTL_SECONDS', 30),
        'write_lease_ttl_seconds' => (int) env('LIGHTSPEED_RESOURCE_WRITE_LEASE_TTL_SECONDS', 10),
        'write_lease_retries' => (int) env('LIGHTSPEED_RESOURCE_WRITE_LEASE_RETRIES', 50),
        'write_lease_wait_us' => (int) env('LIGHTSPEED_RESOURCE_WRITE_LEASE_WAIT_US', 20000),

        // The retry count that applies when the waiting context cannot yield,
        // which with `enable_coroutine` off is every context. Each retry is
        // then a real usleep() on the event loop that serves every connection
        // on the worker, so 50 x 20ms was up to a second of a server that
        // answers nothing. Twelve is ~240ms: long enough to ride out a normal
        // handoff, short enough that a contended resource reports "busy",
        // which is what the caller is given a null for, instead of freezing
        // the process. Raise it only alongside `enable_coroutine`.
        'blocking_write_lease_retries' => (int) env('LIGHTSPEED_RESOURCE_BLOCKING_WRITE_LEASE_RETRIES', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Limits
    |--------------------------------------------------------------------------
    |
    | A public channel needs no authorization and the app key is public, so a
    | `pusher:subscribe` frame is the one path in this server that an anonymous
    | client can make allocate without proving anything: the channel name
    | becomes an array key, twice, for the life of the connection. Without a
    | bound, a client looping subscribes with junk names grows the worker until
    | it is OOM-killed, and `worker_num` defaults to 1.
    |
    | The defaults are Pusher's and Reverb's, so an application whose channel
    | names already work against either is unaffected. Over-limit subscribes are
    | REFUSED with a `pusher:error` naming the limit, never truncated and never
    | silently dropped: a client whose subscribe vanished waits forever for a
    | subscription_succeeded that is not coming.
    |
    */

    'channels' => [
        'max_name_length' => (int) env('LIGHTSPEED_MAX_CHANNEL_NAME_LENGTH', 164),
        'max_per_connection' => (int) env('LIGHTSPEED_MAX_CHANNELS_PER_CONNECTION', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Events
    |--------------------------------------------------------------------------
    |
    | A `client-*` frame is the one path that carries an application payload UP
    | the socket, and it is the payload the server does the most with: it is
    | handed to your handler inside the booted application, and if no handler
    | answers it is relayed to every other subscriber on the channel. Without a
    | bound, one authorized connection can hand every peer on a channel a
    | megabyte, once per frame.
    |
    | The default is Pusher's own 10KB, so an application whose client events
    | already work against Pusher or Reverb is unaffected. Over-limit events are
    | REFUSED with a `pusher:error` naming the limit, never truncated: a payload
    | silently cut in half is a handler running against data the client did not
    | send.
    |
    | MEASURED ON THE WHOLE FRAME, envelope included, not on the `data` member
    | alone the way Pusher states its limit. The envelope is the event name and
    | the channel name, so the difference is a couple of hundred bytes at most,
    | and the frame length is a number the server already has: checking it costs
    | one integer comparison instead of re-encoding the payload on the hot path.
    |
    | A value that is not a usable size (zero, negative, or a string that is not
    | a number, which is what an empty env var casts to) falls back to the 10240
    | below rather than to some floor: a typo should leave you with the default,
    | never with a server that refuses every client event.
    |
    */
    'client_events' => [
        'max_frame_bytes' => (int) env('LIGHTSPEED_MAX_CLIENT_EVENT_BYTES', 10240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    |
    | Optional colour assignment: set `color_slots` to a palette size and every
    | presence member is given a stable `colorIndex` (0 to slots-1) in its
    | user_info, assigned atomically so two members on different workers never
    | hold the same slot, and released when they leave. Apps that paint
    | collaborator cursors want this; everyone else should leave it at 0, which
    | keeps member payloads exactly as the app sent them.
    |
    | With colours on, a member may set `actorKey` in its user_info to say which
    | connections count as the same actor (several tabs of one person sharing a
    | colour). Without it, the user id is the actor.
    |
    */

    'presence' => [
        'redis_connection' => env('LIGHTSPEED_PRESENCE_REDIS_CONNECTION', env('LIGHTSPEED_RELAY_REDIS_CONNECTION', 'default')),
        'color_slots' => (int) env('LIGHTSPEED_PRESENCE_COLOR_SLOTS', 0),

        // How often each worker says its presence connections are still there,
        // and reconciles away the rows of connections nobody is saying that
        // about any more.
        //
        // Presence rows are REFERENCE COUNTS, and an orderly close is the
        // only thing that ever decrements one. A SIGKILL, an OOM kill or a
        // container stop that reaps the process leaves a member behind with
        // no decrement left anywhere; this sweep is the only thing that can
        // remove such a row, so without it the member sits in every future
        // snapshot of that channel permanently.
        'sweep_interval_ms' => (int) env('LIGHTSPEED_PRESENCE_SWEEP_INTERVAL_MS', 10000),

        // How many presence channels one sweep REAPS before leaving the rest to
        // the next tick.
        //
        // A reap is an HKEYS plus an EXISTS per connection before it finds any
        // ghost at all, blocking, on the single event loop that serves every
        // connection this worker holds, with `enable_coroutine` off by default.
        // Unbounded, a worker holding ten thousand presence channels spends
        // tens of thousands of serial round trips inside one tick and answers
        // nothing while it does.
        //
        // Sweeps resume where the last one stopped, so bounding this delays a
        // channel's reap rather than starving it: the rows it removes are
        // ghosts either way, and one found a rotation later is still found.
        //
        // IT DOES NOT BOUND THE LIVENESS MARKERS, and it used to. Those are
        // racing a wall-clock TTL rather than waiting their turn: a marker not
        // rewritten within connection_ttl_seconds is gone, and the member
        // behind it, connected and subscribed, is reaped by whichever worker
        // sweeps that channel next. So every channel this worker holds has its
        // markers rewritten on EVERY tick, whatever this is set to.
        'sweep_max_channels_per_tick' => (int) env('LIGHTSPEED_PRESENCE_SWEEP_MAX_CHANNELS_PER_TICK', 100),

        // How many liveness markers go into one pipeline. Not a per-tick
        // budget: every marker is rewritten on every tick, and this bounds only
        // how much of that pass is in flight at once, so a worker holding a very
        // large number of presence connections does not hand Redis a single
        // write it has to buffer whole.
        //
        // The marker pass itself is the remaining ceiling, and no dial here
        // makes one worker carry more of it. See docs/PRODUCTION.md for the
        // measured cost per marker and the point at which to add workers.
        'sweep_chunk_size' => (int) env('LIGHTSPEED_PRESENCE_SWEEP_CHUNK_SIZE', 500),

        // How many reaped members one sweep tells `connection_closed_handlers`
        // about before leaving the rest to the next tick.
        //
        // The channel budget above bounds channels, and one channel can hold
        // any number of abandoned members: a worker OOM-killed while holding a
        // large presence channel leaves all of them to be reaped in a single
        // call. Application handlers run inline on this worker's event loop, so
        // this is the ceiling on how many times one tick can enter them.
        //
        // Nothing is dropped. The reap happens regardless, and members past
        // this ceiling are reported on the following ticks, so the only cost of
        // lowering it is how late a late notification is.
        'sweep_max_notifications_per_tick' => (int) env('LIGHTSPEED_PRESENCE_SWEEP_MAX_NOTIFICATIONS_PER_TICK', 100),

        // How long a membership is believed without being vouched for again,
        // and therefore how long a killed connection stays in snapshots. It is
        // floored at three sweep intervals: a marker that could lapse between
        // two ticks of a healthy worker would have that worker reap its own
        // live members.
        'connection_ttl_seconds' => (int) env('LIGHTSPEED_PRESENCE_CONNECTION_TTL_SECONDS', 60),

        // Backstop for the one case the markers cannot cover: every worker
        // holding a channel dying at once leaves rows with no worker left to
        // reconcile them. Any live member anywhere refreshes this on every
        // sweep, so it is only ever reached by a channel nobody is on.
        'channel_ttl_seconds' => (int) env('LIGHTSPEED_PRESENCE_CHANNEL_TTL_SECONDS', 3600),
    ],

    'connections' => [
        'redis_connection' => env('LIGHTSPEED_CONNECTIONS_REDIS_CONNECTION', env('LIGHTSPEED_RELAY_REDIS_CONNECTION', 'default')),

        // How long the cluster believes "socket X is being served by process
        // Y" without that process saying so again.
        //
        // The entry is a claim with an expiry date, not a record: a worker that
        // is SIGKILLed cannot delete the entries of the sockets it was holding,
        // and the TTL is what removes them. Which means a live connection's
        // entry has to be RENEWED, and renewing it is this sweeper's whole
        // job: without it, an entry written on the handshake expires an hour
        // later under a connection that is still open, still subscribed and
        // still receiving. An expired entry looks exactly like a closed one,
        // so every consumer, both probes and any application or operator tool
        // asking `ConnectionRegistry::metadata()`, would conclude that a
        // connected client did not exist.
        //
        // Floored at three sweep intervals: an entry that could lapse between
        // two ticks of a healthy worker would be that same failure with a
        // smaller number on it.
        'ttl_seconds' => (int) env('LIGHTSPEED_CONNECTIONS_TTL_SECONDS', 3600),

        // How often each worker says the connections it is holding are still
        // there. Every held connection is renewed on every tick, because one
        // that is skipped for long enough does not have its renewal delayed, it
        // loses its entry.
        'sweep_interval_ms' => (int) env('LIGHTSPEED_CONNECTIONS_SWEEP_INTERVAL_MS', 300000),

        // How many entries go into one pipeline. Not a per-tick budget: it
        // bounds how much of a pass is in flight at once, so a worker holding a
        // very large number of sockets does not hand Redis a single write it
        // has to buffer whole.
        'sweep_chunk_size' => (int) env('LIGHTSPEED_CONNECTIONS_SWEEP_CHUNK_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Message Authorization
    |--------------------------------------------------------------------------
    |
    | Opt-in, and opted into from your channel callbacks rather than from here.
    | A `Broadcast::channel()` callback that calls
    | `Lightspeed::tag(['user:42', 'project:7'])` signs those tags into the auth
    | string the client already has to present, and
    | `Lightspeed::revoke('project:7')` refuses the next message from every
    | connection carrying that tag and drops it out of the fan-out. A channel
    | callback that never calls tag() signs the same auth string it always did,
    | and messages on that channel are handled exactly as they were before,
    | with no Redis call added anywhere, on subscribe or per message.
    |
    | The per-message check is one uncached Redis MGET, always authoritative. If
    | Redis cannot answer, the message is REFUSED. There is deliberately no
    | mirror, no shared-memory table and no local cache: each of those is a
    | way to keep serving a revoked connection after something else has gone
    | wrong.
    |
    */

    'auth' => [
        'redis_connection' => env('LIGHTSPEED_AUTH_REDIS_CONNECTION', env('LIGHTSPEED_RELAY_REDIS_CONNECTION', 'default')),

        // How long a grant stays valid before the client must authorize again.
        //
        // This is a BACKSTOP, not the guarantee. Revocation is immediate in
        // both directions and does not wait for it. It is one dial bounding
        // three things: how long a revoked connection could keep RECEIVING if
        // the relay notification were lost, the blast radius of a Redis outage
        // during which nothing can be revoked, and how often clients
        // re-authorize (one /broadcasting/auth round trip and one database read
        // each). Shorter is safer and busier.
        // Lowering this is safe: see revocation_retention_floor_seconds for
        // what stops a shorter setting shortening the revocations that were
        // written for the longer grants still out there.
        'grant_lifetime_seconds' => (int) env('LIGHTSPEED_AUTH_GRANT_LIFETIME_SECONDS', 300),

        // The shortest time a revocation is kept in Redis.
        //
        // A revocation has to outlive every grant it invalidates, or the grant
        // simply comes back: the client still holds the auth string, and once
        // the revocation record lapses there is nothing left to refuse it.
        //
        // Normally the retention works itself out: every mint records the
        // lifetime it used, and a revoke keeps its record for twice the longest
        // lifetime anything has recently minted under. That is what makes
        // lowering the setting above, and rolling a deploy that lowers it,
        // safe: the revoking process no longer decides the window from its own
        // config while other processes hold longer grants.
        //
        // This is the floor under that, for when there is no record to read: a
        // flushed Redis, a key evicted under `maxmemory`, or the first revoke a
        // fresh deployment performs. It costs one small string per tag that was
        // actually revoked, and only until it expires.
        //
        // Set it to TWICE the longest grant lifetime any process in your fleet
        // mints under, including the version still draining during a rolling
        // deploy. It is a declaration, not a derived value: nothing a process
        // mints itself needs it, because a revoke already keeps its record for
        // twice its OWN lifetime whatever this says. What it is for is the one
        // case that doubling cannot reach, a peer minting longer grants while
        // the record is gone.
        //
        // A revoke that found no record logs a warning, and `lightspeed:doctor`
        // reads the record and reports whether this would cover it. Run this
        // Redis with `noeviction`.
        'revocation_retention_floor_seconds' => (int) env('LIGHTSPEED_AUTH_REVOCATION_RETENTION_FLOOR_SECONDS', 3600),

        // How often each worker looks for grants that have run out.
        //
        // This is what makes the lifetime above mean anything to a connection
        // that only LISTENS. Such a connection never sends a frame, so nothing
        // else in the server ever judges its grant. Without this sweep, a
        // lurker would keep receiving broadcasts long past its grant's
        // expiry, and the lifetime would be a promise with no enforcer.
        //
        // The sweep walks this worker's own subscriptions and compares one
        // integer each. DECIDING expiry needs no Redis, which is exactly why
        // the sweep still works during the outage that makes revocation
        // unreadable; unwinding a PRESENCE subscription does, because leaving
        // the member in the room is not an acceptable alternative. A cleanup
        // that fails is logged and the client is still told to re-authorize.
        //
        // This is the granularity of expiry: a connection can outlive its grant
        // by up to this long.
        'sweep_interval_ms' => (int) env('LIGHTSPEED_AUTH_SWEEP_INTERVAL_MS', 1000),

        // How many subscriptions one sweep will unwind before leaving the rest
        // to the next tick.
        //
        // A sweep runs in a timer body, and with `enable_coroutine` off (the
        // default) a blocking Redis round trip there stops the event loop. Ten
        // thousand presence grants expiring in the same second would otherwise
        // be ten thousand serial round trips inside one tick. What is left over
        // is swept on the next one, and a connection whose grant has expired is
        // refused on every message it sends in the meantime either way.
        'sweep_max_per_tick' => (int) env('LIGHTSPEED_AUTH_SWEEP_MAX_PER_TICK', 200),

        // Upper bound on the jittered re-subscribe hint sent with a stale
        // frame. Revoking a busy tag refuses every connection carrying it at
        // once; this is what stops them all re-authorizing in the same instant.
        'stale_retry_max_ms' => (int) env('LIGHTSPEED_AUTH_STALE_RETRY_MAX_MS', 2000),

        // Bounds on what tag() will accept, both REJECTING rather than
        // truncating. A tag silently cut to fit produces a connection that can
        // never be revoked and a revoke that can never land, neither of which
        // reports anything.
        'max_tag_length' => (int) env('LIGHTSPEED_AUTH_MAX_TAG_LENGTH', 191),

        // Each tag is one key in the per-message MGET and one more entry in a
        // string every client carries.
        'max_tags' => (int) env('LIGHTSPEED_AUTH_MAX_TAGS', 16),
    ],

    'owner_commands' => [
        'enabled' => (bool) env('LIGHTSPEED_OWNER_COMMANDS_ENABLED', true),
        'redis_connection' => env('LIGHTSPEED_OWNER_COMMANDS_REDIS_CONNECTION', env('LIGHTSPEED_RELAY_REDIS_CONNECTION', 'default')),
        'poll_interval_ms' => (int) env('LIGHTSPEED_OWNER_COMMANDS_POLL_INTERVAL_MS', 25),
        'read_count' => (int) env('LIGHTSPEED_OWNER_COMMANDS_READ_COUNT', 100),
        'response_ttl_seconds' => (int) env('LIGHTSPEED_OWNER_COMMANDS_RESPONSE_TTL_SECONDS', 30),
        // How long a caller waits for the owning worker's response when it can
        // YIELD while it waits, i.e. with `enable_coroutine` on. Size it above
        // the slowest owner command the application has.
        'wait_timeout_ms' => (int) env('LIGHTSPEED_OWNER_COMMANDS_WAIT_TIMEOUT_MS', 10000),
        'wait_interval_us' => (int) env('LIGHTSPEED_OWNER_COMMANDS_WAIT_INTERVAL_US', 10000),

        // And how long it waits when it CANNOT yield, which with
        // `enable_coroutine` off is always. That wait is a usleep() on the
        // single event loop serving every connection this worker holds, so the
        // ten seconds above would be ten seconds during which the server
        // answers no frame, no request and no timer. A forwarded command that
        // outruns this is failed rather than waited for; the caller gets a
        // structured error naming this setting, and a retry is recoverable in
        // a way that a frozen server is not.
        'blocking_wait_timeout_ms' => (int) env('LIGHTSPEED_OWNER_COMMANDS_BLOCKING_WAIT_TIMEOUT_MS', 250),

        // Hold the resource's write lease while the owning worker executes a
        // forwarded command, so a mutation arriving from another worker cannot
        // interleave with one the owner is already running.
        'write_lease' => (bool) env('LIGHTSPEED_OWNER_COMMANDS_WRITE_LEASE', true),

        // How often one worker will report the same problem with a NO-REPLY command
        // (`OwnerCommandBus::forwardWithoutReply()`), per resource and command,
        // in seconds.
        //
        // A no-reply command has no caller and no response key, so a log line is the
        // only report of a command that could not be routed or whose handler
        // threw. The rate limit is what stops that being one line per command on
        // a stream chosen for its volume, and it is a limit rather than a
        // once-per-worker mute so that a resource which breaks again tomorrow
        // can still say so.
        'no_reply_report_interval_seconds' => (int) env('LIGHTSPEED_OWNER_COMMANDS_NO_REPLY_REPORT_INTERVAL_SECONDS', 60),

        // How long a signed command or response stays deliverable, in seconds.
        //
        // Both directions carry a signed `issued_at`, and both ends refuse
        // anything further from now than this, in either direction, since the
        // two ends are different machines and either clock can be the fast one.
        // Commands drain a poll interval after they are written, so this is
        // enormously generous for the legitimate case; what it bounds is how
        // long a captured entry is worth keeping. INSIDE the window, replay is
        // stopped by the request id being SPENT rather than by the clock: the
        // owning worker claims `lightspeed:owner-command-seen:{id}` with SET NX
        // before it executes, so a signed command executes exactly once. Those
        // claim keys live for twice this value PLUS ONE SECOND and then expire
        // on their own, so nothing accumulates. The window is symmetric, so an
        // entry is acceptable across an interval two of these wide; at exactly
        // twice, the claim and the freshness check disagree by one second in
        // the direction that readmits the entry, and the extra second is what
        // makes the claim outlive the last instant its entry can be accepted.
        // See OwnerCommandBus::spentIdTtlSeconds().
        //
        // Lower it and clock skew between workers starts failing real commands;
        // raise it and a captured entry stays deliverable-once for longer.
        'freshness_seconds' => (int) env('LIGHTSPEED_OWNER_COMMANDS_FRESHNESS_SECONDS', 30),

        // Commands drain within a poll interval, so a healthy stream holds only
        // a handful of entries; these bound a stalled consumer's backlog and
        // let a dead process's stream expire instead of living forever.
        'max_entries' => (int) env('LIGHTSPEED_OWNER_COMMANDS_MAX_ENTRIES', 10000),
        'stream_ttl_seconds' => (int) env('LIGHTSPEED_OWNER_COMMANDS_STREAM_TTL_SECONDS', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pusher / Reverb Compatibility
    |--------------------------------------------------------------------------
    |
    | Lightspeed speaks the Pusher protocol. Credentials fall back to the
    | REVERB_* env vars so an app migrating from Reverb keeps working without
    | any env changes.
    |
    | WHERE CLIENTS DIAL, NOT WHERE THE SERVER BINDS. `public_scheme`,
    | `public_host` and `public_port` are the address a BROWSER uses. The
    | address this process listens on is `server.host` and `server.port`, above.
    | They are separate settings because behind a TLS terminator they are
    | genuinely different values: you bind 8000 on loopback and clients dial 443
    | over https, which is the shape deploy/nginx/two-hosts.conf.example
    | documents. Anything that tells a client or another process where to reach
    | this server reads the public triple; nothing reads the bind values for
    | that purpose.
    |
    | This is the same split Reverb makes, with the same meanings, so the
    | REVERB_* names fall through here too:
    |
    |   REVERB_SERVER_HOST / REVERB_SERVER_PORT   what the server binds
    |   REVERB_HOST / REVERB_PORT / REVERB_SCHEME what clients dial
    |
    | Reverb leaves REVERB_HOST with no default and defaults the other two to
    | 443 and https. Lightspeed instead falls back to APP_URL, which is already
    | the address the application tells browsers to use, so the single-origin
    | case needs no configuration at all. Set the LIGHTSPEED_PUBLIC_* or
    | REVERB_* vars when the realtime origin differs from APP_URL.
    |
    */

    'reverb_compat' => [
        'path_prefix' => env('LIGHTSPEED_REVERB_PATH_PREFIX', '/app'),
        'app_id' => env('LIGHTSPEED_APP_ID', env('REVERB_APP_ID')),
        'app_key' => env('LIGHTSPEED_APP_KEY', env('REVERB_APP_KEY')),
        'app_secret' => env('LIGHTSPEED_APP_SECRET', env('REVERB_APP_SECRET')),
        'activity_timeout' => (int) env('LIGHTSPEED_ACTIVITY_TIMEOUT', 30),
        'public_scheme' => env('LIGHTSPEED_PUBLIC_SCHEME', env('REVERB_SCHEME', $appScheme)),
        'public_host' => env('LIGHTSPEED_PUBLIC_HOST', env('REVERB_HOST', $appHost)),
        'public_port' => (int) env('LIGHTSPEED_PUBLIC_PORT', env('REVERB_PORT', $appPort)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Static File Serving
    |--------------------------------------------------------------------------
    |
    | Lightspeed uses a focused built-in extension => MIME lookup for common
    | web assets. Add or override entries here when an application needs a
    | different type for a given extension.
    |
    */

    'static_files' => [
        'mime_types' => [
            // 'wasm' => 'application/wasm',
            // 'webmanifest' => 'application/manifest+json',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostic Protocol
    |--------------------------------------------------------------------------
    |
    | Lightspeed speaks a second, non-Pusher protocol used only by the local
    | probe commands (`whoami`, `broadcast-test`, raw subscribe). It has NO
    | channel authorization by design: it is a local inspection tool, not a
    | client surface. It ships DISABLED, and even when enabled it only accepts
    | connections from the loopback addresses listed here.
    |
    | Never enable this on a socket reachable from the public internet.
    |
    */

    'diagnostics' => [
        'enabled' => (bool) env('LIGHTSPEED_DIAGNOSTICS_ENABLED', false),

        // Comma separated, so the gate can be narrowed or widened from the
        // environment like everything else that decides who may reach what.
        //
        // Widening this past loopback is the one change here that can turn a
        // local inspection tool into a client surface. Read the caveat in
        // docs/configuration.md about reverse proxies before you do.
        'allow_from' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LIGHTSPEED_DIAGNOSTICS_ALLOW_FROM', '127.0.0.1,::1')),
        ), static fn (string $address): bool => $address !== '')),
    ],

    'logging' => [
        'http' => (bool) env('LIGHTSPEED_LOG_HTTP', false),
        'websocket' => (bool) env('LIGHTSPEED_LOG_WEBSOCKET', false),
        'payloads' => (bool) env('LIGHTSPEED_LOG_PAYLOADS', false),
        'payload_limit' => (int) env('LIGHTSPEED_LOG_PAYLOAD_LIMIT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Handlers
    |--------------------------------------------------------------------------
    |
    | Keep protocol/runtime concerns in the core server and push app-specific
    | client events into dedicated handlers. Each entry is a class name
    | implementing the matching Lightspeed contract; handlers run in order and
    | the first non-null result wins.
    |
    | `connection_closed_handlers` is the exception, because it notifies rather
    | than decides. There is no result to return, so no handler answers on
    | another's behalf: every handler that resolves and implements the contract
    | is run, and one that cannot be resolved, does not implement it, or throws
    | is logged and stepped over rather than stopping the rest.
    |
    | It is also BEST EFFORT, and an application that needs correctness still
    | needs TTLs or leases. A killed worker runs no teardown, so its connections
    | produce no event at all; a presence member is reported later and thinner
    | with reason `swept`; and handlers only ever run for connections that held
    | a channel or a grant. See docs/extending.md.
    |
    */

    'client_event_handlers' => [
        // \App\Lightspeed\Handlers\MyClientEventHandler::class,
    ],

    'connection_closed_handlers' => [
        // \App\Lightspeed\Handlers\MyConnectionClosedHandler::class,
    ],

    'owner_command_handlers' => [
        // \App\Lightspeed\OwnerCommands\MyOwnerCommandHandler::class,
    ],

];
