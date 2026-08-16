<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Lightspeed\Console\Commands\LightspeedInstall;

/**
 * The parts of `lightspeed:install` that InstallCommandTest does not watch.
 *
 * That file asserts on the files the command leaves behind, which is the right
 * subject for most of it. This one covers three things it leaves alone:
 *
 *   the report      the command prints, because the two column detail lines are
 *                   the only account an operator gets of what was touched and
 *                   what was left alone, and a run that silently did nothing
 *                   looks exactly like a run that did everything.
 *   the paste-me    messages, because when the command cannot finish, the exact
 *                   text it prints IS the product: a line the user copies into
 *                   their own bootstrap file or broadcasting config.
 *   the .env writer, because a variable written to the wrong place, read from a
 *                   key that merely starts the same way, or left unquoted, is a
 *                   corruption nobody sees until the next deploy.
 */
final class InstMutInstall extends LightspeedInstall
{
    public string $root = '';

    /** How many times the fake was asked to publish each config. */
    public int $broadcastingPublishes = 0;

    public int $packagePublishes = 0;

    /** Publish reports success but leaves no file, the way a failed copy can. */
    public bool $broadcastingPublishWritesFile = true;

    public bool $packagePublishSucceeds = true;

    protected function broadcastingConfigPath(): string
    {
        return $this->root.'/config/broadcasting.php';
    }

    protected function appConfigPath(): string
    {
        return $this->root.'/config/app.php';
    }

    protected function broadcastServiceProviderPath(): string
    {
        return $this->root.'/app/Providers/BroadcastServiceProvider.php';
    }

    protected function channelsRoutePath(): string
    {
        return $this->root.'/routes/channels.php';
    }

    protected function bootstrapAppPath(): string
    {
        return $this->root.'/bootstrap/app.php';
    }

    protected function envPath(): string
    {
        return $this->root.'/.env';
    }

    protected function envExamplePath(): string
    {
        return $this->root.'/.env.example';
    }

    protected function publishBroadcastingConfig(): bool
    {
        $this->broadcastingPublishes++;

        if (! $this->broadcastingPublishWritesFile) {
            return true;
        }

        @mkdir(dirname($this->broadcastingConfigPath()), 0755, true);

        return copy(
            dirname(__DIR__, 2).'/vendor/laravel/framework/config/broadcasting.php',
            $this->broadcastingConfigPath(),
        );
    }

    protected function publishPackageConfig(): bool
    {
        $this->packagePublishes++;

        if (! $this->packagePublishSucceeds) {
            return false;
        }

        @mkdir($this->root.'/config', 0755, true);

        return copy(dirname(__DIR__, 2).'/config/lightspeed.php', $this->root.'/config/lightspeed.php');
    }

    protected function packageConfigPublished(): bool
    {
        return is_file($this->root.'/config/lightspeed.php');
    }
}

/** The bootstrap/app.php a fresh Laravel 12 application ships with. */
function instMutBootstrapFile(): string
{
    return <<<'PHP'
    <?php

    use Illuminate\Foundation\Application;
    use Illuminate\Foundation\Configuration\Exceptions;
    use Illuminate\Foundation\Configuration\Middleware;

    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            commands: __DIR__.'/../routes/console.php',
            health: '/up',
        )->create();
    PHP;
}

function instMutEnvFile(): string
{
    return implode(PHP_EOL, [
        'APP_NAME=Laravel',
        'APP_ENV=local',
        '',
        'BROADCAST_CONNECTION=log',
        '',
    ]);
}

/** Build a throwaway application directory and point an install command at it. */
function instMutInstall(array $files = []): InstMutInstall
{
    $root = sys_get_temp_dir().'/lightspeed-instmut-'.bin2hex(random_bytes(6));

    $defaults = [
        'bootstrap/app.php' => instMutBootstrapFile(),
        '.env' => instMutEnvFile(),
        '.env.example' => instMutEnvFile(),
    ];

    foreach ($files + $defaults as $path => $contents) {
        if ($contents === null) {
            continue;
        }

        $full = $root.'/'.$path;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, $contents);
    }

    @mkdir($root.'/routes', 0755, true);

    $command = new InstMutInstall;
    $command->root = $root;

    app()->instance(LightspeedInstall::class, $command);

    return $command;
}

