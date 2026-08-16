#!/usr/bin/env bash
#
# Build and run the Lightspeed hello world.
#
#   ./install.sh          create ./hello-world and serve it
#   ./install.sh --clean  delete ./hello-world
#
# Needs PHP 8.2+ with ext-swoole, Composer, and a Redis. That Redis defaults to
# 127.0.0.1:6379; set REDIS_HOST and REDIS_PORT to point somewhere else, and both
# the preflight below and the generated app's .env follow them.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE="$(cd "$HERE/.." && pwd)"
APP="$HERE/hello-world"
PORT="${PORT:-8000}"
export PORT

# Resolved once, here, rather than defaulted separately in each place that
# needs them, and written into the generated .env below. If the exported
# variables and the .env disagreed, a non-default Redis would work for
# `lightspeed:serve` (which inherits the export through `exec`) and for
# nothing else: `hello:broadcast` runs in a fresh shell that reads .env, and
# a demo whose halves talk to two different Redis servers shows up as a
# broadcast that silently reaches nobody.
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
export REDIS_HOST REDIS_PORT

if [[ "${1:-}" == "--clean" ]]; then
    rm -rf "$APP"
    echo "Removed $APP"
    exit 0
fi

echo "==> Checking what you need"
command -v composer >/dev/null || { echo "composer not found" >&2; exit 1; }
# Asked of PHP directly rather than grepping `php -m`: under `set -o pipefail`,
# `grep -q` closes the pipe on its first match and the SIGPIPE that gives php
# fails the whole pipeline, which reports a missing extension that is present.
php -r 'exit(extension_loaded("swoole") ? 0 : 1);' || { echo "PHP is missing ext-swoole" >&2; exit 1; }
# Asked over a socket rather than by running redis-cli: plenty of machines run
# Redis in Docker, or install it without putting the CLI on PATH, and testing
# for the binary reports "Redis is down" on a perfectly healthy server.
php -r '
    $host = getenv("REDIS_HOST");
    $port = (int) getenv("REDIS_PORT");
    $socket = @fsockopen($host, $port, $errno, $error, 2);
    if (!$socket) {
        fwrite(STDERR, "Redis is not answering on {$host}:{$port} ({$error})\n");
        exit(1);
    }
    fwrite($socket, "PING\r\n");
    if (trim((string) fgets($socket)) !== "+PONG") {
        fwrite(STDERR, "Something is listening on {$host}:{$port}, but it did not answer PING like Redis\n");
        exit(1);
    }
' || exit 1
echo "    php, composer, swoole, redis"

if [[ -d "$APP" ]]; then
    echo "==> Reusing the app already in ./hello-world (./install.sh --clean to start over)"
else
    echo "==> Creating a Laravel app"
    composer create-project laravel/laravel "$APP" "11.*" --prefer-dist --no-interaction --quiet

    cd "$APP"

    echo "==> Installing Lightspeed"
    composer config repositories.lightspeed path "$PACKAGE" --no-interaction
    composer config minimum-stability dev --no-interaction
    composer require innerloop-dev/lightspeed:@dev --no-interaction --quiet

    echo "==> Wiring it up (the install step from the main README)"
    # The credentials go in BEFORE lightspeed:install runs. That command keeps
    # any non-empty values it finds and only generates what is missing, and this
    # demo needs these exact ones: hello:broadcast signs with them, and the Vite
    # build below bakes the key into the browser bundle. Random generated
    # credentials would leave the two halves unable to talk.
    # BROADCAST_CONNECTION is not written here: the scaffold already has that
    # line and lightspeed:install rewrites it in place, where appending a second
    # assignment would leave a duplicate key.
    cat >> .env <<ENV

LIGHTSPEED_APP_ID=hello-world
LIGHTSPEED_APP_KEY=hello-world-key
LIGHTSPEED_APP_SECRET=hello-world-secret
LIGHTSPEED_SERVER_PORT=$PORT
LIGHTSPEED_WORKER_NUM=2

# Where BROWSERS dial. config/lightspeed.php defaults these from APP_URL, but
# Vite reads this file directly and knows nothing about that PHP fallback, so
# the VITE_ vars below need them spelled out. See docs/configuration.md.
LIGHTSPEED_PUBLIC_SCHEME=http
LIGHTSPEED_PUBLIC_HOST=localhost
LIGHTSPEED_PUBLIC_PORT=$PORT

