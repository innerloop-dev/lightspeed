<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Redis;
use Lightspeed\ClientEvents\ClientEvent;
use Lightspeed\ClientEvents\ClientEventResult;
use Lightspeed\Console\Commands\LightspeedDoctor;
use Lightspeed\Console\DoctorReport;
use Lightspeed\Contracts\ClientEventHandler;
use Lightspeed\Contracts\OwnerCommandHandler;
use Lightspeed\Owner\OwnerCommand;
use Lightspeed\Server;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * What each doctor check CONCLUDES, read as data rather than as a screen.
 *
 * `DoctorCommandTest.php` asserts on the sentences an operator reads, which is
 * the right way to hold a command whose whole purpose is a readable message.
 * These read `--json` instead, so a check's name, status, detail, note and fix
 * can each be pinned exactly: the threshold a value has to cross, which of the
 * three verdicts that produces, and which number or env var ends up in which
 * sentence. A fix that names the wrong port, or a boundary that refuses the
 * value it is supposed to accept, is the failure mode this command exists to
 * prevent and is invisible to a substring match on collapsed output.
 */
final class DocMutFakeDoctor extends LightspeedDoctor
{
    public ?string $php = '8.2.0';

    public ?string $swoole = '6.0.0';

    public ?string $extRedis = '6.0.0';

    public bool $predis = false;

    /** Error text `pingRedis()` reports, by connection name; '*' catches all. */
    public array $redisErrors = [];

    public bool $published = true;

    public string $channelsPath = '';

    public ?int $highWater = null;

    /** A Redis that answers PING and then fails the mark read. */
    public ?Throwable $highWaterThrows = null;

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

    protected function grantLifetimeHighWater(string $connection): ?int
    {
        if ($this->highWaterThrows !== null) {
            throw $this->highWaterThrows;
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

/** A handler that satisfies the client-event contract. */
final class DocMutFixtureHandler implements ClientEventHandler
{
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        return null;
    }
}

/** A second one, so "how many" can be told apart from "any at all". */
final class DocMutOtherHandler implements ClientEventHandler
{
    public function handle(ClientEvent $event): ?ClientEventResult
    {
        return null;
    }
}

/** A handler that satisfies the owner-command contract. */
final class DocMutOwnerHandler implements OwnerCommandHandler
{
    public function handle(OwnerCommand $command): ?array
    {
        return null;
    }
}

/** A class named as a handler that implements neither contract. */
final class DocMutNotAHandler
{
}

/** A Redis manager that fails the way a misconfigured one does. */
final class DocMutThrowingRedis
{
    public function __construct(private readonly Throwable $throwable) {}

    public function connection(?string $name = null): mixed
    {
        throw $this->throwable;
    }
}

/** An application that is set up correctly, so a test can break one thing. */
function docMutHealthyConfig(array $overrides = []): void
{
    config($overrides + [
        'app.url' => 'http://localhost',
        'broadcasting.default' => 'lightspeed',
        'broadcasting.connections.lightspeed' => ['driver' => 'lightspeed'],
        'lightspeed.reverb_compat.app_id' => 'test-app',
        'lightspeed.reverb_compat.app_key' => 'test-key',
        'lightspeed.reverb_compat.app_secret' => 'test-secret',
        'lightspeed.reverb_compat.public_scheme' => 'http',
        'lightspeed.reverb_compat.public_host' => 'localhost',
        'lightspeed.reverb_compat.public_port' => 8000,
        'lightspeed.server.host' => '0.0.0.0',
        'lightspeed.server.port' => 8000,
        'lightspeed.server.worker_num' => 1,
        'lightspeed.server.enable_coroutine' => false,
        'lightspeed.client_event_handlers' => [DocMutFixtureHandler::class],
        'lightspeed.owner_command_handlers' => [],
        'lightspeed.auth.grant_lifetime_seconds' => 300,
        'lightspeed.auth.revocation_retention_floor_seconds' => 3600,
    ]);
}

/**
 * Remove a config key outright.
 *
 * Several checks read a default that only applies when the key is ABSENT, and
 * an application that never published config/lightspeed.php is exactly that
 * case. Setting the key to null is a different thing and does not exercise it.
 */
function docMutForget(string $key): void
{
    $parts = explode('.', $key);
    $leaf = array_pop($parts);
    $parent = implode('.', $parts);

    $value = config($parent);

    if (! is_array($value)) {
        return;
    }

    unset($value[$leaf]);

    config([$parent => $value]);
}

/**
 * Register channel definitions the way routes/channels.php does.
 *
 * They live on the default connection's broadcaster instance, which is the
 * thing the channel-routes check asks. Wrapped because resolving that
 * broadcaster is precisely what a missing credential makes impossible, and the
 * tests that empty one on purpose still have to reach the command.
 */
function docMutRegisterChannels(int $count): void
{
    for ($index = 0; $index < $count; $index++) {
        try {
            Broadcast::channel('docmut.'.$index.'.{id}', fn ($user, string $id) => true);
        } catch (\Throwable) {
            // See above.
        }
    }
}

/**
 * Run the doctor over a described application and read its JSON.
 *
 * `checks` comes back keyed by name, and `rows` keeps the list, because the six
 * Redis rows are six different names and their ORDER is part of what is being
 * asserted.
 */
function docMutRun(array $overrides = [], ?callable $configure = null, array $forget = [], int $channels = 1): array
{
    docMutHealthyConfig($overrides);

    foreach ($forget as $key) {
        docMutForget($key);
    }

    docMutRegisterChannels($channels);

    $doctor = new DocMutFakeDoctor;
    $doctor->channelsPath = __DIR__.'/../fixtures/channels.php';

    if ($configure !== null) {
        $configure($doctor);
    }

    // Run the command object directly rather than through Artisan: the console
    // application resolves a command once and keeps it, so a second run inside
    // one test would silently be the first run's doctor all over again.
    $doctor->setLaravel(app());

    $buffer = new BufferedOutput;
    $status = $doctor->run(new ArrayInput(['--json' => true]), $buffer);
    $output = $buffer->fetch();
    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray("the --json contract is a parseable document, got: {$output}");

    $checks = [];

    foreach ($decoded['checks'] as $check) {
        $checks[$check['name']] = $check;
    }

    return [
        'status' => $status,
        'ok' => $decoded['ok'],
        'counts' => $decoded['counts'],
        'rows' => $decoded['checks'],
        'checks' => $checks,
    ];
}

/** One check by name, so a missing row fails as a missing row. */
function docMutCheckNamed(array $result, string $name): array
{
    expect($result['checks'])->toHaveKey($name);

    return $result['checks'][$name];
}

/**
 * Every check runs, under the name it is reported by, in the order the screen
 * prints them.
 *
 * A check that quietly stops running is the worst failure this command has:
 * the row simply is not there, everything else still passes, and the operator
 * is told the installation is fine.
 */
it('runs every check, in order', function () {
    $result = docMutRun();

    expect(array_column($result['rows'], 'name'))->toBe([
        'PHP',
        'Swoole',
        'PHP Redis client',
        'Redis (relay)',
        'Redis (presence)',
        'Redis (resources)',
        'Redis (connections)',
        'Redis (owner commands)',
        'Redis (auth)',
        'App credentials',
        'Broadcast driver',
        'Broadcast connection',
        'Channel routes',
        'Client event handlers',
        'Handler classes',
        'Public address',
        'Page scheme',
        'Workers',
        'Coroutines',
        'Grant lifetime',
        'Revocation floor',
    ]);
});

/**
 * The counts are the summary sentence's evidence and `--json`'s `counts`, and
 * they are counted from the rows rather than asserted about.
 */
it('counts the rows it reports, and exits on whether any of them failed', function () {
    $healthy = docMutRun();

    $tally = array_count_values(array_column($healthy['rows'], 'status'));

    expect($healthy['counts'][DoctorReport::PASS])->toBe($tally[DoctorReport::PASS] ?? 0)
        ->and($healthy['counts'][DoctorReport::WARN])->toBe($tally[DoctorReport::WARN] ?? 0)
        ->and($healthy['counts'][DoctorReport::FAIL])->toBe(0)
        ->and($healthy['ok'])->toBeTrue()
        ->and($healthy['status'])->toBe(0);

    $broken = docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->swoole = null);

    expect($broken['counts'][DoctorReport::FAIL])->toBe(1)
        ->and($broken['ok'])->toBeFalse()
        ->and($broken['status'])->toBe(1);
});

// --- PHP -----------------------------------------------------------------

/**
 * The floor is the package's own, and the row says which version failed which
 * constraint. "PHP is too old" without both numbers is not actionable.
 */
it('names the running version and the constraint it does not satisfy', function () {
    $constraint = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true)['require']['php'];

    $old = docMutCheckNamed(docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->php = '8.0.30'), 'PHP');

