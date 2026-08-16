<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- laravel-echo reads the CSRF token off this tag and puts it on the
         /broadcasting/auth request. Without it that POST is a 419 and no
         private or presence channel can ever subscribe. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Lightspeed · Laravel Echo</title>
    @vite('resources/js/app.js')
    <style>
        body { font: 15px/1.6 ui-sans-serif, system-ui, sans-serif; margin: 0; background: #0b0d12; color: #e6e9ef; }
        main { max-width: 760px; margin: 0 auto; padding: 40px 24px 64px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #8b93a7; margin: 0 0 32px; }
        section { background: #141822; border: 1px solid #222838; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: #8b93a7; margin: 0 0 12px; }
        input { background: #0b0d12; border: 1px solid #2b3346; color: inherit; border-radius: 7px; padding: 9px 12px; font: inherit; width: 100%; box-sizing: border-box; }
        ul { list-style: none; margin: 0; padding: 0; }
        li { padding: 5px 0; border-bottom: 1px solid #1c2130; }
        li:last-child { border-bottom: 0; }
        .log { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; max-height: 260px; overflow: auto; }
        .ok { color: #6ee7a8; }
        .bad { color: #ff8f8f; }
        .dim { color: #8b93a7; }
        .you { color: #7cc4ff; }
        code { color: #c3a6ff; }
    </style>
</head>
<body>
<main>
    <h1>Lightspeed · the documented Laravel Echo path</h1>
    <p class="sub">
        You are user <strong class="you" id="whoami">{{ $userId }}</strong>.
        This page runs <code>resources/js/echo.js</code>, which is the snippet in
        <code>docs/configuration.md</code> verbatim. Open a second tab to see presence and whispers; open a
        <strong>private/incognito window</strong> to see them between two different people, since one
        browser session is one identity.
    </p>

    <section>
        <h2>Connection <span class="dim" id="conn">booting…</span></h2>
        <ul class="log" id="config"></ul>
    </section>

    <section>
        <h2>Presence · <code>Echo.join('lobby')</code> <span class="dim" id="presence-status">…</span></h2>
        <ul id="members"></ul>
    </section>

    <section>
        <h2>Private · <code>Echo.private('inbox.{{ $userId }}')</code> <span class="dim" id="private-status">…</span></h2>
        <p class="dim" style="margin:-6px 0 0">
            Proves the auth round trip on a private channel as well as a presence one.
            Push to it with <code>php artisan hello:inbox {{ $userId }} "ping"</code>.
        </p>
        <ul class="log" id="inbox"></ul>
    </section>

    <section>
        <h2>Client event · <code>whisper('say')</code> → Laravel handler <span class="dim" id="roundtrip"></span></h2>
        <input id="say" placeholder="Type something and press enter" autocomplete="off">
        <ul class="log" id="messages"></ul>
    </section>

    <section>
        <h2>Peer whisper · <code>whisper('typing')</code> / <code>listenForWhisper('typing')</code></h2>
        <p class="dim" style="margin:-6px 0 0">
            No server handler claims <code>client-typing</code>, so it falls through to plain
            Pusher peer relay and only the <em>other</em> tab sees it.
        </p>
        <input id="typing" placeholder="Type here; the other tab logs it" autocomplete="off">
        <ul class="log" id="whispers"></ul>
    </section>

    <section>
        <h2>Server broadcast · <code>listen('.hello')</code></h2>
        <p class="dim" style="margin:-6px 0 0">Run <code>php artisan hello:broadcast "anyone there?"</code></p>
        <ul class="log" id="broadcasts"></ul>
    </section>
</main>

<script>
    // Everything this page observes also goes into window.PROOF, so the run can
    // be inspected from the console or a driver without scraping the DOM.
    window.PROOF = [];

    document.addEventListener('DOMContentLoaded', () => {
        const userId = @json($userId);
        const $ = (id) => document.getElementById(id);

        function log(target, text, cls) {
            const li = document.createElement('li');
            li.textContent = text;
            if (cls) li.className = cls;
            $(target).prepend(li);
            window.PROOF.push([new Date().toISOString(), target, text]);
            console.log('[echo]', target, text);
        }

        if (!window.Echo) {
            $('conn').textContent = 'window.Echo was never created';
            $('conn').className = 'bad';
            return;
        }

        // What the documented snippet actually resolved to at build time. If any
        // of these are empty or NaN, the env wiring in the docs is wrong.
        const pusher = window.Echo.connector.pusher;
        const opts = window.Echo.options;
        log('config', 'echo.options: ' + JSON.stringify({
            broadcaster: opts.broadcaster, key: opts.key, wsHost: opts.wsHost,
            wsPort: opts.wsPort, wssPort: opts.wssPort, forceTLS: opts.forceTLS,
            enabledTransports: opts.enabledTransports,
        }));
        log('config', 'pusher resolved: ' + JSON.stringify({
            host: pusher.config.wsHost, port: pusher.config.wsPort,
            wssPort: pusher.config.wssPort, useTLS: pusher.config.useTLS,
            cluster: pusher.config.cluster, authEndpoint: pusher.config.authEndpoint,
        }));

        pusher.connection.bind('connected', () => {
            $('conn').textContent = 'connected · socket ' + pusher.connection.socket_id;
            $('conn').className = 'ok';
            window.PROOF.push([new Date().toISOString(), 'conn', 'connected ' + pusher.connection.socket_id]);
            console.log('[echo] connected', pusher.connection.socket_id);
        });
        pusher.connection.bind('error', (e) => {
            $('conn').textContent = 'error: ' + JSON.stringify(e);
            $('conn').className = 'bad';
            console.error('[echo] connection error', e);
        });
        pusher.connection.bind('state_change', (s) => {
            console.log('[echo] state', s.previous, '->', s.current);
            window.PROOF.push([new Date().toISOString(), 'state', s.previous + ' -> ' + s.current]);
        });

        // --- presence -------------------------------------------------------
        let here = [];
        const render = () => {
            $('members').innerHTML = '';
            here.forEach((m) => {
                const li = document.createElement('li');
                li.textContent = m.name + (String(m.id) === String(userId) ? ' (you)' : '');
                if (String(m.id) === String(userId)) li.className = 'you';
                $('members').appendChild(li);
            });
            $('presence-status').textContent = here.length + ' member(s)';
        };

        const lobby = window.Echo.join('lobby')
            .here((members) => {
                here = members;
                render();
                log('config', 'presence here(): ' + JSON.stringify(members), 'ok');
            })
            .joining((m) => { here.push(m); render(); log('config', 'presence joining(): ' + JSON.stringify(m), 'ok'); })
            .leaving((m) => {
                here = here.filter((x) => String(x.id) !== String(m.id));
                render();
                log('config', 'presence leaving(): ' + JSON.stringify(m), 'dim');
            })
            .error((e) => { $('presence-status').textContent = 'auth failed'; $('presence-status').className = 'bad'; console.error('[echo] presence error', e); log('config', 'presence error: ' + JSON.stringify(e), 'bad'); });

        // Broadcast() from the server. The leading dot means "this is the exact
        // event name", which is what a raw broadcast(['presence-lobby'], 'hello')
        // puts on the wire.
        lobby.listen('.hello', (data) => log('broadcasts', 'broadcast: ' + data.message, 'ok'));
        lobby.listen('.said', (m) => {
            const mine = String(m.from) === String(userId);
            log('messages', `${m.at}  user ${m.from}${mine ? ' (you)' : ''}: ${m.message}`, mine ? 'you' : 'ok');
        });

        // The handler's reply comes back only on the socket that asked.
        const pending = {};
        lobby.listen('.lightspeed:response', (frame) => {
            log('messages', 'response: ' + JSON.stringify(frame), 'you');
            const started = pending[frame.requestId];
            if (started === undefined) return;
            delete pending[frame.requestId];
            $('roundtrip').textContent = `· round trip ${Math.round(performance.now() - started)}ms`;
        });

        $('say').addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' || !e.target.value.trim()) return;
            const requestId = Math.random().toString(36).slice(2);
            pending[requestId] = performance.now();
            // Echo's whisper() sends `client-say`, which is the event
            // App\Realtime\EchoHandler answers.
            lobby.whisper('say', { requestId, message: e.target.value.trim() });
            log('messages', 'whisper(say) sent: ' + e.target.value.trim(), 'dim');
            e.target.value = '';
        });

        // --- peer whisper ---------------------------------------------------
        lobby.listenForWhisper('typing', (m) => log('whispers', 'listenForWhisper(typing): ' + JSON.stringify(m), 'ok'));
        $('typing').addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' || !e.target.value.trim()) return;
            lobby.whisper('typing', { from: userId, text: e.target.value.trim() });
            log('whispers', 'whisper(typing) sent: ' + e.target.value.trim(), 'dim');
            e.target.value = '';
        });

        // --- private --------------------------------------------------------
        window.Echo.private('inbox.' + userId)
            .listen('.mail', (data) => log('inbox', 'private mail: ' + JSON.stringify(data), 'ok'))
            .error((e) => { $('private-status').textContent = 'auth failed'; $('private-status').className = 'bad'; console.error('[echo] private error', e); });

        pusher.channel('private-inbox.' + userId)?.bind('pusher:subscription_succeeded', () => {
            $('private-status').textContent = 'subscribed';
            $('private-status').className = 'ok';
        });
        pusher.bind('pusher:subscription_succeeded', () => {});
        pusher.connection.bind('message', (m) => {
            if (m.event === 'pusher_internal:subscription_succeeded') {
                console.log('[echo] subscribed', m.channel);
                window.PROOF.push([new Date().toISOString(), 'subscribed', m.channel]);
                if (m.channel === 'private-inbox.' + userId) {
                    $('private-status').textContent = 'subscribed';
                    $('private-status').className = 'ok';
                }
            }
        });
    });
</script>
</body>
</html>