/** Run the command and hand back its status, raw output and flattened output. */
function instMutRun(array $parameters = []): array
{
    $status = Artisan::call('lightspeed:install', $parameters);
    $output = Artisan::output();

    return [
        'status' => $status,
        'output' => $output,
        'flat' => trim((string) preg_replace('/\s+/', ' ', $output)),
    ];
}

/**
 * The raw value of a key in an env file, matched on the whole key.
 *
 * Deliberately stricter than the command's own reader: this one keeps the value
 * exactly as written, so a test can tell an empty placeholder from a quoted one.
 */
function instMutEnvValue(string $path, string $key): ?string
{
    foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $line) {
        if (str_starts_with($line, $key.'=')) {
            return substr($line, strlen($key) + 1);
        }
    }

    return null;
}

/**
 * Every step reports what it did, and the report is the only account there is.
 *
 * An operator reads these lines to find out whether the run wired anything.
 * A step that quietly stopped printing would be indistinguishable from a step
 * that quietly stopped working, so the labels are pinned as a set rather than
 * one at a time.
 */
it('reports every file it touched on a fresh application', function () {
    instMutInstall();

    $result = instMutRun();

    expect($result['flat'])
        ->toContain('Setting up Lightspeed broadcasting.')
        ->toContain('config/broadcasting.php .')
        ->toContain('published')
        ->toContain('routes/channels.php .')
        ->toContain('created')
        ->toContain('bootstrap/app.php .')
        ->toContain('channels routes registered')
        ->toContain('broadcast connection .')
        ->toContain('added to config/broadcasting.php')
        ->toContain('LIGHTSPEED_APP_SECRET .')
        ->toContain('generated')
        ->toContain('BROADCAST_CONNECTION .')
        ->toContain('lightspeed')
        ->toContain('.env.example .')
        ->toContain('placeholders written')
        ->toContain('config/lightspeed.php .')
        ->toContain('Done. Run `php artisan lightspeed:doctor` to check the rest of the setup.');

    // The closing line is set apart from the report above it on purpose: it is
    // an instruction to the reader, not another row of the table. Matched on
    // the raw output, because the blank line is the whole assertion.
    expect($result['output'])->toMatch('/published[^\S\n]*\n[^\S\n]*\n[^\S\n]*INFO[^\S\n]+Done\./');
});

/**
 * A second run says "already", one line per step, and publishes nothing again.
 *
 * This is the difference between a command that is safe to re-run and one that
 * merely appears to be: every one of these branches is an early return, and a
 * mutant that skipped the return would republish or rewrite a file the user has
 * since edited.
 */
it('reports every step as already done on a second run', function () {
    $install = instMutInstall();

    expect(instMutRun()['status'])->toBe(0);

    $second = instMutRun();

    expect($second['status'])->toBe(0);

    // Each label is pinned to its own verdict: two steps report "already
    // present", and a run where one of them went quiet still contains the
    // words if they are only looked for one at a time.
    expect($second['flat'])
        ->toMatch('/config\/broadcasting\.php \.+ already present/')
        ->toMatch('/routes\/channels\.php \.+ already present/')
        ->toMatch('/bootstrap\/app\.php \.+ channels routes already registered/')
        ->toMatch('/broadcast connection \.+ already in config\/broadcasting\.php/')
        ->toMatch('/LIGHTSPEED_APP_SECRET \.+ kept, never overwritten/')
        ->toMatch('/config\/lightspeed\.php \.+ already published/');

    expect($install->broadcastingPublishes)->toBe(1);
    expect($install->packagePublishes)->toBe(1);
});

/**
 * A commented channels line is uncommented, and says so.
 *
 * The three wiring branches print three different sentences because they are
 * three different edits, and the one that was made is the only clue an operator
 * has about what their bootstrap file now looks like.
 */