    expect($old['status'])->toBe(DoctorReport::FAIL)
        ->and($old['detail'])->toBe('8.0.30 does not satisfy '.$constraint)
        ->and($old['fix'])->toBe('Lightspeed requires PHP '.$constraint.'. Upgrade PHP, or check that the `php` on your PATH is the one running artisan.');

    $current = docMutCheckNamed(docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->php = '8.3.14'), 'PHP');

    expect($current['status'])->toBe(DoctorReport::PASS)
        ->and($current['detail'])->toBe('8.3.14 satisfies '.$constraint);
});

// --- PHP Redis client ----------------------------------------------------

/**
 * Which client is installed, exactly, and not one word more: this row is the
 * one that tells somebody `composer require innerloop-dev/lightspeed` installed no
 * Redis client at all, so naming a client that is not there would be worse
 * than saying nothing.
 */
it('names only the Redis clients that are actually installed', function () {
    $extOnly = docMutRun(configure: function (DocMutFakeDoctor $doctor) {
        $doctor->extRedis = '6.0.2';
        $doctor->predis = false;
    });

    expect(docMutCheckNamed($extOnly, 'PHP Redis client')['detail'])->toBe('ext-redis 6.0.2');

    $predisOnly = docMutRun(configure: function (DocMutFakeDoctor $doctor) {
        $doctor->extRedis = null;
        $doctor->predis = true;
    });

    expect(docMutCheckNamed($predisOnly, 'PHP Redis client')['detail'])->toBe('predis/predis');

    $both = docMutRun(configure: function (DocMutFakeDoctor $doctor) {
        $doctor->extRedis = '6.0.2';
        $doctor->predis = true;
    });

    expect(docMutCheckNamed($both, 'PHP Redis client')['detail'])->toBe('ext-redis 6.0.2, predis/predis');
});

// --- Redis connections ---------------------------------------------------

/**
 * Six usages, six rows, whatever is wrong. Each names the connection IT
 * resolved to, which is the fact an operator cannot otherwise see: a
 * deployment that sets only the relay connection moves revocation state with
 * it and nothing says so.
 *
 * The repeated fix is what gets folded, never the row: the first row of a
 * given connection carries the paragraph and the rest point at it.
 */
it('reports all six Redis usages when the connection does not answer, and repeats the fix once', function () {
    $result = docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->redisErrors = ['*' => 'Connection refused.']);

    $relay = docMutCheckNamed($result, 'Redis (relay)');
    $presence = docMutCheckNamed($result, 'Redis (presence)');
    $auth = docMutCheckNamed($result, 'Redis (auth)');

    expect($relay['status'])->toBe(DoctorReport::FAIL)
        ->and($relay['detail'])->toBe("connection 'default' did not answer")
        ->and($relay['fix'])->toBe('Redis said: Connection refused. Start Redis, or point the `default` connection at a reachable server in the `redis` section of config/database.php.')
        // Every later row points back at the FIRST row that carried the fix,
        // not at the row before it.
        ->and($presence['fix'])->toBe("Same 'default' connection as Redis (relay) above.")
        ->and($auth['fix'])->toBe("Same 'default' connection as Redis (relay) above.");
});

/**
 * With no client installed nothing in this process can reach Redis whichever
 * connection it is pointed at, so every usage still gets a row and the
 * paragraph explaining why is printed once.
 */
