<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Redis;
use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Auth\RevocationLog;
use Lightspeed\Boot\VersionConstraint;
use Lightspeed\Console\Commands\LightspeedDoctor;
use Lightspeed\Contracts\ClientEventHandler;

/**
 * `lightspeed:doctor` exists because every failure a first-time user actually
 * hits is a SETUP failure that surfaces as something else: a private channel
 * that silently never authorizes, a handler that silently never runs, a relay
 * that explodes at runtime because `composer require` installed no Redis
 * client. So every test here asserts on the MESSAGE, not only the exit code.
 * A non-zero exit that does not name the file, env var or command to change is
 * exactly the experience this command was written to remove.
 *
 * The environment facts, PHP version, loaded extensions, whether Redis
 * answers, are read through overridable methods so a test can state the broken
 * machine it is describing. `pingRedis()` is additionally exercised for real
 * against the Redis the rest of this suite already requires.
 */
final class FakeDoctor extends LightspeedDoctor
{
    public ?string $php = '8.2.0';

    public ?string $swoole = '6.0.0';

    public ?string $extRedis = '6.0.0';

    public bool $predis = false;

    /** Error text `pingRedis()` should report, keyed by connection name; '*' catches all. */
    public array $redisErrors = [];

    public bool $published = true;

    public string $channelsPath = '';

    protected function phpVersion(): string
    {
        return (string) $this->php;
    }

    protected function swooleVersion(): ?string
    {
        return $this->swoole;
    }

    protected function extRedisVersion(): ?string
    {
        return $this->extRedis;
    }

    protected function predisAvailable(): bool
    {
        return $this->predis;
    }

    protected function pingRedis(string $connection): ?string
    {
        return $this->redisErrors[$connection] ?? $this->redisErrors['*'] ?? null;
    }

    /** The fleet's recorded high-water grant lifetime, or null for "no mark". */
    public ?int $highWater = null;

    /**
     * A Redis that answers PING and then fails the read.
     *
     * "No mark" and "could not ask" are different facts and only one of them is
     * evidence that nothing has minted a grant, so the seam has to be able to
     * express both.
     */
    public bool $highWaterUnreadable = false;

    protected function grantLifetimeHighWater(string $connection): ?int
    {
        if ($this->highWaterUnreadable) {
            throw new \RuntimeException('READONLY You can\'t write against a read only replica.');
        }

        return $this->highWater;
    }

    protected function configFilePublished(): bool
    {
        return $this->published;
    }

    protected function channelsRoutePath(): string
    {
        return $this->channelsPath;
    }
}

/** A handler that satisfies the contract, for the case that should pass. */
final class DoctorFixtureHandler implements ClientEventHandler
{
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        return null;
    }
}

/** A class the config names as a handler that does not implement the contract. */
final class DoctorFixtureNotAHandler
{
}

/**
 * The configuration of an application that is set up correctly, so that each
 * test can break exactly one thing and nothing else.
 */
function doctorHealthyConfig(array $overrides = []): void
{
    config($overrides + [
        'broadcasting.default' => 'lightspeed',
        'broadcasting.connections.lightspeed' => ['driver' => 'lightspeed'],
        'lightspeed.reverb_compat.app_id' => 'test-app',
        'lightspeed.reverb_compat.app_key' => 'test-key',
        'lightspeed.reverb_compat.app_secret' => 'test-secret',
        'lightspeed.reverb_compat.public_host' => 'localhost',
        'lightspeed.reverb_compat.public_port' => 8000,
        'lightspeed.server.port' => 8000,
        'lightspeed.client_event_handlers' => [DoctorFixtureHandler::class],
        'lightspeed.owner_command_handlers' => [],
        'lightspeed.auth.grant_lifetime_seconds' => 300,
        'lightspeed.auth.revocation_retention_floor_seconds' => 3600,
    ]);

    doctorRegisterChannel();
}

