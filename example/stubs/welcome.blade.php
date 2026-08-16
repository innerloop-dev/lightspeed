<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Lightspeed hello world</title>
    <script src="https://js.pusher.com/8.4/pusher.min.js"></script>
    <style>
        body { font: 15px/1.6 ui-sans-serif, system-ui, sans-serif; margin: 0; background: #0b0d12; color: #e6e9ef; }
        main { max-width: 720px; margin: 0 auto; padding: 40px 24px 64px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #8b93a7; margin: 0 0 32px; }
        section { background: #141822; border: 1px solid #222838; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: #8b93a7; margin: 0 0 12px; }
        input { background: #0b0d12; border: 1px solid #2b3346; color: inherit; border-radius: 7px; padding: 9px 12px; font: inherit; width: 100%; box-sizing: border-box; }
        ul { list-style: none; margin: 0; padding: 0; }
        li { padding: 5px 0; border-bottom: 1px solid #1c2130; }
        li:last-child { border-bottom: 0; }
        .log { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; max-height: 220px; overflow: auto; }
        .ok { color: #6ee7a8; }
        .dim { color: #8b93a7; }
        .you { color: #7cc4ff; }
    </style>
</head>
<body>
<main>
    <h1>Lightspeed hello world</h1>
    <p class="sub">
        You are user <strong class="you">{{ $userId }}</strong>. A second tab is the same person on a
        second connection, because one browser session is one identity. To be a <em>second</em> person,
        open a <strong>private/incognito window</strong>, which gets a session cookie of its own.
    </p>

    <section>
        <h2>Presence <span class="dim" id="status">connecting…</span></h2>
        <ul id="members"></ul>
    </section>

    <section>
        <h2>Say something <span class="dim" id="roundtrip"></span></h2>
        <input id="say" placeholder="Type something and press enter" autocomplete="off">
        <ul class="log" id="messages"></ul>
    </section>

    <section>
        <h2>Broadcasts from the server</h2>
        <p class="dim" style="margin-top:-6px">Run <code>php artisan hello:broadcast "anyone there?"</code></p>
        <ul class="log" id="broadcasts"></ul>
    </section>
</main>

<script>
    const userId = @json($userId);
    const members = document.getElementById('members');
    const status = document.getElementById('status');

    const pusher = new Pusher(@json($appKey), {
        wsHost: window.location.hostname,
        wsPort: @json($port),
        forceTLS: false,
        enabledTransports: ['ws'],
        disableStats: true,
        authEndpoint: '/broadcasting/auth',
        // pusher-js refuses to construct without a cluster, even though
        // wsHost overrides the host it would have derived from one. The value
        // is never used against a self-hosted server; it just has to be there.
        cluster: 'lightspeed',
    });

    pusher.connection.bind('connected', () => {
        status.textContent = 'connected · socket ' + pusher.connection.socket_id;
        status.className = 'ok';
    });

    const channel = pusher.subscribe('presence-lobby');

    function render(list) {
        members.innerHTML = '';
        list.forEach((m) => {
            const li = document.createElement('li');
            li.textContent = m.name + (String(m.id) === String(userId) ? ' (you)' : '');
            if (String(m.id) === String(userId)) li.className = 'you';
            members.appendChild(li);
        });
    }

    let here = [];
    channel.bind('pusher:subscription_succeeded', (m) => {
        here = Object.values(m.members ?? {});
        render(here);
    });
    channel.bind('pusher:member_added', (m) => { here.push(m.info); render(here); });
    channel.bind('pusher:member_removed', (m) => {
        here = here.filter((x) => String(x.id) !== String(m.info.id));
        render(here);
    });

    function log(target, text, cls) {
        const li = document.createElement('li');
        li.textContent = text;
        if (cls) li.className = cls;
        target.prepend(li);
    }

    // Up the socket: a client event, answered by a Laravel handler.
    const pending = {};
    document.getElementById('say').addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || !e.target.value.trim()) return;

        const requestId = Math.random().toString(36).slice(2);
        pending[requestId] = performance.now();

        channel.trigger('client-say', { requestId, message: e.target.value.trim() });
        e.target.value = '';
    });

    // Everyone on the channel gets this, including whoever sent it. This is the
    // part that makes a second tab light up.
    channel.bind('said', (m) => {
        const mine = String(m.from) === String(userId);
        log(document.getElementById('messages'),
            `${m.at}  user ${m.from}${mine ? ' (you)' : ''}: ${m.message}`,
            mine ? 'you' : 'ok');
    });

    // Only the sender gets this, back on the socket it asked from. It is what
    // makes the exchange a round trip rather than a broadcast.
    channel.bind('lightspeed:response', (frame) => {
        const started = pending[frame.requestId];
        if (!started) return;

        delete pending[frame.requestId];
        document.getElementById('roundtrip').textContent =
            `· your last round trip: ${Math.round(performance.now() - started)}ms`;
    });

    channel.bind('hello', (data) => {
        log(document.getElementById('broadcasts'), `broadcast: ${data.message}`, 'ok');
    });
</script>
</body>
</html>