it('reports all six Redis usages when no client is installed', function () {
    $result = docMutRun(configure: function (DocMutFakeDoctor $doctor) {
        $doctor->extRedis = null;
        $doctor->predis = false;
    });

    $rows = array_values(array_filter($result['rows'], fn (array $row): bool => str_starts_with($row['name'], 'Redis (')));

    expect($rows)->toHaveCount(6)
        ->and($rows[0]['detail'])->toBe("connection 'default', not checked")
        ->and($rows[0]['fix'])->toBe('Install a PHP Redis client first (see the PHP Redis client check above); until then nothing in this process can reach Redis, whichever connection it is pointed at.')
        ->and($rows[1]['fix'])->toBe('Install a PHP Redis client first (see above).')
        ->and($rows[5]['fix'])->toBe('Install a PHP Redis client first (see above).');
});

/** Each usage reads its own config key, and reports the connection it found. */
it('reports the connection each usage resolved to', function () {
    $result = docMutRun([
        'lightspeed.presence.redis_connection' => 'presence-store',
        'lightspeed.owner_commands.redis_connection' => 'commands',
    ]);

    expect(docMutCheckNamed($result, 'Redis (presence)')['detail'])->toBe("connection 'presence-store' answered PING")
        ->and(docMutCheckNamed($result, 'Redis (owner commands)')['detail'])->toBe("connection 'commands' answered PING")
        ->and(docMutCheckNamed($result, 'Redis (relay)')['detail'])->toBe("connection 'default' answered PING");
});

// --- App credentials -----------------------------------------------------

/**
 * The row names the env vars to set, and the consequence text has to be right
 * about WHICH of them is missing: the secret makes every private channel
 * forgeable, the app id breaks publishing and nothing else. Those sentences
 * used to be the wrong way round, which sent anyone debugging an empty app id
 * at the one subsystem still working.
 */
it('names the missing credentials and the consequence of the ones that are missing', function () {
    $all = docMutCheckNamed(docMutRun([
        'lightspeed.reverb_compat.app_id' => '',
        'lightspeed.reverb_compat.app_key' => '',
        'lightspeed.reverb_compat.app_secret' => '',
    ]), 'App credentials');

    expect($all['status'])->toBe(DoctorReport::FAIL)
        ->and($all['detail'])->toBe('empty: LIGHTSPEED_APP_ID, LIGHTSPEED_APP_KEY, LIGHTSPEED_APP_SECRET')
        ->and($all['fix'])->toStartWith('Set LIGHTSPEED_APP_ID and LIGHTSPEED_APP_KEY and LIGHTSPEED_APP_SECRET in .env (the REVERB_APP_* names are accepted too). With the secret empty');

    $secretOnly = docMutCheckNamed(docMutRun(['lightspeed.reverb_compat.app_secret' => '']), 'App credentials');

    expect($secretOnly['detail'])->toBe('empty: LIGHTSPEED_APP_SECRET')
        ->and($secretOnly['fix'])->toStartWith('Set LIGHTSPEED_APP_SECRET in .env (the REVERB_APP_* names are accepted too). With the secret empty')
        ->and($secretOnly['fix'])->toContain('`lightspeed:serve` refuses to start without the key or the secret.');

    $keyOnly = docMutCheckNamed(docMutRun(['lightspeed.reverb_compat.app_key' => '']), 'App credentials');

    expect($keyOnly['fix'])->toContain('With the secret empty every private and presence channel signature can be computed');

    // The app id alone is an address rather than a credential, and gets the
    // other sentence.
    $idOnly = docMutCheckNamed(docMutRun(['lightspeed.reverb_compat.app_id' => '']), 'App credentials');

    expect($idOnly['detail'])->toBe('empty: LIGHTSPEED_APP_ID')
        ->and($idOnly['fix'])->toContain('The app id is an address rather than a credential')
        ->and($idOnly['fix'])->not->toContain('With the secret empty');
});

/**
 * A credential that was never set at all is as empty as one set to ''.
 *
 * Null is the ordinary shape of this: `env('LIGHTSPEED_APP_SECRET')` with the
 * variable absent from .env answers null, and a null secret makes every
 * private and presence channel exactly as forgeable as an empty one.
 */
it('treats an unset credential as empty', function () {
    $forgotten = docMutCheckNamed(docMutRun(forget: ['lightspeed.reverb_compat.app_secret']), 'App credentials');

    expect($forgotten['status'])->toBe(DoctorReport::FAIL)
        ->and($forgotten['detail'])->toBe('empty: LIGHTSPEED_APP_SECRET');

    $null = docMutCheckNamed(docMutRun(['lightspeed.reverb_compat.app_secret' => null]), 'App credentials');

    expect($null['status'])->toBe(DoctorReport::FAIL)
        ->and($null['detail'])->toBe('empty: LIGHTSPEED_APP_SECRET');
});

/** All three set is the whole of the passing case. */
it('passes when all three credentials are set', function () {
    expect(docMutCheckNamed(docMutRun(), 'App credentials'))
        ->toMatchArray(['status' => DoctorReport::PASS, 'detail' => 'app id, key and secret are set']);
});

// --- Broadcast driver and connection -------------------------------------

/**
 * The driver row resolves the thing and looks at what came back, because the
 * connection NAME is not the fact. What it must never do is take the command
 * down with it: a broadcaster that cannot be built is a row, not a stack
 * trace, and not a `--json` document that will not parse.
 */
it('reports a broadcaster that could not be resolved as a row', function () {
    $result = docMutRun(['broadcasting.default' => 'nowhere'], forget: ['broadcasting.connections.nowhere']);

    $driver = docMutCheckNamed($result, 'Broadcast driver');

    expect($driver['status'])->toBe(DoctorReport::FAIL)
        ->and($driver['detail'])->toBe("connection 'nowhere' could not be resolved")
        ->and($driver['fix'])->toStartWith('Resolving it failed with: ')
        ->and($driver['fix'])->toContain('nowhere')
        ->and($driver['fix'])->toContain(' Nothing can authorize a channel until that is fixed, and any routes/channels.php that calls Broadcast::channel() hits the same failure while the application is booting');

    // And with no broadcaster there is nothing to ask about channels.
    expect(docMutCheckNamed($result, 'Channel routes'))
        ->toMatchArray(['status' => DoctorReport::WARN, 'detail' => 'not checked, the broadcast driver did not resolve']);
});

