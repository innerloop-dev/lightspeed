<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Support\Facades\Redis;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Boot\VersionConstraint;
use Lightspeed\Broadcasting\LightspeedBroadcaster;
use Lightspeed\Console\DoctorReport;
use Lightspeed\Contracts\ClientEventHandler;
use Lightspeed\Contracts\ConnectionClosedHandler;
use Lightspeed\Contracts\OwnerCommandHandler;
use Lightspeed\Server;

/**
 * One screen that says what is wrong with a Lightspeed installation.
 *
 * Nearly every failure a first-time user hits is a SETUP failure that surfaces
 * as something else entirely, which is why they are hard to search for:
 *
 *   - `composer require` installs no PHP Redis client, because predis is only
 *     a `suggest` and the README's "Redis" reads as the server. The app then
 *     explodes inside the relay at runtime instead of at install.
 *   - BROADCAST_CONNECTION is left on another driver, so Laravel registers the
 *     application's `Broadcast::channel()` callbacks on that driver's
 *     broadcaster and the app's own channel rules never run.
 *   - A handler class is pasted into a config file that was never published,
 *     so client events are accepted and silently do nothing.
 *   - The app secret is empty, which made every private channel forgeable.
 *
 * So every check here either passes or prints the file, env var or command
 * that fixes it, on the same screen. Never a stack trace, never "something is
 * wrong". A broken installation exits non-zero.
 *
 * This deliberately overlaps with the validation `Server::serve()` performs at
 * boot. Serve refuses to start; doctor says the same thing earlier, next to
 * everything else, before the user has worked out that the server is the thing
 * they should have been reading the output of.
 *
 * The environment facts, PHP version, loaded extensions, whether Redis
 * answers, are read through overridable methods so the tests can describe a
 * broken machine without being run on one.
 *
 * Owns: which checks run, and what each one concludes.
 * Deliberately does not own: how the conclusion is printed (Console\DoctorReport)
 * or how a Composer version constraint is read (Boot\VersionConstraint).
 */
class LightspeedDoctor extends Command
{
    protected $signature = 'lightspeed:doctor
        {--json : Emit the report as JSON and nothing else}
        {--fix : Also print the exact commands that fix each problem. ADVISORY ONLY: nothing is run}';

    protected $description = 'Check that this application is set up to run Lightspeed';

    /** The status vocabulary is defined once, by the thing that renders it. */
    private const PASS = DoctorReport::PASS;

    private const WARN = DoctorReport::WARN;

    private const FAIL = DoctorReport::FAIL;

    /** Cached ping result per Redis connection name, so six usages are not six round trips. */
    private array $pings = [];

    /** @var array{broadcaster: ?object, error: ?string}|null */
    private ?array $resolvedBroadcaster = null;