VITE_LIGHTSPEED_APP_KEY="\${LIGHTSPEED_APP_KEY}"
VITE_LIGHTSPEED_SCHEME="\${LIGHTSPEED_PUBLIC_SCHEME}"
VITE_LIGHTSPEED_HOST="\${LIGHTSPEED_PUBLIC_HOST}"
VITE_LIGHTSPEED_PORT="\${LIGHTSPEED_PUBLIC_PORT}"
ENV

    # Not silenced like the other wiring: this is the command the README tells
    # people to run, so its report is worth reading, and if it fails the reason
    # must not vanish into /dev/null.
    php artisan lightspeed:install --no-interaction

    # The app and the realtime server are one origin here, so APP_URL has to
    # carry the port as well: it is what /broadcasting/auth and the docs'
    # public-triple fallback are read from.
    #
    # REDIS_HOST and REDIS_PORT are REWRITTEN IN PLACE rather than appended,
    # because the Laravel scaffold already writes both near the top of .env and
    # a second assignment further down is a duplicate key whose precedence is
    # nobody's business to depend on. Every process that reads .env, and not
    # only the one that inherits this script's exported variables, has to reach
    # the same Redis: the relay is the only thing connecting the workers to
    # `php artisan hello:broadcast`.
    php -r '$c = file_get_contents(".env");
        $c = preg_replace("~^APP_URL=.*$~m", "APP_URL=http://localhost:".getenv("PORT"), $c, 1);
        $c = preg_replace("~^REDIS_HOST=.*$~m", "REDIS_HOST=".getenv("REDIS_HOST"), $c, 1);
        $c = preg_replace("~^REDIS_PORT=.*$~m", "REDIS_PORT=".getenv("REDIS_PORT"), $c, 1);
        file_put_contents(".env", $c);'

    # lightspeed:install already published config/lightspeed.php; this only
    # registers the demo's handler class in it.
    php -r '$c = file_get_contents("config/lightspeed.php");
        $c = preg_replace("/\x27client_event_handlers\x27 => \[/", "\x27client_event_handlers\x27 => [\n        \\\\App\\\\Realtime\\\\EchoHandler::class,", $c, 1);
        file_put_contents("config/lightspeed.php", $c);'

    echo "==> Copying the demo (eight small files)"
    mkdir -p app/Realtime app/Console/Commands
    cp "$HERE/stubs/EchoHandler.php"      app/Realtime/EchoHandler.php
    cp "$HERE/stubs/BroadcastHello.php"   app/Console/Commands/BroadcastHello.php
    cp "$HERE/stubs/InboxHello.php"       app/Console/Commands/InboxHello.php
    cp "$HERE/stubs/channels.php"         routes/channels.php
    cp "$HERE/stubs/web.php"              routes/web.php
    cp "$HERE/stubs/welcome.blade.php"    resources/views/welcome.blade.php
    cp "$HERE/stubs/echo-page.blade.php"  resources/views/echo-page.blade.php
    # The documented Echo config, verbatim from docs/configuration.md. Nothing
    # else creates or imports this file (that was install:broadcasting's job,
    # and this script no longer runs it), so the import into the bundle is
    # added here too. Without it the /echo page builds fine and does nothing.
    cp "$HERE/stubs/echo.js"              resources/js/echo.js
    printf "\nimport './echo';\n" >> resources/js/app.js

    touch database/database.sqlite
    php artisan migrate --force --quiet
    php artisan optimize:clear >/dev/null
fi

cd "$APP"

# Built every run, not only on a fresh install: public/build is not kept, and
# the Echo page is a compiled bundle rather than a CDN script tag. Vite bakes
# the VITE_* values in at build time, so the server has to be (re)started after
# this or a long-lived worker keeps serving the previous manifest.
if command -v npm >/dev/null; then
    echo "==> Building the client assets (laravel-echo + pusher-js)"
    npm install --silent --no-audit --no-fund laravel-echo pusher-js >/dev/null
    npm run build --silent >/dev/null
else
    echo "!!  npm not found: skipping the asset build, so http://localhost:$PORT/echo will not work" >&2
fi

echo
echo "==> Serving on http://localhost:$PORT"
echo "      /       raw pusher-js, so you can see every frame"
echo "      /echo   real Laravel Echo, configured exactly as docs/configuration.md says"
echo "    Open either in two tabs. One browser session is one person, so open a"
echo "    private/incognito window when you want a second person. Then, in another terminal:"
echo "      cd $APP && php artisan hello:broadcast \"anyone there?\""
echo

exec php artisan lightspeed:serve --workers=2 --port="$PORT"