/** A default connection that is not named at all still reads as a row. */
it('reports an unnamed default broadcast connection', function () {
    $result = docMutRun(forget: ['broadcasting.default']);

    expect(docMutCheckNamed($result, 'Broadcast driver')['detail'])->toBe("connection '' resolves to Illuminate\\Broadcasting\\Broadcasters\\NullBroadcaster")
        ->and(docMutCheckNamed($result, 'Broadcast connection')['detail'])->toBe("no '' connection in config/broadcasting.php");
});

/**
 * Resolving to another package's broadcaster is the commonest silent failure
 * there is: Laravel registers the application's own `Broadcast::channel()`
 * callbacks on the default connection's broadcaster, so every authorization
 * decision is then made by code the application did not write.
 */
it('names the broadcaster the default connection actually resolves to', function () {
    $result = docMutRun([
        'broadcasting.default' => 'logging',
        'broadcasting.connections.logging' => ['driver' => 'log'],
    ]);

    $driver = docMutCheckNamed($result, 'Broadcast driver');

    expect($driver['status'])->toBe(DoctorReport::FAIL)
        ->and($driver['detail'])->toBe("connection 'logging' resolves to Illuminate\\Broadcasting\\Broadcasters\\LogBroadcaster")
        ->and($driver['fix'])->toStartWith('Set BROADCAST_CONNECTION=lightspeed in .env, or point it at any connection in config/broadcasting.php whose `driver` is `lightspeed`');

    // The connection row is about a different fact: the connection exists here,
    // it is its driver that is wrong.
    $connection = docMutCheckNamed($result, 'Broadcast connection');

    expect($connection['status'])->toBe(DoctorReport::FAIL)
        ->and($connection['detail'])->toBe("connection 'logging' has driver 'log'")
        // What matters in this fix text: it names BOTH remedies and carries
        // the pasteable snippet. The exact prose is not pinned; adversarial review
        // caught the old pin enshrining a comma splice.
        ->and($connection['fix'])->toContain('BROADCAST_CONNECTION=lightspeed')
        ->and($connection['fix'])->toContain("'logging' => ['driver' => 'lightspeed'],");
});

/**
 * The ordering trap: the connection has to exist in config/broadcasting.php
 * BEFORE BROADCAST_CONNECTION names it, and the fix has to say so because
 * doing it the other way round throws during boot.
 */
it('reports a default connection that is not defined at all', function () {
    $result = docMutRun(['broadcasting.default' => 'realtime'], forget: ['broadcasting.connections.realtime']);

    $connection = docMutCheckNamed($result, 'Broadcast connection');

    expect($connection['status'])->toBe(DoctorReport::FAIL)
        ->and($connection['detail'])->toBe("no 'realtime' connection in config/broadcasting.php")
        ->and($connection['fix'])->toBe("Add 'realtime' => ['driver' => 'lightspeed'], to the `connections` array in config/broadcasting.php, and do it BEFORE pointing BROADCAST_CONNECTION at it. Laravel throws when the named connection does not exist yet.");
});

/** A connection defined with no driver at all reports the empty driver. */
it('reports a connection defined without a driver', function () {
    $result = docMutRun(['broadcasting.connections.lightspeed' => ['queue' => 'default']]);

    expect(docMutCheckNamed($result, 'Broadcast connection')['detail'])->toBe("connection 'lightspeed' has driver ''");
});

/** The name does not matter, only the driver: this is a working deployment. */
it('accepts a working connection whatever it is named', function () {
    $result = docMutRun([
        'broadcasting.default' => 'realtime',
        'broadcasting.connections.realtime' => ['driver' => 'lightspeed'],
    ]);

    expect(docMutCheckNamed($result, 'Broadcast driver'))
        ->toMatchArray([
            'status' => DoctorReport::PASS,
            'detail' => "connection 'realtime' resolves to the Lightspeed broadcaster",
        ]);

    expect(docMutCheckNamed($result, 'Broadcast connection'))
        ->toMatchArray([
            'status' => DoctorReport::PASS,
            'detail' => "'realtime' is defined with the lightspeed driver",
        ]);
});

// --- Channel routes ------------------------------------------------------

/** How many definitions registered, said in English for one of them. */
it('counts the channel definitions that actually registered', function () {
    expect(docMutCheckNamed(docMutRun(channels: 1), 'Channel routes'))
        ->toMatchArray(['status' => DoctorReport::PASS, 'detail' => '1 channel definition registered']);

    expect(docMutCheckNamed(docMutRun(channels: 3), 'Channel routes')['detail'])
        ->toBe('3 channel definitions registered');
});

/**
 * A file that registered nothing and no file at all need different sentences:
 * one is "write some channels", the other is "you have nowhere to write them".
 */
it('separates a channels file that registered nothing from one that does not exist', function () {
    $registeredNothing = docMutCheckNamed(docMutRun(channels: 0), 'Channel routes');

    expect($registeredNothing['status'])->toBe(DoctorReport::FAIL)
        ->and($registeredNothing['detail'])->toBe('routes/channels.php registered no channels')
        ->and($registeredNothing['fix'])->toStartWith('The file exists and the broadcaster came back with nothing');

    $noFile = docMutCheckNamed(
        docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->channelsPath = __DIR__.'/../fixtures/does-not-exist.php', channels: 0),
        'Channel routes',
    );

    expect($noFile['status'])->toBe(DoctorReport::FAIL)
        ->and($noFile['detail'])->toBe('no channel definitions registered, and routes/channels.php does not exist')
        ->and($noFile['fix'])->toStartWith('Run `php artisan lightspeed:install` to create routes/channels.php and register it');
});

// --- Client event handlers -----------------------------------------------

/**
 * An unpublished config file is a different problem from an empty list, and it
 * is the commonest one: the file somebody edited is not the file that is read.
 */