it('says it uncommented the channels routes line', function () {
    instMutInstall([
        'bootstrap/app.php' => str_replace(
            "commands: __DIR__.'/../routes/console.php',",
            "commands: __DIR__.'/../routes/console.php',\n        // channels: __DIR__.'/../routes/channels.php',",
            instMutBootstrapFile(),
        ),
    ]);

    expect(instMutRun()['flat'])->toContain('channels routes uncommented');
});

/**
 * A bootstrap file with no console commands line still gets wired, and reports
 * the registration rather than the uncommenting.
 */
it('says it registered the channels routes through withRouting', function () {
    $install = instMutInstall([
        'bootstrap/app.php' => str_replace(
            "        commands: __DIR__.'/../routes/console.php',\n",
            '',
            instMutBootstrapFile(),
        ),
    ]);

    expect(instMutRun()['flat'])->toContain('channels routes registered');
    expect(file_get_contents($install->root.'/bootstrap/app.php'))->toContain(
        '->withRouting('.PHP_EOL."        channels: __DIR__.'/../routes/channels.php',"
    );
});

/**
 * When the command cannot wire the file, the message it prints is the product.
 *
 * The user is going to paste this line into their own bootstrap file, so the
 * whole sentence is pinned: the path it names, the line it hands over, and the
 * order the two arrive in. A message that named the routes file instead of the
 * bootstrap file, or handed over the fragments the other way round, would send
 * the reader to edit the wrong file.
 */
it('hands over the exact line and the exact file when it cannot wire the routes', function () {
    $install = instMutInstall(['bootstrap/app.php' => '<?php return 1;']);

    $result = instMutRun();

    expect($result['status'])->not->toBe(0);
    expect($result['flat'])
        ->toContain('could not register channels routes')
        ->toContain(
            'Unable to register broadcast routes. Add this line inside ->withRouting(...) in ['
            .$install->root.'/bootstrap/app.php]: '
            ."channels: __DIR__.'/../routes/channels.php',"
        );
});

/**
 * The same, for a broadcasting config with nowhere to add the connection.
 *
 * Nothing may be written to .env on this path, and the reason is printed as
 * well as the fix, because a half-installed application in this order does not
 * boot, including to run this command again.
 */
it('hands over the exact entry and the exact file when there is no connections array', function () {
    $install = instMutInstall([
        'config/broadcasting.php' => "<?php return ['default' => 'null'];",
    ]);

    $result = instMutRun();

    expect($result['status'])->not->toBe(0);
    expect($result['flat'])
        ->toContain('no connections array found')
        ->toContain(
            'Could not find the `connections` array in ['
            .$install->root.'/config/broadcasting.php]. Add this to it by hand: '
            ."'lightspeed' => ['driver' => 'lightspeed'],"
        )
        ->toContain('Nothing was written to .env, because pointing BROADCAST_CONNECTION at a connection that does not exist stops the application booting.');

    expect(instMutEnvValue($install->root.'/.env', 'BROADCAST_CONNECTION'))->toBe('log');
});

/**
 * A publish that reports success but writes nothing is a failed publish.
 *
 * `config:publish` returning 0 is not the same as the file being there, and the
 * step that follows has no file to add a connection to. Believing the exit code
 * over the filesystem would report a published config and then fail one line
 * later for a reason the report contradicts.
 */
it('does not claim a published config when the publish left no file', function () {
    $install = instMutInstall();
    $install->broadcastingPublishWritesFile = false;

    $result = instMutRun();

    expect($result['status'])->not->toBe(0);
    expect($result['flat'])
        ->toContain('could not publish')
        ->toContain('config/broadcasting.php is missing')
        ->toContain('Run `php artisan config:publish broadcasting`, then run this command again.');

    // And .env is untouched, which is the reason this step is allowed to stop
    // the command: BROADCAST_CONNECTION=lightspeed with no such connection
    // defined is an application that throws on boot, including on the run that
    // would fix it.
    expect(instMutEnvValue($install->root.'/.env', 'BROADCAST_CONNECTION'))->toBe('log');
    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET'))->toBeNull();
});

