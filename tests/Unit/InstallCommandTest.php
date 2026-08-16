<?php

use Illuminate\Support\Facades\Artisan;
use Lightspeed\Console\Commands\LightspeedInstall;

/**
 * `lightspeed:install` writes into a real application's files: bootstrap/app.php,
 * config/broadcasting.php, routes/channels.php, .env and .env.example. So these
 * tests give it a real directory of real files and read back what it wrote,
 * rather than asserting on what it printed. The output is asserted only where
 * the output IS the product (the case where the command cannot wire the routes
 * and has to hand the job back to the user).
 *
 * The command reaches its paths through overridable methods for exactly this
 * reason, the same seam `lightspeed:doctor` uses. The alternative is letting a
 * test write into the Testbench skeleton, which is shared, is not a fresh
 * application, and would leave the next test reading the previous one's files.
 */
final class FakeInstall extends LightspeedInstall
{
    public string $root = '';

    /** Set when the fake was asked to publish the framework's broadcasting config. */
    public bool $publishedBroadcastingConfig = false;

    /** Set when the fake was asked to publish config/lightspeed.php. */
    public bool $publishedPackageConfig = false;

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

    /**
     * Stand in for `config:publish`, which would write into the Testbench
     * skeleton's config directory rather than this test's application.
     *
     * It copies the same file that command copies, so what the rest of the
     * command sees is byte for byte what a real publish leaves behind.
     */
    protected function publishBroadcastingConfig(): bool
    {
        $this->publishedBroadcastingConfig = true;

        @mkdir(dirname($this->broadcastingConfigPath()), 0755, true);

        return copy(
            dirname(__DIR__, 2).'/vendor/laravel/framework/config/broadcasting.php',
            $this->broadcastingConfigPath(),
        );
    }

    protected function publishPackageConfig(): bool
    {
        $this->publishedPackageConfig = true;

        @mkdir($this->root.'/config', 0755, true);

        return copy(dirname(__DIR__, 2).'/config/lightspeed.php', $this->root.'/config/lightspeed.php');
    }

    protected function packageConfigPublished(): bool
    {
        return is_file($this->root.'/config/lightspeed.php');
    }
}

/** The bootstrap/app.php a fresh Laravel 12 application ships with. */
function installFreshBootstrapFile(): string
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
        )
        ->withMiddleware(function (Middleware $middleware): void {
            //
        })
        ->withExceptions(function (Exceptions $exceptions): void {
            //
        })->create();
    PHP;
}

/** The .env a fresh Laravel application ships with, trimmed to what matters here. */
function installFreshEnvFile(): string
{
    return implode(PHP_EOL, [
        'APP_NAME=Laravel',
        'APP_ENV=local',
        'APP_KEY=base64:AAAA',
        '',
        'BROADCAST_CONNECTION=log',
        'CACHE_STORE=database',
        '',
    ]);
}

/**
 * Build a throwaway application directory and an install command pointed at it.
 *
 * `$files` overrides any of the defaults by relative path; passing null for a
 * path means "this application does not have that file", which is how the
 * tests describe an app with no .env, or no bootstrap file to wire.
 */
function fakeInstall(array $files = []): FakeInstall
{
    $root = sys_get_temp_dir().'/lightspeed-install-'.bin2hex(random_bytes(6));

    $defaults = [
        'bootstrap/app.php' => installFreshBootstrapFile(),
        '.env' => installFreshEnvFile(),
        '.env.example' => installFreshEnvFile(),
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

    $command = new FakeInstall;
    $command->root = $root;

    app()->instance(LightspeedInstall::class, $command);

    return $command;
}

/** Run the install command and hand back its exit code and flattened output. */
function runInstall(array $parameters = []): array
{
    $status = Artisan::call('lightspeed:install', $parameters);
    $output = Artisan::output();

    return [
        'status' => $status,
        'output' => $output,
        'flat' => trim((string) preg_replace('/\s+/', ' ', $output)),
    ];
}

/** Every file in the application, keyed by relative path, for comparing runs. */
function installSnapshot(string $root): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}

function envValue(string $path, string $key): ?string
{
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, $key.'=')) {
            return trim(substr($line, strlen($key) + 1), '"');
        }
    }

    return null;
}