it('separates an unpublished config file from an empty handler list', function () {
    $unpublished = docMutCheckNamed(
        docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->published = false),
        'Client event handlers',
    );

    expect($unpublished['status'])->toBe(DoctorReport::WARN)
        ->and($unpublished['detail'])->toBe('config/lightspeed.php has not been published')
        ->and($unpublished['fix'])->toStartWith('Run `php artisan vendor:publish --tag=lightspeed-config`');

    $none = docMutCheckNamed(docMutRun(['lightspeed.client_event_handlers' => []]), 'Client event handlers');

    expect($none['status'])->toBe(DoctorReport::WARN)
        ->and($none['detail'])->toBe('none registered')
        ->and($none['fix'])->toStartWith('Add your handler class to `client_event_handlers` in config/lightspeed.php.');

    $two = docMutCheckNamed(
        docMutRun(['lightspeed.client_event_handlers' => [DocMutFixtureHandler::class, DocMutOtherHandler::class]]),
        'Client event handlers',
    );

    expect($two['status'])->toBe(DoctorReport::PASS)
        ->and($two['detail'])->toBe('2 registered');
});

/** A single handler written without the array brackets is still one handler. */
it('reads a handler list that is a bare class name', function () {
    $result = docMutRun([
        'lightspeed.client_event_handlers' => DocMutFixtureHandler::class,
        'lightspeed.owner_command_handlers' => DocMutOwnerHandler::class,
    ]);

    expect(docMutCheckNamed($result, 'Client event handlers')['detail'])->toBe('1 registered')
        ->and(docMutCheckNamed($result, 'Handler classes')['detail'])->toBe('2 exist and implement their contract');
});

// --- Handler classes -----------------------------------------------------

/**
 * The same refusal `Server::serve()` starts on, reported before the first
 * client event arrives in production. Each of the three ways an entry can be
 * wrong names the entry, the config key, and what to do about it.
 */
it('reports every way a handler entry can be wrong', function () {
    $notAName = docMutCheckNamed(docMutRun(['lightspeed.client_event_handlers' => ['']]), 'Handler classes');

    expect($notAName['status'])->toBe(DoctorReport::FAIL)
        ->and($notAName['detail'])->toBe('`client_event_handlers` contains something that is not a class name')
        ->and($notAName['fix'])->toBe('Every entry in `client_event_handlers` in config/lightspeed.php must be a handler class name, for example MyHandler::class.');

    $missing = docMutCheckNamed(docMutRun(['lightspeed.client_event_handlers' => ['App\\Nope']]), 'Handler classes');

    expect($missing['status'])->toBe(DoctorReport::FAIL)
        ->and($missing['detail'])->toBe('App\\Nope does not exist')
        ->and($missing['fix'])->toBe('`client_event_handlers` in config/lightspeed.php names App\\Nope, and no such class can be autoloaded. Check the namespace and the file name, then run `composer dump-autoload`.');

    $wrongContract = docMutCheckNamed(docMutRun(['lightspeed.client_event_handlers' => [DocMutNotAHandler::class]]), 'Handler classes');

    expect($wrongContract['status'])->toBe(DoctorReport::FAIL)
        ->and($wrongContract['detail'])->toBe(DocMutNotAHandler::class.' does not implement the contract')
        ->and($wrongContract['fix'])->toBe(DocMutNotAHandler::class.' must implement '.ClientEventHandler::class.'. The server refuses to start until it does.');
});

/** Owner command handlers are checked too, against their own contract. */
it('checks owner command handlers against the owner command contract', function () {
    $wrongContract = docMutCheckNamed(docMutRun([
        'lightspeed.client_event_handlers' => [],
        'lightspeed.owner_command_handlers' => [DocMutNotAHandler::class],
    ]), 'Handler classes');

    expect($wrongContract['status'])->toBe(DoctorReport::FAIL)
        ->and($wrongContract['detail'])->toBe(DocMutNotAHandler::class.' does not implement the contract')
        ->and($wrongContract['fix'])->toBe(DocMutNotAHandler::class.' must implement '.OwnerCommandHandler::class.'. The server refuses to start until it does.');
});

/** Nothing configured is not the same statement as everything checked out. */
it('counts the handlers it checked across both config keys', function () {
    expect(docMutCheckNamed(docMutRun([
        'lightspeed.client_event_handlers' => [],
        'lightspeed.owner_command_handlers' => [],
    ]), 'Handler classes'))->toMatchArray(['status' => DoctorReport::PASS, 'detail' => 'none configured']);

    expect(docMutCheckNamed(docMutRun([
        'lightspeed.client_event_handlers' => [DocMutFixtureHandler::class],
        'lightspeed.owner_command_handlers' => [],
    ]), 'Handler classes')['detail'])->toBe('1 exist and implement their contract');

    expect(docMutCheckNamed(docMutRun([
        'lightspeed.client_event_handlers' => [DocMutFixtureHandler::class, DocMutOtherHandler::class],
        'lightspeed.owner_command_handlers' => [DocMutOwnerHandler::class],
    ]), 'Handler classes')['detail'])->toBe('3 exist and implement their contract');
});

// --- Public address ------------------------------------------------------

/**
 * Both addresses, in full, because "which of these two ports did I mean" is
 * the question the public/bind split creates.
 */
it('prints the address clients dial and the socket the server binds', function () {
    $result = docMutRun([
        'lightspeed.reverb_compat.public_scheme' => 'https',
        'lightspeed.reverb_compat.public_host' => 'chat.example.com',
        'lightspeed.reverb_compat.public_port' => 443,
        'lightspeed.server.host' => '127.0.0.1',
        'lightspeed.server.port' => 8080,
    ]);

    expect(docMutCheckNamed($result, 'Public address')['detail'])
        ->toBe('clients dial https://chat.example.com:443, server binds 127.0.0.1:8080');
});

/** With nothing configured the row still reads as an address. */
it('falls back to the documented public and bind defaults', function () {
    $result = docMutRun(forget: [
        'lightspeed.reverb_compat.public_port',
        'lightspeed.reverb_compat.public_host',
        'lightspeed.server.port',
        'lightspeed.server.host',
    ]);

    expect(docMutCheckNamed($result, 'Public address')['detail'])
        ->toBe('clients dial http://localhost:80, server binds 0.0.0.0:8000');
});