/**
 * The connection is inserted immediately inside the connections array.
 *
 * Byte for byte, because the insertion is an offset arithmetic on a regex
 * match: an offset off by the length of the match writes the entry into the
 * middle of the `'connections' => [` line, which is a config file that no
 * longer parses. Nothing else in the file may move either.
 */
it('inserts the connection immediately after the connections array opens', function () {
    $before = '<?php'.PHP_EOL.PHP_EOL
        .'return ['.PHP_EOL
        ."    'default' => 'log',".PHP_EOL.PHP_EOL
        ."    'connections' => [".PHP_EOL
        ."        'log' => ['driver' => 'log'],".PHP_EOL
        .'    ],'.PHP_EOL
        .'];'.PHP_EOL;

    $install = instMutInstall(['config/broadcasting.php' => $before]);

    expect(instMutRun()['status'])->toBe(0);

    expect(file_get_contents($install->root.'/config/broadcasting.php'))->toBe(
        '<?php'.PHP_EOL.PHP_EOL
        .'return ['.PHP_EOL
        ."    'default' => 'log',".PHP_EOL.PHP_EOL
        ."    'connections' => [".PHP_EOL
        .PHP_EOL
        ."        'lightspeed' => [".PHP_EOL
        ."            'driver' => 'lightspeed',".PHP_EOL
        .'        ],'.PHP_EOL
        ."        'log' => ['driver' => 'log'],".PHP_EOL
        .'    ],'.PHP_EOL
        .'];'.PHP_EOL
    );
});

/**
 * The old BroadcastServiceProvider is only enabled where one exists.
 *
 * Both files have to be there. Uncommenting a provider line in config/app.php
 * for a class the application does not have is an application that cannot boot,
 * and a Laravel 11 or 12 skeleton has the config file and no provider.
 */
it('leaves config/app.php alone when there is no BroadcastServiceProvider', function () {
    $config = "<?php return ['providers' => [\n    // App\Providers\BroadcastServiceProvider::class,\n]];";

    $install = instMutInstall(['config/app.php' => $config]);

    expect(instMutRun()['status'])->toBe(0);

    expect(file_get_contents($install->root.'/config/app.php'))->toBe($config);
    expect(instMutRun()['flat'])->not->toContain('BroadcastServiceProvider enabled');
});

/** And when it does enable one, it says so. */
it('says it enabled an older application\'s BroadcastServiceProvider', function () {
    instMutInstall([
        'app/Providers/BroadcastServiceProvider.php' => '<?php',
        'config/app.php' => "<?php return ['providers' => [\n    // App\Providers\BroadcastServiceProvider::class,\n]];",
    ]);

    expect(instMutRun()['flat'])->toContain('BroadcastServiceProvider enabled');
});

/**
 * The app id is the application's own name, slugged.
 *
 * It is the name clients connect with, so it is derived rather than invented,
 * and it falls back to `lightspeed` only when the name has no slug at all.
 */
it('derives the app id from the application name', function () {
    config()->set('app.name', 'Acme Chat');

    $install = instMutInstall();

    expect(instMutRun()['status'])->toBe(0);

    // Quoted because it is not bare alphanumeric, which is the same rule both
    // the framework's writer and the Laravel 11 fallback apply.
    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_ID'))->toBe('"acme-chat"');
});

it('falls back to lightspeed when the application name has no slug', function () {
    config()->set('app.name', '///');

    $install = instMutInstall();

    expect(instMutRun()['status'])->toBe(0);
    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_ID'))->toBe('lightspeed');
});

/**
 * The generated credentials are the sizes the package documents.
 *
 * These are the only entropy in the scheme: the key is what a client presents
 * and the secret is what every auth signature and every owner command is signed
 * with. Their lengths are a security parameter, not a formatting choice.
 */
it('generates a twenty character key and a forty character secret', function () {
    $install = instMutInstall();

    expect(instMutRun()['status'])->toBe(0);

    $key = instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_KEY');
    $secret = instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET');

    expect($key)->toMatch('/^[a-z0-9]{20}$/');
    expect($secret)->toMatch('/^[a-zA-Z0-9]{40}$/');
});