it('sets up a fresh Laravel application in one run', function () {
    $install = fakeInstall();

    $result = runInstall(['--no-interaction' => true]);

    expect($result['status'])->toBe(0);
    expect($install->root.'/routes/channels.php')->toBeFile();
    expect(file_get_contents($install->root.'/bootstrap/app.php'))
        ->toContain("channels: __DIR__.'/../routes/channels.php',");
    expect(file_get_contents($install->root.'/config/broadcasting.php'))
        ->toContain("'lightspeed' => [");
    expect(envValue($install->root.'/.env', 'BROADCAST_CONNECTION'))->toBe('lightspeed');
    expect(envValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET'))->not->toBe('');
    expect($result['flat'])->toContain('lightspeed:doctor');
});

it('uncomments an already commented channels route', function () {
    $install = fakeInstall([
        'bootstrap/app.php' => str_replace(
            "commands: __DIR__.'/../routes/console.php',",
            "commands: __DIR__.'/../routes/console.php',\n        // channels: __DIR__.'/../routes/channels.php',",
            installFreshBootstrapFile(),
        ),
    ]);

    expect(runInstall()['status'])->toBe(0);

    $bootstrap = file_get_contents($install->root.'/bootstrap/app.php');

    expect($bootstrap)->toContain("channels: __DIR__.'/../routes/channels.php',");
    expect($bootstrap)->not->toContain('// channels: ');
    expect(substr_count($bootstrap, 'channels: '))->toBe(1);
});

it('leaves an already wired bootstrap file alone', function () {
    $wired = str_replace(
        "commands: __DIR__.'/../routes/console.php',",
        "commands: __DIR__.'/../routes/console.php',\n        channels: __DIR__.'/../routes/channels.php',",
        installFreshBootstrapFile(),
    );

    $install = fakeInstall(['bootstrap/app.php' => $wired]);

    expect(runInstall()['status'])->toBe(0);
    expect(file_get_contents($install->root.'/bootstrap/app.php'))->toBe($wired);
});

it('inserts channels after the console commands line', function () {
    $install = fakeInstall();

    expect(runInstall()['status'])->toBe(0);

    expect(file_get_contents($install->root.'/bootstrap/app.php'))->toContain(
        "commands: __DIR__.'/../routes/console.php',".PHP_EOL."        channels: __DIR__.'/../routes/channels.php',"
    );
});

it('inserts channels after withRouting when there is no console commands line', function () {
    $install = fakeInstall([
        'bootstrap/app.php' => str_replace(
            "        commands: __DIR__.'/../routes/console.php',\n",
            '',
            installFreshBootstrapFile(),
        ),
    ]);

    expect(runInstall()['status'])->toBe(0);

    expect(file_get_contents($install->root.'/bootstrap/app.php'))->toContain(
        '->withRouting('.PHP_EOL."        channels: __DIR__.'/../routes/channels.php',"
    );
});

it('names the file to edit when it cannot wire the routes itself', function () {
    $install = fakeInstall(['bootstrap/app.php' => '<?php return 1;']);

    $result = runInstall();

    expect($result['status'])->not->toBe(0);
    expect($result['flat'])->toContain($install->root.'/bootstrap/app.php');
    expect($result['flat'])->toContain("channels: __DIR__.'/../routes/channels.php'");
});

it('enables an older application\'s BroadcastServiceProvider', function () {
    $install = fakeInstall([
        'app/Providers/BroadcastServiceProvider.php' => '<?php',
        'config/app.php' => "<?php return ['providers' => [\n    // App\Providers\BroadcastServiceProvider::class,\n]];",
    ]);

    expect(runInstall()['status'])->toBe(0);

    expect(file_get_contents($install->root.'/config/app.php'))
        ->toContain('App\Providers\BroadcastServiceProvider::class')
        ->not->toContain('// App\Providers\BroadcastServiceProvider::class');
});

it('changes nothing on a second run', function () {
    $install = fakeInstall();

    expect(runInstall()['status'])->toBe(0);

    $first = installSnapshot($install->root);

    expect(runInstall()['status'])->toBe(0);

    expect(installSnapshot($install->root))->toBe($first);
});

it('changes nothing on a second run with --force', function () {
    $install = fakeInstall();

    expect(runInstall()['status'])->toBe(0);

    $secret = envValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET');

    expect(runInstall(['--force' => true])['status'])->toBe(0);

    expect(envValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET'))->toBe($secret);
});

it('never overwrites an existing app secret', function () {
    $install = fakeInstall([
        '.env' => installFreshEnvFile().PHP_EOL.'LIGHTSPEED_APP_SECRET=already-in-production'.PHP_EOL,
    ]);

    expect(runInstall(['--force' => true])['status'])->toBe(0);

    expect(envValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET'))->toBe('already-in-production');
});

it('fills an app secret that exists but is empty', function () {
    $install = fakeInstall([
        '.env' => installFreshEnvFile().PHP_EOL.'LIGHTSPEED_APP_SECRET='.PHP_EOL,
    ]);

    expect(runInstall()['status'])->toBe(0);

    expect(envValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET'))->not->toBe('');
});

it('never writes the real secret into .env.example', function () {
    $install = fakeInstall();

    expect(runInstall()['status'])->toBe(0);

    $secret = envValue($install->root.'/.env', 'LIGHTSPEED_APP_SECRET');
    $key = envValue($install->root.'/.env', 'LIGHTSPEED_APP_KEY');
    $example = file_get_contents($install->root.'/.env.example');

    expect($secret)->not->toBeNull();
    expect($example)
        ->not->toContain($secret)
        ->not->toContain($key)
        ->toContain('BROADCAST_CONNECTION=lightspeed')
        ->toContain('LIGHTSPEED_APP_SECRET=');
});

it('adds the connection before it points the app at it', function () {
    // A broadcasting config with no connections array is the one shape where
    // the connection cannot be added. Writing BROADCAST_CONNECTION=lightspeed
    // anyway would leave the application throwing on boot, which is the exact
    // failure the ordering exists to prevent, so nothing may be written.
    $install = fakeInstall([
        'config/broadcasting.php' => "<?php return ['default' => env('BROADCAST_CONNECTION', 'null')];",
    ]);

    $result = runInstall();

    expect($result['status'])->not->toBe(0);
    expect(envValue($install->root.'/.env', 'BROADCAST_CONNECTION'))->toBe('log');
    expect($result['flat'])->toContain('config/broadcasting.php');
});

it('keeps a broadcast connection that already uses the lightspeed driver', function () {
    config(['broadcasting.connections.realtime' => ['driver' => 'lightspeed']]);

    $install = fakeInstall([
        '.env' => str_replace('BROADCAST_CONNECTION=log', 'BROADCAST_CONNECTION=realtime', installFreshEnvFile()),
    ]);

    expect(runInstall()['status'])->toBe(0);

    expect(envValue($install->root.'/.env', 'BROADCAST_CONNECTION'))->toBe('realtime');
});

it('publishes the package config so the doctor has a file to read', function () {
    $install = fakeInstall();

    expect(runInstall()['status'])->toBe(0);

    expect($install->publishedPackageConfig)->toBeTrue();
    expect($install->root.'/config/lightspeed.php')->toBeFile();
});

it('skips publishing the package config when asked to', function () {
    $install = fakeInstall();

    expect(runInstall(['--without-config' => true])['status'])->toBe(0);

    expect($install->publishedPackageConfig)->toBeFalse();
});

it('does not republish a broadcasting config the application already has', function () {
    $install = fakeInstall([
        'config/broadcasting.php' => file_get_contents(
            dirname(__DIR__, 2).'/vendor/laravel/framework/config/broadcasting.php'
        ),
    ]);

    expect(runInstall()['status'])->toBe(0);

    expect($install->publishedBroadcastingConfig)->toBeFalse();
});

it('does not overwrite an existing channels route file', function () {
    $install = fakeInstall();

    file_put_contents($install->root.'/routes/channels.php', '<?php // mine');

    expect(runInstall()['status'])->toBe(0);

    expect(file_get_contents($install->root.'/routes/channels.php'))->toBe('<?php // mine');
});

it('overwrites the channels route file with --force', function () {
    $install = fakeInstall();

    file_put_contents($install->root.'/routes/channels.php', '<?php // mine');

    expect(runInstall(['--force' => true])['status'])->toBe(0);

    expect(file_get_contents($install->root.'/routes/channels.php'))->toContain('Broadcast::channel(');
});

it('says what to do when the application has no .env', function () {
    $install = fakeInstall(['.env' => null, '.env.example' => null]);

    $result = runInstall();

    expect($result['status'])->toBe(0);
    expect($result['flat'])->toContain('LIGHTSPEED_APP_SECRET');
});