/**
 * https on the SAME port the server binds cannot work, because nothing here
 * terminates TLS, and the warning has to name the port that is not listening
 * for a handshake. It warns rather than fails: the doctor cannot see the proxy.
 */
it('warns about a public https scheme on the port the server itself binds', function () {
    $check = docMutCheckNamed(docMutRun([
        'lightspeed.reverb_compat.public_scheme' => 'https',
        'lightspeed.reverb_compat.public_port' => 8000,
        'lightspeed.server.port' => 8000,
        'app.url' => 'https://localhost',
    ]), 'Public address');

    expect($check['status'])->toBe(DoctorReport::WARN)
        ->and($check['fix'])->toStartWith('Lightspeed does not terminate TLS, so nothing is listening for a TLS handshake on 8000. Either put a terminator in front and set LIGHTSPEED_PUBLIC_PORT (or REVERB_PORT) to the port it listens on')
        ->and($check['note'])->toBe('Only wrong if clients reach this port directly. A terminator that proxies 8000 back to 8000 is unusual but works.');
});

/**
 * A public port that DIFFERS from the bind port is the ordinary terminator
 * topology and must never be a problem, https or not. Refusing a safe
 * configuration is a worse failure than not checking, because the operator
 * then goes and breaks something that was right.
 */
it('passes a public port that differs from the bind port, over https too', function () {
    $https = docMutCheckNamed(docMutRun([
        'lightspeed.reverb_compat.public_scheme' => 'https',
        'lightspeed.reverb_compat.public_port' => 443,
        'lightspeed.server.port' => 8000,
        'app.url' => 'https://localhost',
    ]), 'Public address');

    expect($https['status'])->toBe(DoctorReport::PASS)
        ->and($https['note'])->toStartWith('The two differ, which is what a TLS terminator or reverse proxy in front looks like.');

    // The same ports over http is the plain single-host deployment, and it has
    // nothing to explain.
    $plain = docMutCheckNamed(docMutRun([
        'lightspeed.reverb_compat.public_port' => 8000,
        'lightspeed.server.port' => 8000,
    ]), 'Public address');

    expect($plain['status'])->toBe(DoctorReport::PASS)
        ->and($plain['note'])->toBeNull();
});

/** The two ports are compared as numbers, not as whatever .env spelled them. */
it('compares the public and bind ports as numbers', function () {
    $check = docMutCheckNamed(docMutRun([
        'lightspeed.reverb_compat.public_port' => '8000',
        'lightspeed.server.port' => 8000,
    ]), 'Public address');

    expect($check['note'])->toBeNull()
        ->and($check['detail'])->toBe('clients dial http://localhost:8000, server binds 0.0.0.0:8000');

    $reversed = docMutCheckNamed(docMutRun([
        'lightspeed.reverb_compat.public_port' => 8000,
        'lightspeed.server.port' => '8000',
    ]), 'Public address');

    expect($reversed['note'])->toBeNull();
});

// --- Page scheme ---------------------------------------------------------

/**
 * The mixed-content trap: an https page may not open a ws:// socket, the
 * browser blocks it before anything reaches this server, and there is nothing
 * in any log to find.
 */
it('fails an https page that is told to dial a plaintext socket', function () {
    $check = docMutCheckNamed(docMutRun([
        'app.url' => 'https://chat.example.com',
        'lightspeed.reverb_compat.public_scheme' => 'http',
    ]), 'Page scheme');

    expect($check['status'])->toBe(DoctorReport::FAIL)
        ->and($check['detail'])->toBe('pages are served over https, clients are told to dial http')
        ->and($check['fix'])->toContain('Set LIGHTSPEED_PUBLIC_SCHEME=https (or REVERB_SCHEME)');
});

/** The other direction is allowed everywhere, so it passes and is reported. */
it('passes an https socket opened from an http page', function () {
    $check = docMutCheckNamed(docMutRun([
        'app.url' => 'http://chat.example.com',
        'lightspeed.reverb_compat.public_scheme' => 'https',
        'lightspeed.reverb_compat.public_port' => 443,
    ]), 'Page scheme');

    expect($check['status'])->toBe(DoctorReport::PASS)
        ->and($check['detail'])->toBe('pages are served over http, clients dial https')
        ->and($check['note'])->toStartWith('A wss:// socket from an http page is allowed by every browser');

    $matched = docMutCheckNamed(docMutRun([
        'app.url' => 'https://chat.example.com',
        'lightspeed.reverb_compat.public_scheme' => 'https',
        'lightspeed.reverb_compat.public_port' => 443,
    ]), 'Page scheme');

    expect($matched['status'])->toBe(DoctorReport::PASS)
        ->and($matched['detail'])->toBe('pages and sockets are both https')
        ->and($matched['note'])->toBeNull();
});

/**
 * A scheme is a scheme however it was typed. `APP_URL=HTTPS://...` is the same
 * deployment as the lowercase one, and telling its operator their pages are
 * served over "HTTPS" while their sockets are "https" would be a mismatch
 * invented out of capitalisation.
 */
it('compares the two schemes without regard to case', function () {
    $result = docMutRun([
        'app.url' => 'HTTPS://chat.example.com',
        'lightspeed.reverb_compat.public_scheme' => 'HTTPS',
        'lightspeed.reverb_compat.public_port' => 443,
    ]);

    expect(docMutCheckNamed($result, 'Page scheme'))
        ->toMatchArray(['status' => DoctorReport::PASS, 'detail' => 'pages and sockets are both https']);
});

/** An APP_URL with no scheme cannot answer the question, and says so. */
it('warns when APP_URL names no scheme', function () {
    $check = docMutCheckNamed(docMutRun(['app.url' => 'chat.example.com']), 'Page scheme');

    expect($check['status'])->toBe(DoctorReport::WARN)
        ->and($check['detail'])->toBe('APP_URL names no scheme')
        ->and($check['fix'])->toStartWith('Set APP_URL to the full URL browsers load your application from');
});