/**
 * Register a channel the way an application's `routes/channels.php` does.
 *
 * This is what makes the channel-routes check answerable: the definitions live
 * on the DEFAULT connection's broadcaster instance, and asking that instance is
 * the only way to know whether a file that mentions `Broadcast::channel(` in
 * four comments actually registered anything.
 *
 * Wrapped, and the wrapping is not incidental. Resolving the broadcaster is
 * precisely what an empty credential set makes impossible, and in a real
 * application that throw happens while `bootstrap/app.php` is loading
 * `routes/channels.php`, during boot, before any artisan command exists to
 * catch it. The tests below that empty a credential on purpose still have to
 * reach the command, so they register nothing and say so.
 */
function doctorRegisterChannel(): void
{
    try {
        Broadcast::channel('doc.{id}', fn ($user, string $id) => true);
    } catch (\Throwable) {
        // See above: this is the LightspeedServiceProvider::pusherClient()
        // throw, and no change to the doctor can prevent it.
    }
}

/** Install a doctor whose view of the machine the test controls. */
function fakeDoctor(?callable $configure = null): FakeDoctor
{
    $doctor = new FakeDoctor;
    $doctor->channelsPath = __DIR__.'/../fixtures/channels.php';

    if ($configure !== null) {
        $configure($doctor);
    }

    app()->instance(LightspeedDoctor::class, $doctor);

    return $doctor;
}

/**
 * Run the doctor and hand back its exit code and output.
 *
 * `flat` is the same output with every run of whitespace collapsed, and it is
 * what the message assertions read. The command wraps a fix to the terminal
 * width, so asserting on raw output would make a test fail or pass on how wide
 * the terminal running it happens to be, which says nothing about the command.
 */
function runDoctor(array $parameters = []): array
{
    $status = Artisan::call('lightspeed:doctor', $parameters);
    $output = Artisan::output();

    return [
        'status' => $status,
        'output' => $output,
        'flat' => trim((string) preg_replace('/\s+/', ' ', $output)),
    ];
}

it('passes a correctly configured application', function () {
    doctorHealthyConfig();
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(0)
        ->and($result['flat'])->not->toContain('FAIL');
});

it('reports that neither PHP Redis client is installed, and how to install one', function () {
    doctorHealthyConfig();
    fakeDoctor(function (FakeDoctor $doctor) {
        // What `composer require innerloop-dev/lightspeed` actually leaves you with:
        // predis is only a `suggest`, so neither client is installed and the
        // README's "Redis" reads as the server, which is running fine.
        $doctor->extRedis = null;
        $doctor->predis = false;
    });

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('composer require predis/predis')
        ->and($result['flat'])->toContain('ext-redis');
});

it('reports an unreachable Redis against each connection the package uses', function () {
    doctorHealthyConfig();
    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->redisErrors = ['*' => 'Connection refused [tcp://127.0.0.1:6379]'];
    });

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('Connection refused');
});

it('names the connection each Redis usage resolves to, so a relay-only override is visible', function () {
    doctorHealthyConfig([
        // A deployment that sets only LIGHTSPEED_RELAY_REDIS_CONNECTION moves
        // revocation state with it, because auth falls back to the relay's
        // connection before `default`.
        'lightspeed.relay.redis_connection' => 'realtime',
        'lightspeed.auth.redis_connection' => 'realtime',
        'lightspeed.presence.redis_connection' => 'realtime',
        'lightspeed.resources.redis_connection' => 'realtime',
        'lightspeed.connections.redis_connection' => 'realtime',
        'lightspeed.owner_commands.redis_connection' => 'realtime',
    ]);
    fakeDoctor();

    $result = runDoctor(['--json' => true]);
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    $redis = array_values(array_filter(
        $report['checks'],
        fn (array $check) => str_starts_with($check['name'], 'Redis ')
    ));

    expect($redis)->toHaveCount(6);

    foreach ($redis as $check) {
        expect($check['detail'])->toContain('realtime');
    }
});