/**
 * A key that is present but empty is filled, and a key that is present and set
 * is left alone while the next one is still written.
 *
 * Both halves matter. An empty value is a placeholder somebody committed, not a
 * decision; and stopping at the first key that is already set would leave the
 * rest of the credentials unwritten with nothing in the report to say so.
 */
it('fills an app id that is present but empty', function () {
    $install = instMutInstall([
        '.env' => instMutEnvFile().'LIGHTSPEED_APP_ID='.PHP_EOL,
    ]);

    expect(instMutRun()['status'])->toBe(0);
    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_ID'))->toBe('laravel');
});

it('still writes the app key when the app id is kept', function () {
    $install = instMutInstall([
        '.env' => instMutEnvFile().'LIGHTSPEED_APP_ID=mine'.PHP_EOL,
    ]);

    expect(instMutRun()['status'])->toBe(0);
    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_ID'))->toBe('mine');
    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_KEY'))->toMatch('/^[a-z0-9]{20}$/');
});

/**
 * --force regenerates the app key, and never the secret.
 *
 * The key is public and rotating it costs a reconnect. The secret is not: every
 * connected client and every signed auth string on a deployment that was working
 * would stop verifying, and the command cannot tell it is not looking at one.
 */
it('regenerates the app key with force and keeps the secret', function () {
    $install = instMutInstall();

    expect(instMutRun()['status'])->toBe(0);

    $key = instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_KEY');
    $secret = instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET');

    expect(instMutRun(['--force' => true])['status'])->toBe(0);

    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_KEY'))->not->toBe($key);
    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET'))->toBe($secret);
});

/**
 * .env.example gets placeholders, never values, and never loses the ones it has.
 *
 * That file is committed. Writing the real key or the real secret into it
 * publishes them, and overwriting an application's own placeholder text throws
 * away something a human wrote for other humans.
 */
it('writes empty placeholders for the credentials into .env.example', function () {
    $install = instMutInstall();

    expect(instMutRun()['status'])->toBe(0);

    expect(instMutEnvValue($install->root.'/.env.example', 'LIGHTSPEED_APP_KEY'))->toBe('');
    expect(instMutEnvValue($install->root.'/.env.example', 'LIGHTSPEED_APP_SECRET'))->toBe('');
    expect(instMutEnvValue($install->root.'/.env.example', 'LIGHTSPEED_APP_ID'))->toBe('laravel');
    expect(instMutEnvValue($install->root.'/.env.example', 'BROADCAST_CONNECTION'))->toBe('lightspeed');
});

it('keeps the placeholders .env.example already has', function () {
    $install = instMutInstall([
        '.env.example' => instMutEnvFile()
            .'LIGHTSPEED_APP_ID=your-app-id'.PHP_EOL
            .'LIGHTSPEED_APP_KEY=your-app-key'.PHP_EOL
            .'LIGHTSPEED_APP_SECRET=your-app-secret'.PHP_EOL,
    ]);

    expect(instMutRun()['status'])->toBe(0);

    expect(instMutEnvValue($install->root.'/.env.example', 'LIGHTSPEED_APP_ID'))->toBe('your-app-id');
    expect(instMutEnvValue($install->root.'/.env.example', 'LIGHTSPEED_APP_KEY'))->toBe('your-app-key');
    expect(instMutEnvValue($install->root.'/.env.example', 'LIGHTSPEED_APP_SECRET'))->toBe('your-app-secret');
});

/**
 * A longer key that starts with the one being looked for is a different key.
 *
 * `LIGHTSPEED_APP_SECRET_BACKUP` is not `LIGHTSPEED_APP_SECRET`. A reader that
 * matched on the prefix would read the backup's value, conclude the secret is
 * already set, and leave the application with no LIGHTSPEED_APP_SECRET at all,
 * which is a boot failure the report would describe as "kept".
 */