// --- Workers and coroutines ----------------------------------------------

/**
 * One worker and several workers are different deployments with different
 * hazards, and the note is the only place either is said.
 */
it('says what one worker means, and what several mean', function () {
    $one = docMutCheckNamed(docMutRun(forget: ['lightspeed.server.worker_num']), 'Workers');

    expect($one['detail'])->toBe('1')
        ->and($one['note'])->toStartWith('One process serves every connection this server holds, so anything that blocks in a handler blocks all of them.');

    $several = docMutCheckNamed(docMutRun(['lightspeed.server.worker_num' => 2]), 'Workers');

    expect($several['detail'])->toBe('2')
        ->and($several['note'])->toStartWith('Each worker holds its own sockets and reaches the others only through the Redis relay');
});

/** Coroutines are off by default, and the note explains which risk applies. */
it('reports whether coroutines are enabled, and what that costs', function () {
    $off = docMutCheckNamed(docMutRun(forget: ['lightspeed.server.enable_coroutine']), 'Coroutines');

    expect($off['detail'])->toBe('disabled')
        ->and($off['note'])->toStartWith('The package\'s bounded waits are real usleep() on the event loop');

    $on = docMutCheckNamed(docMutRun(['lightspeed.server.enable_coroutine' => true]), 'Coroutines');

    expect($on['detail'])->toBe('enabled')
        ->and($on['note'])->toStartWith('Every callback runs inside a coroutine');
});

// --- Grant lifetime ------------------------------------------------------

/**
 * The lifetime is handed to Redis as an expiry, so the boundaries are the
 * whole check: one second is a legal lifetime, zero is not, and the ceiling
 * accepts the ceiling itself.
 */
it('accepts a grant lifetime at both boundaries and refuses what is outside them', function () {
    expect(docMutCheckNamed(docMutRun(['lightspeed.auth.grant_lifetime_seconds' => 1]), 'Grant lifetime'))
        ->toMatchArray(['status' => DoctorReport::PASS, 'detail' => '1s']);

    $zero = docMutCheckNamed(docMutRun(['lightspeed.auth.grant_lifetime_seconds' => 0]), 'Grant lifetime');

    expect($zero['status'])->toBe(DoctorReport::FAIL)
        ->and($zero['detail'])->toBe('0s')
        ->and($zero['fix'])->toBe('Set LIGHTSPEED_AUTH_GRANT_LIFETIME_SECONDS to a positive number of seconds.');

    expect(docMutCheckNamed(docMutRun(['lightspeed.auth.grant_lifetime_seconds' => Server::MAX_GRANT_LIFETIME_SECONDS]), 'Grant lifetime'))
        ->toMatchArray(['status' => DoctorReport::PASS, 'detail' => Server::MAX_GRANT_LIFETIME_SECONDS.'s']);

    $tooLong = docMutCheckNamed(
        docMutRun(['lightspeed.auth.grant_lifetime_seconds' => Server::MAX_GRANT_LIFETIME_SECONDS + 1]),
        'Grant lifetime',
    );

    expect($tooLong['status'])->toBe(DoctorReport::FAIL)
        ->and($tooLong['detail'])->toBe((Server::MAX_GRANT_LIFETIME_SECONDS + 1).'s is past the '.Server::MAX_GRANT_LIFETIME_SECONDS.'s ceiling')
        ->and($tooLong['fix'])->toStartWith('Set LIGHTSPEED_AUTH_GRANT_LIFETIME_SECONDS to a value in seconds under '.Server::MAX_GRANT_LIFETIME_SECONDS.'.');
});

/** The default lifetime is the one the package documents. */
it('reports the default grant lifetime when nothing is configured', function () {
    expect(docMutCheckNamed(docMutRun(forget: ['lightspeed.auth.grant_lifetime_seconds']), 'Grant lifetime')['detail'])
        ->toBe('300s');
});

// --- Revocation floor ----------------------------------------------------

/**
 * What a revoke served by THIS process would keep a record for with no mark to
 * read: exactly the Lua, which is `max(own lifetime * 2, floor)`. Both halves
 * of that max are load-bearing, and the number ends up in the sentence that
 * tells an operator what the window would be.
 */
it('reports the retention a lost mark would fall back to', function () {
    // The floor wins.
    expect(docMutCheckNamed(docMutRun([
        'lightspeed.auth.grant_lifetime_seconds' => 300,
        'lightspeed.auth.revocation_retention_floor_seconds' => 3600,
    ]), 'Revocation floor')['detail'])->toBe('3600s, covers grants up to 3600s');

    // Twice the lifetime wins.
    expect(docMutCheckNamed(docMutRun([
        'lightspeed.auth.grant_lifetime_seconds' => 300,
        'lightspeed.auth.revocation_retention_floor_seconds' => 100,
    ]), 'Revocation floor')['detail'])->toBe('100s, covers grants up to 600s');

    // And with neither configured, the documented defaults.
    expect(docMutCheckNamed(docMutRun(forget: [
        'lightspeed.auth.grant_lifetime_seconds',
        'lightspeed.auth.revocation_retention_floor_seconds',
    ]), 'Revocation floor')['detail'])->toBe('3600s, covers grants up to 3600s');

    expect(docMutCheckNamed(docMutRun(
        ['lightspeed.auth.revocation_retention_floor_seconds' => 100],
        forget: ['lightspeed.auth.grant_lifetime_seconds'],
    ), 'Revocation floor')['detail'])->toBe('100s, covers grants up to 600s');
});

/**
 * UNKNOWN IS NOT FINE. With the mark unreadable this row used to pass with a
 * statement about the whole fleet made on no evidence. It warns, it says what
 * Redis said, and it names the connection it asked.
 */
it('warns, and repeats the error, when the high-water mark cannot be read', function () {
    $check = docMutCheckNamed(
        docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->highWaterThrows = new RuntimeException('  READONLY You can\'t write against a read only replica.  ')),
        'Revocation floor',
    );

    expect($check['status'])->toBe(DoctorReport::WARN)
        ->and($check['detail'])->toBe('3600s, and the high-water mark could not be read')
        ->and($check['fix'])->toStartWith('Redis on the `default` connection said: READONLY You can\'t write against a read only replica. So whether the floor covers the longest grants this deployment mints is UNKNOWN, not fine.')
        ->and($check['fix'])->toContain('a revoke served by this process keeps its record for 3600s, and any grant minted anywhere under a longer lifetime outlives it.');
});