it('refuses an empty app secret and says what an empty one costs', function () {
    doctorHealthyConfig(['lightspeed.reverb_compat.app_secret' => '']);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('LIGHTSPEED_APP_SECRET');
});

it('reports a broadcast connection that is not lightspeed', function () {
    doctorHealthyConfig(['broadcasting.default' => 'reverb']);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('BROADCAST_CONNECTION=lightspeed');
});

it('reports the ordering trap when the connection is named before it is defined', function () {
    doctorHealthyConfig();
    config()->offsetUnset('broadcasting.connections.lightspeed');
    config(['broadcasting.connections' => array_diff_key(
        (array) config('broadcasting.connections', []),
        ['lightspeed' => true],
    )]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain("'lightspeed' => ['driver' => 'lightspeed']");
});

it('reports a handler class that does not exist', function () {
    doctorHealthyConfig([
        'lightspeed.client_event_handlers' => ['App\Lightspeed\Handlers\NotThere'],
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('App\Lightspeed\Handlers\NotThere');
});

it('reports a handler class that does not implement the contract', function () {
    doctorHealthyConfig([
        'lightspeed.client_event_handlers' => [DoctorFixtureNotAHandler::class],
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain(ClientEventHandler::class);
});

it('reports a connection-closed handler that does not implement the contract', function () {
    // The doctor is where a handler typo is meant to be caught, because the
    // close path's own answer is deliberately a log line: a handler that never
    // runs in production is silent everywhere else.
    doctorHealthyConfig([
        'lightspeed.connection_closed_handlers' => [DoctorFixtureNotAHandler::class],
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain(Lightspeed\Contracts\ConnectionClosedHandler::class);
});

it('warns, without failing, when no client event handler is registered', function () {
    doctorHealthyConfig(['lightspeed.client_event_handlers' => []]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(0)
        ->and($result['flat'])->toContain('WARN')
        ->and($result['flat'])->toContain('client_event_handlers');
});

it('gives the vendor:publish command when the config file was never published', function () {
    doctorHealthyConfig(['lightspeed.client_event_handlers' => []]);
    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->published = false;
    });

    $result = runDoctor();

    expect($result['flat'])->toContain('vendor:publish --tag=lightspeed-config');
});

it('reports a routes/channels.php with no channel definitions', function () {
    doctorHealthyConfig();

    // The file Laravel generates and the application never fills in: it exists,
    // and it registered nothing. Registration is the fact this check reads now,
    // so the fixture has to be paired with a broadcaster that was never told
    // about a channel.
    Broadcast::forgetDrivers();

    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->channelsPath = __DIR__.'/../fixtures/channels-without-definitions.php';
    });

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('Broadcast::channel');
});

it('sends you to lightspeed:install when there is no routes/channels.php at all', function () {
    doctorHealthyConfig();
    Broadcast::forgetDrivers();

    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->channelsPath = __DIR__.'/../fixtures/no-such-channels-file.php';
    });

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('lightspeed:install');
});

/**
 * The public triple (`reverb_compat.public_scheme/host/port`) is where CLIENTS
 * dial; `server.host`/`server.port` is where this process BINDS. Reverb makes
 * the same split for the same reason, and the reason is that behind a TLS
 * terminator the two are genuinely different values.
 *
 * So the check is about whether the pair is a describable deployment, not about
 * APP_URL on its own. APP_URL is only the fallback the public triple defaults
 * from when nothing else sets it.
 */
it('accepts a public port that differs from the bind port, which is what a proxy looks like', function () {
    doctorHealthyConfig([
        'lightspeed.reverb_compat.public_scheme' => 'https',
        'lightspeed.reverb_compat.public_port' => 443,
        'lightspeed.server.port' => 8000,
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(0)
        ->and($result['flat'])->toContain('443')
        ->and($result['flat'])->toContain('8000');
});

/**
 * The one combination that cannot work. Lightspeed's listener does not
 * terminate TLS, there is no certificate setting anywhere in `server`. so
 * `https` on the very port the server binds means browsers are dialling a
 * plaintext socket over TLS and every connection fails the handshake.
 */
it('warns when clients are told to dial TLS straight at the port the server binds', function () {
    doctorHealthyConfig([
        'lightspeed.reverb_compat.public_scheme' => 'https',
        'lightspeed.reverb_compat.public_port' => 8000,
        'lightspeed.server.port' => 8000,
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['flat'])->toContain('does not terminate TLS');
});

it('reports the public address clients dial and the address the server binds', function () {
    doctorHealthyConfig([
        'lightspeed.reverb_compat.public_scheme' => 'http',
        'lightspeed.reverb_compat.public_host' => 'localhost',
        'lightspeed.reverb_compat.public_port' => 8000,
        'lightspeed.server.port' => 8000,
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(0)
        ->and($result['flat'])->toContain('http://localhost:8000');
});

/**
 * A10. The floor check used to be `floor >= own lifetime * 2`, and that is a
 * condition about the one lifetime the floor is guaranteed NOT to be needed
 * for: REVOKE_SCRIPT already covers a process's own grants with
 * `own dial * 2`. The hole is a PEER minting longer grants than this process,
 * with the high-water mark gone, and no amount of config reading finds it.
 *
 * The mark itself is the evidence, and this command is the one place that both
 * talks to Redis and is allowed to be wrong about a transient. So it reads the
 * mark and compares it to what a revoke would fall back to without it.
 */
it('warns when losing the high-water mark would let the fleet\'s longest grants outlive a revocation', function () {
    doctorHealthyConfig([
        'lightspeed.auth.grant_lifetime_seconds' => 300,
        'lightspeed.auth.revocation_retention_floor_seconds' => 3600,
    ]);
    fakeDoctor(function (FakeDoctor $doctor) {
        // A rolling deploy that lowered the dial from 7200 to 300. Peers are
        // still holding 7200s grants; a revoke served by THIS process without
        // the mark keeps its record for max(300*2, 3600) = 3600s.
        $doctor->highWater = 7200;
    });

    $result = runDoctor();

    expect($result['flat'])->toContain('7200')
        ->and($result['flat'])->toContain('LIGHTSPEED_AUTH_REVOCATION_RETENTION_FLOOR_SECONDS')
        ->and($result['flat'])->toContain('14400');
});

it('does not warn about the revocation floor when it already covers the longest grant in the fleet', function () {
    doctorHealthyConfig([
        'lightspeed.auth.grant_lifetime_seconds' => 300,
        'lightspeed.auth.revocation_retention_floor_seconds' => 3600,
    ]);
    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->highWater = 1800;
    });

    $result = runDoctor();

    expect($result['status'])->toBe(0)
        ->and($result['flat'])->toContain('Revocation floor');
});

/**
 * The configuration the OLD check refused to start on, which was safe all
 * along: this process's own 3600s grants are covered by `3600 * 2` inside the
 * Lua, and the floor is never consulted.
 */
it('does not fail a grant lifetime above half the revocation floor', function () {
    doctorHealthyConfig([
        'lightspeed.auth.grant_lifetime_seconds' => 3600,
        'lightspeed.auth.revocation_retention_floor_seconds' => 3600,
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(0);
});

it('reports a PHP older than the constraint in the package composer.json', function () {
    doctorHealthyConfig();
    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->php = '8.1.0';
    });

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('8.1.0');
});

it('reports a missing Swoole extension', function () {
    doctorHealthyConfig();
    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->swoole = null;
    });

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('swoole');
});

it('reports worker_num and enable_coroutine with their consequences', function () {
    doctorHealthyConfig([
        'lightspeed.server.worker_num' => 1,
        'lightspeed.server.enable_coroutine' => false,
    ]);
    fakeDoctor();

    $result = runDoctor(['--json' => true]);
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    $notes = collect($report['checks'])->keyBy('name');

    expect($notes['Workers']['note'])->not->toBeNull()
        ->and($notes['Coroutines']['note'])->not->toBeNull();
});

it('emits a machine readable report under --json and nothing else', function () {
    doctorHealthyConfig(['lightspeed.reverb_compat.app_secret' => '']);
    fakeDoctor();

    $result = runDoctor(['--json' => true]);
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    expect($result['status'])->toBe(1)
        ->and($report)->toHaveKeys(['ok', 'counts', 'checks'])
        ->and($report['ok'])->toBeFalse()
        ->and($report['counts'])->toHaveKeys(['pass', 'warn', 'fail'])
        ->and($report['counts']['fail'])->toBeGreaterThan(0)
        ->and($report['checks'])->toBeArray();

    foreach ($report['checks'] as $check) {
        expect($check)->toHaveKeys(['name', 'status', 'detail', 'note', 'fix'])
            ->and($check['status'])->toBeIn(['pass', 'warn', 'fail']);
    }
});

it('prints the fixes without running them under --fix', function () {
    doctorHealthyConfig(['broadcasting.default' => 'null']);
    fakeDoctor();

    $result = runDoctor(['--fix' => true]);

    expect($result['flat'])->toContain('Nothing above was run')
        ->and($result['flat'])->toContain('BROADCAST_CONNECTION=lightspeed');
});

it('reaches the Redis this suite already requires', function () {
    doctorHealthyConfig();
    // The real implementation, not the fake: the point of this one is that the
    // ping the other tests describe is the ping the command actually performs.
    app()->instance(LightspeedDoctor::class, new class extends LightspeedDoctor
    {
        protected function channelsRoutePath(): string
        {
            return __DIR__.'/../fixtures/channels.php';
        }

        public function ping(string $connection): ?string
        {
            return $this->pingRedis($connection);
        }
    });

    $doctor = app(LightspeedDoctor::class);

    expect($doctor->ping('default'))->toBeNull();
});

/**
 * Same reasoning as the ping above, for the other seam that talks to Redis.
 *
 * The revocation floor tests drive `grantLifetimeHighWater()` through the fake,
 * so they describe what the command does with an answer without proving it can
 * get one. This reads the key the package actually writes, through the same
 * Laravel Redis manager, so the prefix and the connection fallback are exercised
 * rather than assumed.
 */
it('reads the grant lifetime high-water mark the package writes', function () {
    doctorHealthyConfig();

    app()->instance(LightspeedDoctor::class, new class extends LightspeedDoctor
    {
        protected function channelsRoutePath(): string
        {
            return __DIR__.'/../fixtures/channels.php';
        }

        public function highWater(string $connection): ?int
        {
            return $this->grantLifetimeHighWater($connection);
        }
    });

    $doctor = app(LightspeedDoctor::class);
    $connection = RevocationLog::connectionNameFor(app('config'));

    Redis::connection($connection)->del(RevocationLog::HIGH_WATER_KEY);

    expect($doctor->highWater($connection))->toBeNull();

    Redis::connection($connection)->setex(RevocationLog::HIGH_WATER_KEY, 60, '7200');

    try {
        expect($doctor->highWater($connection))->toBe(7200);
    } finally {
        Redis::connection($connection)->del(RevocationLog::HIGH_WATER_KEY);
    }
});

it('reports a Redis connection that is not configured at all', function () {
    doctorHealthyConfig(['lightspeed.relay.redis_connection' => 'nowhere']);

    app()->instance(LightspeedDoctor::class, new class extends LightspeedDoctor
    {
        protected function channelsRoutePath(): string
        {
            return __DIR__.'/../fixtures/channels.php';
        }
    });

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('nowhere');
});

/**
 * The PHP-version check reads the constraint out of the package's own
 * composer.json, and reads it with composer/semver when that is installed. An
 * application is not required to have composer/semver, so there is a fallback
 * parser. And a fallback that disagrees with Composer is worse than no check
 * at all, because this is the check that sends somebody off to upgrade PHP.
 *
 * So it is pinned against the real thing, for every constraint shape it claims
 * to understand.
 */
it('reads a version constraint the way composer/semver does', function () {
    if (! class_exists(Composer\Semver\Semver::class)) {
        $this->markTestSkipped('composer/semver is not installed, so there is nothing to pin against.');
    }

    $clause = new ReflectionMethod(VersionConstraint::class, 'clauseSatisfied');
    $clause->setAccessible(true);
    $constraints = new VersionConstraint;

    $clauses = ['^8.2', '^8.0', '^8', '~8.2', '~8.2.1', '~8', '>=8.1', '>8.2', '<8.3', '<=8.2', '=8.2.0', '8.2', '8.2.1'];
    $versions = ['7.4.0', '8.0.5', '8.1.30', '8.2.0', '8.2.1', '8.2.29', '8.3.0', '8.4.1', '9.0.0'];

    foreach ($clauses as $constraint) {
        foreach ($versions as $version) {
            expect($clause->invoke($constraints, $version, $constraint))
                ->toBe(
                    Composer\Semver\Semver::satisfies($version, $constraint),
                    "fallback disagrees with composer/semver for {$version} against {$constraint}",
                );
        }
    }
});

/**
 * FIX 2. The connection may be called anything.
 *
 * `Broadcast::extend('lightspeed', ...)` registers a DRIVER, so a connection
 * named `realtime` whose driver is `lightspeed` resolves to exactly the same
 * LightspeedBroadcaster that a connection named `lightspeed` does, carries the
 * same channel callbacks and authorizes identically. The doctor used to test
 * the connection NAME twice and told a working deployment, in bold red, that
 * "Lightspeed will not work until the lines above are fixed".
 */
it('accepts a working setup whose broadcast connection is not named lightspeed', function () {
    doctorHealthyConfig([
        'broadcasting.default' => 'realtime',
        'broadcasting.connections.realtime' => ['driver' => 'lightspeed'],
    ]);

    // The name `lightspeed` is not defined at all, which is the whole point.
    config(['broadcasting.connections' => array_diff_key(
        (array) config('broadcasting.connections', []),
        ['lightspeed' => true],
    )]);

    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(0)
        ->and($result['flat'])->not->toContain('FAIL');
});

/**
 * FIX 3. A regex cannot read PHP.
 *
 * `tests/fixtures/channels-commented-out.php` contains four
 * `Broadcast::channel(` occurrences and registers none of them. The old check
 * grepped the file, printed "routes/channels.php defines channels PASS", and
 * every private and presence subscribe on that application was refused with
 * nothing anywhere saying why. The broadcaster knows the answer: ask it.
 */
it('fails a routes/channels.php whose definitions are all commented out', function () {
    doctorHealthyConfig();

    // The file the doctor would grep, without the registration that grepping
    // it is standing in for.
    config(['broadcasting.default' => 'lightspeed']);
    Broadcast::forgetDrivers();

    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->channelsPath = __DIR__.'/../fixtures/channels-commented-out.php';
    });

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('Channel routes')
        ->and($result['flat'])->not->toContain('defines channels');
});

/**
 * FIX 4. The most common production Echo failure, and the one the doctor
 * scored "All checks passed" on.
 *
 * A page served from `https://chat.example.com` may not open a `ws://` socket:
 * every browser blocks it as mixed content and Echo never connects. The SCHEME
 * is the hazard. A public PORT that differs from APP_URL's is the normal shape
 * behind a terminator and must stay silent, which the test below it pins.
 */
it('fails an https APP_URL against an http public scheme, which browsers block as mixed content', function () {
    doctorHealthyConfig([
        'app.url' => 'https://chat.example.com',
        'lightspeed.reverb_compat.public_scheme' => 'http',
        'lightspeed.reverb_compat.public_host' => 'chat.example.com',
        'lightspeed.reverb_compat.public_port' => 8000,
        'lightspeed.server.port' => 8000,
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(1)
        ->and($result['flat'])->toContain('mixed content')
        ->and($result['flat'])->toContain('LIGHTSPEED_PUBLIC_SCHEME');
});

it('says nothing about a public port that differs from the one in APP_URL, which is what a terminator looks like', function () {
    doctorHealthyConfig([
        'app.url' => 'https://chat.example.com',
        'lightspeed.reverb_compat.public_scheme' => 'https',
        'lightspeed.reverb_compat.public_host' => 'chat.example.com',
        'lightspeed.reverb_compat.public_port' => 443,
        'lightspeed.server.port' => 8000,
    ]);
    fakeDoctor();

    $result = runDoctor();

    expect($result['status'])->toBe(0)
        ->and($result['flat'])->not->toContain('FAIL');
});

/**
 * FIX 5. Unknown must not read as fine.
 *
 * With Redis unreachable the high-water read cannot happen, and the check
 * reported "No grant-lifetime high-water mark in Redis yet, so nothing has
 * minted a tagged grant on this deployment", as a PASS. It has no evidence
 * for that sentence. A deployment that mints 7200s grants all day long gets it
 * word for word the moment Redis stops answering.
 */
it('does not claim nothing has minted a grant when Redis could not be asked', function () {
    doctorHealthyConfig();
    fakeDoctor(function (FakeDoctor $doctor) {
        $doctor->redisErrors = ['*' => 'Connection refused [tcp://127.0.0.1:6379]'];
    });

    $result = runDoctor(['--json' => true]);
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
    $floor = collect($report['checks'])->firstWhere('name', 'Revocation floor');

    expect($floor['status'])->toBe('warn')
        ->and($floor['detail'].' '.(string) $floor['fix'])->not->toContain('nothing has minted');
});

it('does not claim nothing has minted a grant when the high-water read itself failed', function () {
    doctorHealthyConfig();
    fakeDoctor(function (FakeDoctor $doctor) {
        // Redis answers PING and then fails the GET: a replica in the middle of
        // a failover, a key evicted mid-read, a client error. Swallowed, this
        // was indistinguishable from "no mark".
        $doctor->highWaterUnreadable = true;
    });

    $result = runDoctor(['--json' => true]);
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
    $floor = collect($report['checks'])->firstWhere('name', 'Revocation floor');

    expect($floor['status'])->toBe('warn')
        ->and($floor['detail'].' '.(string) $floor['fix'])->not->toContain('nothing has minted');
});

/**
 * FIX 1, the half of it that lives in this command.
 *
 * The doctor now RESOLVES the broadcaster rather than reading config strings
 * about it, which is the only way to answer the two questions above honestly.
 * That puts the `pusherClient()` throw on the doctor's own path, so the
 * command has to survive it and report it as the credential failure it is, 
 * including under `--json`, whose documented contract is a parseable report
 * and which was emitting a stack trace.
 *
 * This does NOT fix the crash reported against the real app. There the same
 * throw happens while `routes/channels.php` is being loaded at boot, before
 * this command is reachable at all. See the report.
 */
it('reports, rather than crashes on, credentials that make the broadcaster unbuildable', function () {
    doctorHealthyConfig();
    config(['lightspeed.reverb_compat.app_secret' => '']);
    Broadcast::forgetDrivers();
    fakeDoctor();

    $result = runDoctor(['--json' => true]);
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    expect($result['status'])->toBe(1)
        ->and($report['ok'])->toBeFalse()
        ->and($result['output'])->not->toContain('RuntimeException');

    $names = array_column($report['checks'], 'name');

    expect($names)->toContain('App credentials')
        ->and($names)->toContain('Broadcast driver')
        ->and($names)->toContain('Revocation floor');
});


it('will not guess at a page scheme when APP_URL names none', function () {
    doctorHealthyConfig(['app.url' => 'chat.example.com']);
    fakeDoctor();

    $result = runDoctor(['--json' => true]);
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
    $page = collect($report['checks'])->firstWhere('name', 'Page scheme');

    expect($page['status'])->toBe('warn')
        ->and($page['fix'])->toContain('APP_URL');
});