it('does not read a longer key that merely starts with the one it wants', function () {
    $install = instMutInstall([
        '.env' => instMutEnvFile().'LIGHTSPEED_APP_SECRET_BACKUP=old-secret-from-somewhere'.PHP_EOL,
    ]);

    expect(instMutRun()['status'])->toBe(0);

    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET'))->toMatch('/^[a-zA-Z0-9]{40}$/');
    expect(instMutEnvValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET_BACKUP'))->toBe('old-secret-from-somewhere');
});

/**
 * A quoted, padded connection name reads as the name it quotes.
 *
 * .env files are written by people and by editors, so `BROADCAST_CONNECTION=
 * "realtime"` names the connection `realtime`. Reading it as `"realtime"` with
 * the quotes attached finds no such connection, and the command would rewrite a
 * correctly configured application to point at a different connection than the
 * one it was using.
 */
it('reads a quoted and padded broadcast connection as its bare name', function () {
    config()->set('broadcasting.connections.realtime', ['driver' => 'lightspeed']);

    $install = instMutInstall([
        '.env' => 'APP_NAME=Laravel'.PHP_EOL.'BROADCAST_CONNECTION= "realtime" '.PHP_EOL,
    ]);

    $result = instMutRun();

    expect($result['status'])->toBe(0);
    expect($result['flat'])->toContain('kept as realtime, already a lightspeed connection');
    expect(file_get_contents($install->root.'/.env'))->toContain('BROADCAST_CONNECTION= "realtime" ');
});

/**
 * An .env with no BROADCAST_CONNECTION at all gets one.
 *
 * The "leave it alone" branch is for an application already pointed at a
 * lightspeed connection under another name. A missing line is not that: there
 * is no name to keep, and an application left with no BROADCAST_CONNECTION
 * broadcasts to the framework's default driver and delivers nothing, while the
 * report claims the connection was kept.
 */
it('writes a broadcast connection when .env names none', function () {
    $install = instMutInstall([
        '.env' => 'APP_NAME=Laravel'.PHP_EOL.'APP_ENV=local'.PHP_EOL,
    ]);

    $result = instMutRun();

    expect($result['status'])->toBe(0);
    expect(instMutEnvValue($install->root.'/.env', 'BROADCAST_CONNECTION'))->toBe('lightspeed');
    expect($result['flat'])->not->toContain('already a lightspeed connection');
});

/**
 * An empty BROADCAST_CONNECTION is not a connection name either.
 *
 * The empty string is the value a half-edited .env carries, and it is not the
 * name of anything. The configuration here deliberately defines a connection
 * under the empty name with the lightspeed driver, which is the one shape in
 * which "is this already a lightspeed connection?" can answer yes for a value
 * that names nothing. It has to be filled in anyway.
 */
it('writes a broadcast connection when .env leaves it empty', function () {
    config()->set('broadcasting.connections', ['' => ['driver' => 'lightspeed']]);

    $install = instMutInstall([
        '.env' => 'APP_NAME=Laravel'.PHP_EOL.'BROADCAST_CONNECTION='.PHP_EOL,
    ]);

    expect(instMutRun()['status'])->toBe(0);
    expect(instMutEnvValue($install->root.'/.env', 'BROADCAST_CONNECTION'))->toBe('lightspeed');
});

/**
 * With no .env there is nothing to write, so the four lines are printed whole.
 *
 * This output is the entire result of the run for that application: the user
 * pastes it. Every one of the four names has to arrive with its own value, in a
 * form that can be pasted without editing.
 */
it('prints the four lines to add when the application has no .env', function () {
    config()->set('app.name', 'Acme Chat');

    instMutInstall(['.env' => null, '.env.example' => null]);

    $result = instMutRun();

    expect($result['status'])->toBe(0);
    expect($result['flat'])->toContain('not found');
    expect($result['flat'])->toMatch(
        '/No \.env file, so nothing was written\. Add these lines to yours: '
        .'BROADCAST_CONNECTION=lightspeed '
        .'LIGHTSPEED_APP_ID=acme-chat '
        .'LIGHTSPEED_APP_KEY=[a-z0-9]{20} '
        .'LIGHTSPEED_APP_SECRET=[a-zA-Z0-9]{40}/'
    );
});

/**
 * The .env is edited the way the framework's own writer edits it.
 *
 * The command hands .env writing to Env::writeVariable where that exists, and
 * carries a hand-rolled fallback for Laravel 11 where it does not. The two are
 * not interchangeable: the framework's writer separates an appended variable
 * from the block above it with a blank line, and touches only the line it
 * rewrites, leaving every other line's bytes, including its line ending, alone.
 * Anything that quietly rewrote the whole file would show up here first.
 */
it('appends to .env the way the framework writer does', function () {
    if (! method_exists(Env::class, 'writeVariable')) {
        expect(true)->toBeTrue();

        return;
    }

    $install = instMutInstall([
        '.env' => 'APP_NAME=Laravel'."\r\n".'BROADCAST_CONNECTION=log'."\r\n",
    ]);

    expect(instMutRun()['status'])->toBe(0);

    $env = (string) file_get_contents($install->root.'/.env');

    expect($env)->toContain('APP_NAME=Laravel'."\r\n");
    expect($env)->toMatch('/\n\nLIGHTSPEED_APP_ID=laravel\n/');
});

/**
 * Quoting a value is what stops it from being read back as something else.
 *
 * The Laravel 11 fallback writer has to quote exactly what the framework's
 * writer quotes, because the file it produces is read by the same parser. A
 * value that came back different from the one written is a credential that
 * silently does not match the one the server was told about.
 */
it('quotes an env value exactly the way the framework quotes it', function () {
    // These expectations changed once: the original quoteEnvValue used
    // addcslashes and never single-quoted, which read back fine but produced
    // different BYTES from Env::prepareQuotedValue, so a Laravel 11 install
    // and a Laravel 12 install disagreed about the same .env. The contract is
    // now byte parity with the framework (the caller, not this method,
    // decides that a plain value goes unquoted), pinned end to end in
    // tests/Unit/EnvWriterFallbackParityTest.php.
    $quote = new ReflectionMethod(LightspeedInstall::class, 'quoteEnvValue');
    $quote->setAccessible(true);

    $command = new LightspeedInstall;

    expect($quote->invoke($command, 'has space'))->toBe('"has space"');
    expect($quote->invoke($command, 'say "hi"'))->toBe("'say \"hi\"'");
    expect($quote->invoke($command, 'back\\slash'))->toBe('"back\\\\slash"');

    foreach (['plainValue123', 'has space', 'say "hi"', 'back\\slash', 'a$b', "with'quote"] as $value) {
        expect(Dotenv\Dotenv::parse('K='.$quote->invoke($command, $value)))
            ->toBe(['K' => $value]);
    }
});

/**
 * The channels routes file we carry is the framework's own stub, byte for byte.
 *
 * The command prefers the framework's copy and falls back to this one, so the
 * two being identical is what makes the fallback invisible: an application
 * installed either way ends up with the same file. When Laravel changes its
 * stub, this is the test that says so.
 */
it('carries the framework channels stub byte for byte', function () {
    $stub = dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/broadcasting-routes.stub';

    expect($stub)->toBeFile();

    $constant = (new ReflectionClass(LightspeedInstall::class))->getConstant('CHANNELS_ROUTES');

    expect($constant)->toBe(file_get_contents($stub));
});

/** The package config is skipped on request, and says it was skipped. */
it('says it skipped the package config when asked to', function () {
    $install = instMutInstall();

    $result = instMutRun(['--without-config' => true]);

    expect($result['status'])->toBe(0);
    expect($result['flat'])->toContain('skipped');
    expect($install->packagePublishes)->toBe(0);
});

/**
 * A publish that fails says how to do it by hand.
 *
 * Not fatal: everything else is wired, and `lightspeed:doctor` will ask for the
 * same file. But an install that ends with an unpublished config and no way to
 * find that out leaves the doctor's warning as the first news of it.
 */
it('says how to publish the package config by hand when publishing fails', function () {
    $install = instMutInstall();
    $install->packagePublishSucceeds = false;

    $result = instMutRun();

    expect($result['status'])->toBe(0);
    expect($result['flat'])
        ->toContain('not published')
        ->toContain('Run `php artisan vendor:publish --tag=lightspeed-config` to publish it.');
});