/**
 * An exception with nothing to say is reported by its class, because a row
 * that says Redis said "" is a row that says nothing at all.
 */
it('names the exception class when the failed read carried no message', function () {
    $check = docMutCheckNamed(
        docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->highWaterThrows = new RuntimeException('')),
        'Revocation floor',
    );

    expect($check['fix'])->toStartWith('Redis on the `default` connection said: RuntimeException So whether');
});

/** Redis that does not answer at all is the same unknown, said the same way. */
it('warns when Redis itself did not answer', function () {
    $check = docMutCheckNamed(
        docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->redisErrors = ['*' => 'Connection refused.']),
        'Revocation floor',
    );

    expect($check['status'])->toBe(DoctorReport::WARN)
        ->and($check['fix'])->toStartWith('Redis on the `default` connection said: Connection refused.');
});

/**
 * "No mark" and "could not ask" are different facts and only one of them is
 * reassuring, so only one of them says nothing has minted a tagged grant.
 */
it('passes when Redis answered and holds no mark at all', function () {
    $check = docMutCheckNamed(docMutRun(), 'Revocation floor');

    expect($check['status'])->toBe(DoctorReport::PASS)
        ->and($check['detail'])->toBe('3600s, covers grants up to 3600s')
        ->and($check['note'])->toStartWith('Redis answered and holds no grant-lifetime high-water mark');
});

/**
 * The mark is the only thing that carries a PEER's dial, and the boundary is
 * exact: retention equal to the longest grant minted is covered, one second
 * more than it is not.
 */
it('warns only once the fallback retention is shorter than the grants being minted', function () {
    $covered = docMutCheckNamed(
        docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->highWater = 3600),
        'Revocation floor',
    );

    expect($covered['status'])->toBe(DoctorReport::PASS)
        ->and($covered['detail'])->toBe('3600s, covers the 3600s grants this deployment mints');

    $short = docMutCheckNamed(
        docMutRun(configure: fn (DocMutFakeDoctor $doctor) => $doctor->highWater = 7200),
        'Revocation floor',
    );

    expect($short['status'])->toBe(DoctorReport::WARN)
        ->and($short['detail'])->toBe('3600s falls back to 3600s, under the 7200s grants this deployment mints')
        ->and($short['fix'])->toStartWith('Set LIGHTSPEED_AUTH_REVOCATION_RETENTION_FLOOR_SECONDS to at least 14400. While the high-water mark is in Redis the retention is correct')
        ->and($short['fix'])->toContain('keeps its record for 3600s while grants minted elsewhere live 7200s, and the identical auth string is readmitted for the difference.');
});

// --- Environment seams ---------------------------------------------------

/**
 * The seams are what the tests above replace, so they are the one part of this
 * command no other test can be describing. Each answers about the machine the
 * suite is actually running on.
 */
it('reads the loaded extensions from PHP itself', function () {
    $doctor = new LightspeedDoctor;

    $swoole = new ReflectionMethod(LightspeedDoctor::class, 'swooleVersion');
    $swoole->setAccessible(true);

    $redis = new ReflectionMethod(LightspeedDoctor::class, 'extRedisVersion');
    $redis->setAccessible(true);

    expect($swoole->invoke($doctor))->toBe(extension_loaded('swoole') ? phpversion('swoole') : null)
        ->and($redis->invoke($doctor))->toBe(extension_loaded('redis') ? phpversion('redis') : null);
});

/**
 * The ping seam reports the reason a connection did not answer, and the reason
 * comes from an exception written by somebody else: it may arrive padded, may
 * already end in a full stop, and may be empty.
 *
 * That text is pasted into a sentence, so it is tidied into exactly one
 * trailing stop, and an exception with nothing to say is reported by its class
 * rather than as `Redis said: `.
 */
it('tidies the reason a Redis connection did not answer', function () {
    $ping = new ReflectionMethod(LightspeedDoctor::class, 'pingRedis');
    $ping->setAccessible(true);

    $reason = function (Throwable $throwable) use ($ping): ?string {
        app()->instance('redis', new DocMutThrowingRedis($throwable));

        // The facade holds whatever it resolved last, and this test hands it
        // three different managers.
        Redis::clearResolvedInstance('redis');

        return $ping->invoke(new LightspeedDoctor, 'default');
    };

    expect($reason(new RuntimeException('  Connection refused.  ')))->toBe('Connection refused.')
        ->and($reason(new RuntimeException('Connection refused')))->toBe('Connection refused.')
        ->and($reason(new RuntimeException('')))->toBe(RuntimeException::class);
});

/**
 * Resolving the broadcaster is the most throw-prone thing this command does,
 * and a command whose whole promise is "never a stack trace" reports the throw
 * as a row: trimmed, ended with a single full stop, or named by its class when
 * the exception had nothing to say.
 */
it('reports a throwing broadcaster as text, however the exception was worded', function () {
    Broadcast::extend('docmut-noisy', fn () => throw new RuntimeException('  everything is on fire.  '));

    $noisy = docMutCheckNamed(docMutRun([
        'broadcasting.default' => 'noisy',
        'broadcasting.connections.noisy' => ['driver' => 'docmut-noisy'],
    ]), 'Broadcast driver');

    expect($noisy['fix'])->toStartWith('Resolving it failed with: everything is on fire. Nothing can authorize a channel');

    Broadcast::extend('docmut-silent', fn () => throw new RuntimeException(''));

    $silent = docMutCheckNamed(docMutRun([
        'broadcasting.default' => 'silent',
        'broadcasting.connections.silent' => ['driver' => 'docmut-silent'],
    ]), 'Broadcast driver');

    expect($silent['fix'])->toStartWith('Resolving it failed with: RuntimeException Nothing can authorize a channel');
});