    public function handle(): int
    {
        $checks = $this->runChecks();

        $counts = [
            self::PASS => 0,
            self::WARN => 0,
            self::FAIL => 0,
        ];

        foreach ($checks as $check) {
            $counts[$check['status']]++;
        }

        $report = new DoctorReport($this->output);

        if ($this->option('json')) {
            $report->json($checks, $counts);

            return $counts[self::FAIL] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $report->render($checks, $counts, (bool) $this->option('fix'));

        return $counts[self::FAIL] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array{name: string, status: string, detail: string, note: ?string, fix: ?string}>
     */
    private function runChecks(): array
    {
        return array_merge(
            [
                $this->checkPhpVersion(),
                $this->checkSwoole(),
                $this->checkRedisClient(),
            ],
            $this->checkRedisConnections(),
            [
                $this->checkCredentials(),
                $this->checkBroadcastDriver(),
                $this->checkBroadcastConnection(),
                $this->checkChannelRoutes(),
                $this->checkClientEventHandlers(),
                $this->checkHandlerClasses(),
                $this->checkPublicAddress(),
                $this->checkPageScheme(),
                $this->checkWorkers(),
                $this->checkCoroutines(),
                $this->checkGrantLifetime(),
                $this->checkRevocationFloor(),
            ],
        );
    }

    /**
     * The floor the package advertises, read from its own composer.json rather
     * than hardcoded here, so the two cannot drift apart.
     */
    private function checkPhpVersion(): array
    {
        $version = $this->phpVersion();
        $constraints = new VersionConstraint;
        $constraint = $constraints->packagePhp();

        if ($constraint === null) {
            return $this->warnCheck(
                'PHP',
                $version.', constraint unknown',
                'The package composer.json could not be read, so the PHP floor was not checked.',
            );
        }

        if (! $constraints->satisfies($version, $constraint)) {
            return $this->failCheck(
                'PHP',
                $version.' does not satisfy '.$constraint,
                'Lightspeed requires PHP '.$constraint.'. Upgrade PHP, or check that the `php` on your PATH is the one running artisan.',
            );
        }

        return $this->passCheck('PHP', $version.' satisfies '.$constraint);
    }

    private function checkSwoole(): array
    {
        $version = $this->swooleVersion();

        if ($version === null) {
            return $this->failCheck(
                'Swoole',
                'extension not loaded',
                'Install ext-swoole (`pecl install swoole`) and enable it in the php.ini this PHP binary reads. `php --ini` prints which one that is.',
            );
        }

        return $this->passCheck('Swoole', $version);
    }

    /**
     * The one the reviews flagged hardest.
     *
     * "Redis" in the README means the SERVER, and composer.json lists predis
     * only under `suggest`, so `composer require innerloop-dev/lightspeed` installs
     * NEITHER client. A perfectly healthy Redis is then reachable by every tool
     * on the machine except the application, which fails at runtime inside the
     * relay rather than at install time where somebody is watching.
     */
    private function checkRedisClient(): array
    {
        $extRedis = $this->extRedisVersion();
        $predis = $this->predisAvailable();

        if ($extRedis === null && ! $predis) {
            return $this->failCheck(
                'PHP Redis client',
                'neither ext-redis nor predis/predis is installed',
                'Run `composer require predis/predis`, or install the ext-redis extension. Lightspeed lists predis under `suggest`, so installing the package installs neither, and a running Redis server is not the same thing as a client that can talk to it.',
            );
        }

        $found = [];

        if ($extRedis !== null) {
            $found[] = 'ext-redis '.$extRedis;
        }

        if ($predis) {
            $found[] = 'predis/predis';
        }

        return $this->passCheck('PHP Redis client', implode(', ', $found));
    }

    /**
     * Every connection this package actually uses, and they are not all
     * `default`.
     *
     * relay, presence, resources, connections, owner_commands and auth each
     * fall back to LIGHTSPEED_RELAY_REDIS_CONNECTION before `default`, so a
     * deployment that sets only the relay connection silently moves revocation
     * state along with it. Which connection each one resolved to is reported
     * whether it passes or not, because that is the fact an operator cannot
     * otherwise see.
     *
     * Checked through PHP, never redis-cli: plenty of machines run Redis in
     * Docker or without the CLI on PATH, and this has to answer the question
     * the application will ask, through the client the application will use.
     *
     * @return array<int, array>
     */
    private function checkRedisConnections(): array
    {
        $usages = [
            'relay' => 'lightspeed.relay.redis_connection',
            'presence' => 'lightspeed.presence.redis_connection',
            'resources' => 'lightspeed.resources.redis_connection',
            'connections' => 'lightspeed.connections.redis_connection',
            'owner commands' => 'lightspeed.owner_commands.redis_connection',
            'auth' => 'lightspeed.auth.redis_connection',
        ];

        $hasClient = $this->extRedisVersion() !== null || $this->predisAvailable();

        $checks = [];

        // Six usages very often resolve to one connection, and six identical
        // paragraphs of fix is the kind of wall this command exists to avoid.
        // Every usage still gets its own row, because which connection each one
        // resolved to is the fact worth seeing; only the repeated fix is
        // replaced by a pointer at the row that already carries it.
        $reported = [];

        foreach ($usages as $usage => $configKey) {
            $connection = (string) config($configKey, 'default');
            $name = 'Redis ('.$usage.')';

            if (! $hasClient) {
                $checks[] = $this->failCheck(
                    $name,
                    "connection '{$connection}', not checked",
                    isset($reported['-no-client-'])
                        ? 'Install a PHP Redis client first (see above).'
                        : 'Install a PHP Redis client first (see the PHP Redis client check above); until then nothing in this process can reach Redis, whichever connection it is pointed at.',
                );

                $reported['-no-client-'] = $name;

                continue;
            }

            $error = $this->cachedPing($connection);

            if ($error !== null) {
                $checks[] = $this->failCheck(
                    $name,
                    "connection '{$connection}' did not answer",
                    isset($reported[$connection])
                        ? "Same '{$connection}' connection as ".$reported[$connection].' above.'
                        : 'Redis said: '.$error.' Start Redis, or point the `'.$connection.'` connection at a reachable server in the `redis` section of config/database.php.',
                );

                $reported[$connection] ??= $name;

                continue;
            }

            $checks[] = $this->passCheck($name, "connection '{$connection}' answered PING");
        }

        return $checks;
    }

    /**
     * An auth string is `key:hmac(secret, "{socketId}:{channel}")`, so an empty
     * secret made every private and presence channel forgeable by anybody who
     * had the public app key.
     *
     * All three are required, and they are required by DIFFERENT things, which
     * the fix text has to say correctly because it is the sentence somebody acts
     * on. `Server::serve()` refuses to boot without the key or the secret and
     * checks neither the app id (Boot\BootValidation::validateCredentials).
     *
     * THE APP ID IS AN ADDRESS, NOT A CREDENTIAL. It appears nowhere in an auth
     * string, and `Broadcasting\LightspeedBroadcaster::assertCanSign()`
     * deliberately excludes it: an application must not be taken down over a
     * value signing never reads. So an empty app id lets the server start,
     * lets `Broadcast::channel()` register, and lets `/broadcasting/auth` go on
     * returning signed grants. What it breaks is publishing. The Pusher publish
     * endpoint is `/apps/{id}/events`, and with the id empty that URL is
     * `/apps//events`, which `Protocol\PusherPaths::appIdFromEventsPath()` does
     * not match, so the server 404s it: every server-side `broadcast()` is
     * refused and nothing reaches a subscriber.
     *
     * THIS TEXT USED TO SAY THE OPPOSITE, that an empty app id fails every
     * channel-auth request and throws during boot in any application whose
     * routes/channels.php calls `Broadcast::channel()`. Commit 2389851 removed
     * exactly that defect and this sentence outlived it, which sent anyone
     * debugging an empty app id at the one subsystem still working.
     */
    private function checkCredentials(): array
    {
        $required = [
            'app_id' => 'LIGHTSPEED_APP_ID',
            'app_key' => 'LIGHTSPEED_APP_KEY',
            'app_secret' => 'LIGHTSPEED_APP_SECRET',
        ];

        $missing = [];

        foreach ($required as $setting => $envVar) {
            if ((string) config("lightspeed.reverb_compat.{$setting}", '') === '') {
                $missing[] = $envVar;
            }
        }

        if ($missing !== []) {
            $consequence = in_array('LIGHTSPEED_APP_SECRET', $missing, true) || in_array('LIGHTSPEED_APP_KEY', $missing, true)
                ? 'With the secret empty every private and presence channel signature can be computed by anyone holding the public app key, and `lightspeed:serve` refuses to start without the key or the secret.'
                : 'The app id is an address rather than a credential, so signing does not read it: the server starts and /broadcasting/auth keeps issuing grants. What breaks is publishing. The endpoint is /apps/{id}/events, an empty id makes that /apps//events, and the server refuses it with a 404, so every server-side broadcast() reaches nobody.';

            return $this->failCheck(
                'App credentials',
                'empty: '.implode(', ', $missing),
                'Set '.implode(' and ', $missing).' in .env (the REVERB_APP_* names are accepted too). '.$consequence,
            );
        }

        return $this->passCheck('App credentials', 'app id, key and secret are set');
    }

    /**
     * Does the DEFAULT broadcast connection resolve to Lightspeed's
     * broadcaster?
     *
     * Laravel registers `Broadcast::channel()` callbacks on the default
     * connection's broadcaster instance, so with another driver default the
     * application's own channel rules are registered somewhere nothing reads
     * them, and every authorization decision is made by code the app did not
     * write.
     *
     * THIS USED TO TEST THE CONNECTION NAME, and the name is not the fact.
     * `LightspeedServiceProvider::boot()` calls `Broadcast::extend('lightspeed',
     * ...)`, which registers a DRIVER; a connection named `realtime` whose
     * driver is `lightspeed` resolves to exactly the same LightspeedBroadcaster,
     * carries the same callbacks, and authorizes identically. The old check
     * failed that deployment twice and then printed "Lightspeed will not work
     * until the lines above are fixed" at somebody whose setup worked. Same
     * class of error as the boot check removed in 2f69009: refusing a safe
     * configuration is a worse failure than not checking, because the operator
     * goes and breaks something that was right.
     *
     * So it resolves the thing and looks at what came back. That also puts the
     * broadcaster's own construction on this command's path, which is the point:
     * a broadcaster that cannot be built is the single most consequential
     * failure in this list, and it is reported here as a row rather than as a
     * stack trace.
     */
    private function checkBroadcastDriver(): array
    {
        $default = (string) config('broadcasting.default', '');
        $resolved = $this->broadcaster();

        if ($resolved['error'] !== null) {
            return $this->failCheck(
                'Broadcast driver',
                "connection '{$default}' could not be resolved",
                'Resolving it failed with: '.$resolved['error'].' Nothing can authorize a channel until that is fixed, and any routes/channels.php that calls Broadcast::channel() hits the same failure while the application is booting, which takes down every artisan command and every web request with it.',
            );
        }

        if (! $resolved['broadcaster'] instanceof LightspeedBroadcaster) {
            $actual = $resolved['broadcaster'] === null
                ? 'nothing'
                : $resolved['broadcaster']::class;

            return $this->failCheck(
                'Broadcast driver',
                "connection '{$default}' resolves to {$actual}",
                'Set BROADCAST_CONNECTION=lightspeed in .env, or point it at any connection in config/broadcasting.php whose `driver` is `lightspeed`: the connection NAME does not matter, only its driver. Laravel registers Broadcast::channel() callbacks on the default connection\'s broadcaster, so until this is right your channel rules never run.',
            );
        }

        return $this->passCheck(
            'Broadcast driver',
            "connection '{$default}' resolves to the Lightspeed broadcaster",
        );
    }

    /**
     * The ordering trap. The connection has to exist in
     * `config/broadcasting.php` BEFORE BROADCAST_CONNECTION names it, or
     * Laravel throws on the first thing that resolves a broadcaster.
     *
     * Read against whatever `broadcasting.default` names, for the reason above:
     * this row is about the connection the application actually uses, not about
     * one particular spelling of its name.
     */
    private function checkBroadcastConnection(): array
    {
        $default = (string) config('broadcasting.default', '');
        $connection = config('broadcasting.connections.'.$default);
        $snippet = "'{$default}' => ['driver' => 'lightspeed'],";

        if (! is_array($connection)) {
            return $this->failCheck(
                'Broadcast connection',
                "no '{$default}' connection in config/broadcasting.php",
                'Add '.$snippet.' to the `connections` array in config/broadcasting.php, and do it BEFORE pointing BROADCAST_CONNECTION at it. Laravel throws when the named connection does not exist yet.',
            );
        }

        $driver = (string) ($connection['driver'] ?? '');

        if ($driver !== 'lightspeed') {
            return $this->failCheck(
                'Broadcast connection',
                "connection '{$default}' has driver '{$driver}'",
                // The snippet ends in a comma (it is a pasteable array
                // entry), so it ends the sentence: anything appended after
                // it produced a comma splice in operator-facing text.
                "Set BROADCAST_CONNECTION=lightspeed, or set the '{$default}' connection in config/broadcasting.php to ".$snippet,
            );
        }

        return $this->passCheck('Broadcast connection', "'{$default}' is defined with the lightspeed driver");
    }

    /**
     * Without at least one `Broadcast::channel()` definition every private and
     * presence subscribe is refused, and the client is told only that
     * authorization failed.
     *
     * THIS USED TO BE A REGEX OVER THE FILE, and a regex cannot read PHP. Run
     * against a routes/channels.php whose every definition sits inside a line or
     * block comment, it printed "routes/channels.php defines channels PASS"
     * and "All checks passed", on an application where nothing could subscribe
     * to anything. Commenting a channel out while debugging and forgetting to
     * put it back is not an exotic mistake.
     *
     * `Broadcaster::getChannels()` is public and holds what the file actually
     * registered, on the instance that will actually be asked at authorization
     * time. Asking it also folds in the case where the definitions ran but ran
     * against some other connection's broadcaster, which no file search can see.
     *
     * The file path is still read, but only to choose the fix text: "you have no
     * such file" and "your file registered nothing" need different sentences.
     */
    private function checkChannelRoutes(): array
    {
        $resolved = $this->broadcaster();

        if ($resolved['broadcaster'] === null) {
            return $this->warnCheck(
                'Channel routes',
                'not checked, the broadcast driver did not resolve',
                'Channel definitions live on the default connection\'s broadcaster, so there is nothing to ask until the Broadcast driver row above is fixed. Run this again afterwards.',
            );
        }

        // `getChannels()` answers an Illuminate Collection on current Laravel
        // and a plain array on older ones, and `(array)` on a Collection is the
        // object's PROPERTIES, not its items: two of them, unconditionally, so
        // the count was 2 and the check passed no matter what was registered.
        $channels = method_exists($resolved['broadcaster'], 'getChannels')
            ? $resolved['broadcaster']->getChannels()
            : [];

        $count = $channels instanceof \Illuminate\Support\Collection
            ? $channels->count()
            : count((array) $channels);

        if ($count > 0) {
            return $this->passCheck(
                'Channel routes',
                $count.' channel '.($count === 1 ? 'definition' : 'definitions').' registered',
            );
        }

        if (! is_file($this->channelsRoutePath())) {
            return $this->failCheck(
                'Channel routes',
                'no channel definitions registered, and routes/channels.php does not exist',
                'Run `php artisan lightspeed:install` to create routes/channels.php and register it, then define your channels in it. Without a Broadcast::channel definition every private and presence subscribe is refused.',
            );
        }

        return $this->failCheck(
            'Channel routes',
            'routes/channels.php registered no channels',
            'The file exists and the broadcaster came back with nothing, so every Broadcast::channel(...) in it is commented out, unreachable, or registering against a different broadcast connection. Until one runs, every private and presence subscribe is refused and the client is told only that authorization failed.',
        );
    }

    /**
     * The single biggest newcomer trap: someone pastes the README's handler
     * class, sends a message, nothing happens, and there is no error anywhere
     * to search for. Most often the config file was never published, so the
     * file they edited is not the file that is read.
     */
    private function checkClientEventHandlers(): array
    {
        $handlers = array_values((array) config('lightspeed.client_event_handlers', []));

        if (! $this->configFilePublished()) {
            return $this->warnCheck(
                'Client event handlers',
                'config/lightspeed.php has not been published',
                'Run `php artisan vendor:publish --tag=lightspeed-config`, then list your handler class in `client_event_handlers`. Until the file exists the package defaults are used, and a handler class pasted anywhere else is never read.',
            );
        }

        if ($handlers === []) {
            return $this->warnCheck(
                'Client event handlers',
                'none registered',
                'Add your handler class to `client_event_handlers` in config/lightspeed.php. Without one a client event is accepted, handled by nobody, and reports no error. Which looks exactly like a broken connection.',
            );
        }

        return $this->passCheck('Client event handlers', count($handlers).' registered');
    }

    /**
     * The same class-and-contract check `Server::serve()` refuses to start on,
     * reported here first. Handlers are resolved lazily, so a typo otherwise
     * stays invisible until the first client event arrives in production.
     */
    private function checkHandlerClasses(): array
    {
        $contracts = [
            'client_event_handlers' => ClientEventHandler::class,
            'connection_closed_handlers' => ConnectionClosedHandler::class,
            'owner_command_handlers' => OwnerCommandHandler::class,
        ];

        $total = 0;

        foreach ($contracts as $configKey => $contract) {
            foreach ((array) config('lightspeed.'.$configKey, []) as $handler) {
                if (! is_string($handler) || $handler === '') {
                    return $this->failCheck(
                        'Handler classes',
                        "`{$configKey}` contains something that is not a class name",
                        "Every entry in `{$configKey}` in config/lightspeed.php must be a handler class name, for example MyHandler::class.",
                    );
                }

                if (! class_exists($handler)) {
                    return $this->failCheck(
                        'Handler classes',
                        $handler.' does not exist',
                        "`{$configKey}` in config/lightspeed.php names {$handler}, and no such class can be autoloaded. Check the namespace and the file name, then run `composer dump-autoload`.",
                    );
                }

                if (! is_subclass_of($handler, $contract)) {
                    return $this->failCheck(
                        'Handler classes',
                        $handler.' does not implement the contract',
                        "{$handler} must implement {$contract}. The server refuses to start until it does.",
                    );
                }

                $total++;
            }
        }

        if ($total === 0) {
            return $this->passCheck('Handler classes', 'none configured');
        }

        return $this->passCheck('Handler classes', $total.' exist and implement their contract');
    }

    /**
     * Is the pair "where clients dial" and "where this process binds" a
     * deployment that can actually work?
     *
     * `reverb_compat.public_scheme/host/port` is the address a BROWSER uses.
     * `server.host`/`server.port` is the socket this process listens on. Reverb
     * makes the identical split (REVERB_HOST/PORT/SCHEME against
     * REVERB_SERVER_HOST/PORT) and for the identical reason: behind a TLS
     * terminator they are different values, and a package that only had the
     * bind values could not describe the normal production shape.
     *
     * The triple is what every client-facing path reads, so the check is about
     * the triple, and APP_URL is only the fallback it defaults from.
     *
     * TWO FINDINGS, and only one of them is a problem.
     *
     * A public port that DIFFERS from the bind port is the proxy topology and
     * is fine; it is reported so an operator can see both numbers, because
     * "which of these two ports did I mean" is the question this whole split
     * creates.
     *
     * A public scheme of `https` on the SAME port the server binds cannot work.
     * Lightspeed's listener does not terminate TLS: there is no certificate
     * setting anywhere in the `server` block, because terminating TLS is the
     * proxy's job in every topology this package documents. So that combination
     * points browsers at a plaintext socket over TLS and every handshake fails.
     * It warns rather than fails because the doctor cannot see the proxy: an
     * operator who binds 443 behind a terminator on the same port is describing
     * something unusual but not impossible.
     */
    private function checkPublicAddress(): array
    {
        $scheme = (string) config('lightspeed.reverb_compat.public_scheme', 'http');
        $publicHost = (string) config('lightspeed.reverb_compat.public_host', 'localhost');
        $publicPort = (int) config('lightspeed.reverb_compat.public_port', 80);
        $bindHost = (string) config('lightspeed.server.host', '0.0.0.0');
        $bindPort = (int) config('lightspeed.server.port', 8000);

        $public = $scheme.'://'.$publicHost.':'.$publicPort;
        $bind = $bindHost.':'.$bindPort;

        if ($scheme === 'https' && $publicPort === $bindPort) {
            return $this->warnCheck(
                'Public address',
                "clients dial {$public}, server binds {$bind}",
                'Lightspeed does not terminate TLS, so nothing is listening for a TLS handshake on '.$bindPort.'. Either put a terminator in front and set LIGHTSPEED_PUBLIC_PORT (or REVERB_PORT) to the port it listens on, or set LIGHTSPEED_PUBLIC_SCHEME (or REVERB_SCHEME) to http. See deploy/nginx/two-hosts.conf.example.',
                'Only wrong if clients reach this port directly. A terminator that proxies '.$bindPort.' back to '.$bindPort.' is unusual but works.',
            );
        }

        if ($publicPort !== $bindPort) {
            return $this->passCheck(
                'Public address',
                "clients dial {$public}, server binds {$bind}",
                'The two differ, which is what a TLS terminator or reverse proxy in front looks like. Everything client-facing follows the public triple: your Echo config, and the --host/--port defaults of the probe commands.',
            );
        }

        return $this->passCheck('Public address', "clients dial {$public}, server binds {$bind}");
    }

    /**
     * The commonest way a correct-looking production deployment has no realtime
     * at all, and the one this command scored "All checks passed" on.
     *
     * `APP_URL=https://chat.example.com` with `LIGHTSPEED_PUBLIC_SCHEME=http`
     * builds an Echo config that dials `ws://chat.example.com`, from a page the
     * browser loaded over TLS. Every browser blocks that as mixed content and
     * refuses to open the socket, before any request reaches this server, so
     * there is nothing in any server log to find. The symptom is "Echo just
     * never connects", which is exactly the shape of failure this command
     * exists for.
     *
     * ONLY THE SCHEME. checkPublicAddress() deliberately dropped the APP_URL
     * comparison in 2f69009, and it was right to: the comparison it dropped was
     * against the BIND PORT, and a public port that differs from the bind port,
     * or from APP_URL's, is the ordinary terminator topology and must never be a
     * failure. What was never put back is the one comparison that is a real
     * hazard, which is between the scheme of the page and the scheme of the
     * socket the page is told to open.
     *
     * Only one direction is broken. An https socket opened from an http page is
     * allowed everywhere and is what a partial TLS rollout looks like, so it
     * passes and is merely reported.
     */
    private function checkPageScheme(): array
    {
        $publicScheme = strtolower((string) config('lightspeed.reverb_compat.public_scheme', 'http'));
        $appUrl = (string) config('app.url', '');
        $appScheme = strtolower((string) (parse_url($appUrl, PHP_URL_SCHEME) ?: ''));

        if ($appScheme === '') {
            return $this->warnCheck(
                'Page scheme',
                'APP_URL names no scheme',
                'Set APP_URL to the full URL browsers load your application from, starting http:// or https://. It is what the public triple defaults from, and without it this cannot tell whether the socket your pages are told to open would be blocked as mixed content.',
            );
        }

        if ($appScheme === 'https' && $publicScheme !== 'https') {
            return $this->failCheck(
                'Page scheme',
                "pages are served over https, clients are told to dial {$publicScheme}",
                'A page loaded over https may not open a ws:// socket: the browser blocks it as mixed content and Echo never connects, with nothing reaching this server and nothing in any log. Set LIGHTSPEED_PUBLIC_SCHEME=https (or REVERB_SCHEME) and terminate TLS in front of the server, see deploy/nginx/two-hosts.conf.example. Only the SCHEME has to match APP_URL; a public port that differs from it is the normal terminator shape and is fine.',
            );
        }

        if ($appScheme !== $publicScheme) {
            return $this->passCheck(
                'Page scheme',
                "pages are served over {$appScheme}, clients dial {$publicScheme}",
                'A wss:// socket from an http page is allowed by every browser, so this works. It is what a half-finished TLS rollout looks like, which is worth knowing if you did not intend it.',
            );
        }

        return $this->passCheck('Page scheme', "pages and sockets are both {$appScheme}");
    }

    private function checkWorkers(): array
    {
        $workers = (int) config('lightspeed.server.worker_num', 1);

        $note = $workers <= 1
            ? 'One process serves every connection this server holds, so anything that blocks in a handler blocks all of them. Raise it with `lightspeed:serve --workers=N` or LIGHTSPEED_WORKER_NUM.'
            : 'Each worker holds its own sockets and reaches the others only through the Redis relay, so cross-worker delivery depends on the relay being healthy.';

        return $this->passCheck('Workers', (string) $workers, $note);
    }

    private function checkCoroutines(): array
    {
        $enabled = (bool) config('lightspeed.server.enable_coroutine', false);

        $note = $enabled
            ? 'Every callback runs inside a coroutine, so the host application handles requests concurrently inside one process, a Laravel container, its singletons and its facade roots are not built for that. The auth manager is one of those singletons: the guard reset at each task boundary is process-wide, so a suspended request can have its authentication state reset by any concurrent client event or close.'
            : 'The package\'s bounded waits are real usleep() on the event loop that serves every connection on the worker, which is what `owner_commands.blocking_wait_timeout_ms` and `resources.blocking_write_lease_retries` are sized for.';

        return $this->passCheck('Coroutines', $enabled ? 'enabled' : 'disabled', $note);
    }

    private function checkGrantLifetime(): array
    {
        $lifetime = (int) config('lightspeed.auth.grant_lifetime_seconds', 300);

        if ($lifetime < 1) {
            return $this->failCheck(
                'Grant lifetime',
                $lifetime.'s',
                'Set LIGHTSPEED_AUTH_GRANT_LIFETIME_SECONDS to a positive number of seconds.',
            );
        }

        if ($lifetime > Server::MAX_GRANT_LIFETIME_SECONDS) {
            return $this->failCheck(
                'Grant lifetime',
                $lifetime.'s is past the '.Server::MAX_GRANT_LIFETIME_SECONDS.'s ceiling',
                'Set LIGHTSPEED_AUTH_GRANT_LIFETIME_SECONDS to a value in seconds under '.Server::MAX_GRANT_LIFETIME_SECONDS.'. It is handed to Redis as an expiry when a grant is minted, so a value Redis will not accept fails every tagged /broadcasting/auth request instead of failing at boot.',
            );
        }

        return $this->passCheck('Grant lifetime', $lifetime.'s');
    }

    /**
     * Would a revocation still outlive the longest grants this deployment has
     * actually minted, if the high-water mark were lost?
     *
     * THE CHECK THIS REPLACES WAS AIMED AT NOTHING. It refused a `floor` below
     * `grant_lifetime_seconds * 2`, and `Server::serve()` refused to boot on
     * the same condition. But REVOKE_SCRIPT computes
     * `ttl = max(max(own dial, mark) * 2, floor)`, so a process's own grants
     * are covered by `own dial * 2` whatever the floor is, and the floor is
     * consulted only when it is LARGER. Measured against real Redis with the
     * mark deleted: dial 7200 against floor 3600 keeps the revocation 14400s,
     * not the 3600s the docblock claimed, while dial 300 against the same floor
     * keeps it 3600s and lets a peer's 7200s grants outlive it by an hour. The
     * refused configuration was safe; the passing one was the hole.
     *
     * The hole is about a PEER's dial, and the mark is the only thing that ever
     * carries that fact. So this reads the mark instead of reasoning about
     * config, which is why the check lives here and not at boot: this command
     * already talks to Redis and is allowed to find nothing, where a server
     * that refused to start because Redis was briefly unreachable would be a
     * worse failure than the one being prevented.
     *
     * A WARN and not a FAIL. The mark is present in the normal case and the
     * retention is correct while it is; what is being reported is the size of
     * the window if it is evicted or flushed, which is a risk to size rather
     * than a broken setting. Run this Redis with `noeviction`.
     *
     * @return array<string, mixed>
     */
    private function checkRevocationFloor(): array
    {
        $lifetime = (int) config('lightspeed.auth.grant_lifetime_seconds', 300);
        $floor = (int) config('lightspeed.auth.revocation_retention_floor_seconds', 3600);

        // What a revoke served by THIS process would keep a record for with no
        // mark to read. Exactly the Lua, with `mark` absent.
        $fallback = max($lifetime * 2, $floor);

        $connection = RevocationLog::connectionNameFor(app('config'));
        $pingError = $this->cachedPing($connection);
        $highWater = null;
        $unreadable = $pingError;

        if ($pingError === null) {
            try {
                $highWater = $this->grantLifetimeHighWater($connection);
            } catch (\Throwable $throwable) {
                $message = trim($throwable->getMessage());
                $unreadable = $message === '' ? $throwable::class : rtrim($message, '.').'.';
            }
        }

        // UNKNOWN IS NOT FINE, and this row used to say it was. With Redis
        // unreachable the mark cannot be read, `grantLifetimeHighWater()`
        // swallowed the error and answered null, and the check PASSED with
        // "nothing has minted a tagged grant on this deployment", a statement
        // about the whole fleet, made with no evidence whatsoever, printed word
        // for word at a deployment that mints 7200s grants all day. The two
        // facts are different and only one of them is reassuring.
        if ($unreadable !== null) {
            return $this->warnCheck(
                'Revocation floor',
                $floor.'s, and the high-water mark could not be read',
                'Redis on the `'.$connection.'` connection said: '.$unreadable.' So whether the floor covers the longest grants this deployment mints is UNKNOWN, not fine. Fix the Redis rows above and run this again. Without the mark, a revoke served by this process keeps its record for '.$fallback.'s, and any grant minted anywhere under a longer lifetime outlives it.',
            );
        }

        if ($highWater === null) {
            return $this->passCheck(
                'Revocation floor',
                $floor.'s, covers grants up to '.$fallback.'s',
                'Redis answered and holds no grant-lifetime high-water mark, so nothing has minted a tagged grant on this deployment recently. Once something does, this reports whether the mark being lost would leave a gap.',
            );
        }

        if ($fallback < $highWater) {
            return $this->warnCheck(
                'Revocation floor',
                $floor.'s falls back to '.$fallback.'s, under the '.$highWater.'s grants this deployment mints',
                'Set LIGHTSPEED_AUTH_REVOCATION_RETENTION_FLOOR_SECONDS to at least '.($highWater * 2).'. While the high-water mark is in Redis the retention is correct; if it is evicted under `maxmemory` or flushed, a revoke served by this process keeps its record for '.$fallback.'s while grants minted elsewhere live '.$highWater.'s, and the identical auth string is readmitted for the difference. Run this Redis with `noeviction`.',
            );
        }

        return $this->passCheck(
            'Revocation floor',
            $floor.'s, covers the '.$highWater.'s grants this deployment mints',
        );
    }

    // --- Environment seams -------------------------------------------------

    protected function phpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * Asked of PHP directly rather than by grepping `php -m`, for the reason
     * example/install.sh:26-33 records: under `set -o pipefail`, `grep -q`
     * closes the pipe on its first match and the SIGPIPE that gives php fails
     * the whole pipeline, reporting a missing extension that is present.
     */
    protected function swooleVersion(): ?string
    {
        if (! extension_loaded('swoole')) {
            return null;
        }

        $version = phpversion('swoole');

        return $version === false ? 'unknown version' : $version;
    }

    protected function extRedisVersion(): ?string
    {
        if (! extension_loaded('redis')) {
            return null;
        }

        $version = phpversion('redis');

        return $version === false ? 'unknown version' : $version;
    }

    protected function predisAvailable(): bool
    {
        return class_exists(\Predis\Client::class);
    }

    /**
     * Ask the connection the same way the application will: through the
     * configured Laravel Redis manager and whichever client it is using.
     *
     * Returns null when the connection answered, or the reason it did not. A
     * connection name that is not configured at all reports that, which is a
     * different problem from a Redis that is down and needs a different fix.
     */
    protected function pingRedis(string $connection): ?string
    {
        try {
            Redis::connection($connection)->ping();

            return null;
        } catch (\Throwable $throwable) {
            $message = trim($throwable->getMessage());

            return $message === '' ? $throwable::class : rtrim($message, '.').'.';
        }
    }

    /**
     * The longest grant lifetime anything on this deployment has recently
     * minted under, or null if there is no mark to read.
     *
     * Read through the same Laravel Redis manager the package writes it with,
     * so the key prefix applies identically.
     *
     * IT THROWS, and it used to swallow. Catching here returned null, null
     * means "no mark", and "no mark" is the evidence for a sentence claiming
     * nothing on this deployment has ever minted a tagged grant. A failed read
     * is not that evidence. The caller distinguishes them.
     */
    protected function grantLifetimeHighWater(string $connection): ?int
    {
        $value = Redis::connection($connection)->get(RevocationLog::HIGH_WATER_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The broadcaster the application's own `Broadcast::channel()` callbacks
     * were registered on: the default connection's, resolved exactly as the
     * `/broadcasting/auth` request will resolve it.
     */
    protected function resolveDefaultBroadcaster(): object
    {
        return app(BroadcastingFactory::class)->connection();
    }

    protected function configFilePublished(): bool
    {
        return is_file(config_path('lightspeed.php'));
    }

    protected function channelsRoutePath(): string
    {
        return base_path('routes/channels.php');
    }

    // --- Internals ---------------------------------------------------------

    /**
     * Resolve the default broadcaster once, and never let it take the command
     * down with it.
     *
     * Resolving is the only honest way to answer "is this the Lightspeed
     * broadcaster" and "what channels are registered", and it is also the most
     * throw-prone thing this command does: a missing credential, a connection
     * naming a driver no package provides, a broken custom broadcaster. Every
     * one of those is a finding, and a finding is a row. A command whose whole
     * promise is "never a stack trace" may not print one, and `--json`, whose
     * documented contract is a parseable `{ok, counts, checks}`, may not emit
     * one at anything reading it in CI.
     *
     * @return array{broadcaster: ?object, error: ?string}
     */
    private function broadcaster(): array
    {
        if ($this->resolvedBroadcaster === null) {
            try {
                $this->resolvedBroadcaster = [
                    'broadcaster' => $this->resolveDefaultBroadcaster(),
                    'error' => null,
                ];
            } catch (\Throwable $throwable) {
                $message = trim($throwable->getMessage());

                $this->resolvedBroadcaster = [
                    'broadcaster' => null,
                    'error' => $message === '' ? $throwable::class : rtrim($message, '.').'.',
                ];
            }
        }

        return $this->resolvedBroadcaster;
    }

    private function cachedPing(string $connection): ?string
    {
        if (! array_key_exists($connection, $this->pings)) {
            $this->pings[$connection] = $this->pingRedis($connection);
        }

        return $this->pings[$connection];
    }

    private function passCheck(string $name, string $detail, ?string $note = null): array
    {
        return ['name' => $name, 'status' => self::PASS, 'detail' => $detail, 'note' => $note, 'fix' => null];
    }

    private function warnCheck(string $name, string $detail, string $fix, ?string $note = null): array
    {
        return ['name' => $name, 'status' => self::WARN, 'detail' => $detail, 'note' => $note, 'fix' => $fix];
    }

    private function failCheck(string $name, string $detail, string $fix): array
    {
        return ['name' => $name, 'status' => self::FAIL, 'detail' => $detail, 'note' => null, 'fix' => $fix];
    }
}
