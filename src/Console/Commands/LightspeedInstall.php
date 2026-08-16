<?php

namespace Lightspeed\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Everything an application has to do before `lightspeed:doctor` can pass.
 *
 * This exists because Laravel's own `install:broadcasting` cannot do it. That
 * command asks which broadcasting driver you want and offers reverb, pusher or
 * ably. Lightspeed is none of the three, so there is no answer that is true:
 * under `--no-interaction` the prompt throws, and interactively the user picks
 * a driver they do not want and the installer writes that driver's name into
 * BROADCAST_CONNECTION, which the next step then has to undo. `--pusher` only
 * moves the problem along to a prompt for a Pusher app id. And a fresh Laravel
 * 12 application has no `channels:` line in bootstrap/app.php at all, so the
 * one step nobody can skip is also the one step there was no command for.
 *
 * So the parts of Laravel's installer that DO apply are mirrored here rather
 * than reinvented, most visibly the four-branch cascade that wires the channels
 * routes file into bootstrap/app.php. That cascade is copied deliberately: an
 * application that has run `install:broadcasting` before, or that will run it
 * later, should end up with a bootstrap file that looks the same either way,
 * and a user comparing this command's edit against the framework's should find
 * nothing to reconcile.
 *
 * ORDER IS LOAD-BEARING. `config/broadcasting.php` has to gain the lightspeed
 * connection BEFORE `.env` names it, because Laravel throws "Broadcast
 * connection [lightspeed] is not defined" the moment anything resolves the
 * default broadcaster, which happens during boot while `withRouting(channels:)`
 * loads routes/channels.php. Half an install in that order is an application
 * that will not boot, including to run this command again. So if the connection
 * cannot be added, nothing is written to .env and the command fails loudly.
 *
 * Never prompts, on any path. The whole reason it exists is a prompt.
 */
class LightspeedInstall extends Command
{
    protected $signature = 'lightspeed:install
        {--force : Overwrite the channels routes file, and regenerate the app id and key. Never touches an existing app secret}
        {--without-config : Do not publish config/lightspeed.php}';

    protected $description = 'Set up broadcasting for Lightspeed: channel routes, the broadcast connection, and credentials';

    /**
     * Whether the channels routes file we ship is what Laravel's installer ships.
     *
     * Kept identical on purpose. An application that runs this command and one
     * that ran `install:broadcasting` should have the same starting file.
     */
    private const CHANNELS_ROUTES = <<<'PHP'
    <?php

    use Illuminate\Support\Facades\Broadcast;

    Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
        return (int) $user->id === (int) $id;
    });

    PHP;

    /** Set by any step that failed in a way the user has to act on. */
    private bool $failed = false;

    public function handle(): int
    {
        $this->components->info('Setting up Lightspeed broadcasting.');

        $this->installBroadcastingConfig();
        $this->installChannelsRoutesFile();
        $this->uncommentChannelsRoutesFile();
        $this->enableBroadcastServiceProvider();

        // See the class docblock: the connection has to exist before anything
        // points the application at it, so a failure here stops the command
        // rather than leaving an application that throws on boot.
        if (! $this->addLightspeedConnection()) {
            $this->components->error('Nothing was written to .env, because pointing BROADCAST_CONNECTION at a connection that does not exist stops the application booting.');

            return self::FAILURE;
        }

        $this->writeEnvironment();
        $this->installPackageConfig();

        $this->newLine();
        $this->components->info('Done. Run `php artisan lightspeed:doctor` to check the rest of the setup.');

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Publish the framework's broadcasting config if the application has none.
     *
     * A fresh Laravel 11/12 application does not ship config/broadcasting.php,
     * it reads the framework's copy. There is nowhere to add a connection to a
     * file that does not exist, so this is the first step and the connection
     * step depends on it.
     */
    private function installBroadcastingConfig(): void
    {
        if (is_file($this->broadcastingConfigPath())) {
            $this->components->twoColumnDetail('config/broadcasting.php', '<fg=gray>already present</>');

            return;
        }

        if ($this->publishBroadcastingConfig() && is_file($this->broadcastingConfigPath())) {
            $this->components->twoColumnDetail('config/broadcasting.php', '<fg=green>published</>');

            return;
        }

        // Not fatal on its own: addLightspeedConnection() reports the specific
        // reason it cannot proceed, and that is the message worth reading.
        $this->components->twoColumnDetail('config/broadcasting.php', '<fg=red>could not publish</>');
    }

    /** Copy the channel routes file in, the way Laravel's installer does. */
    private function installChannelsRoutesFile(): void
    {
        $path = $this->channelsRoutePath();

        if (is_file($path) && ! $this->option('force')) {
            $this->components->twoColumnDetail('routes/channels.php', '<fg=gray>already present</>');

            return;
        }

        if (! is_dir($directory = dirname($path))) {
            mkdir($directory, 0755, true);
        }

        // Prefer the framework's own stub so the file stays whatever Laravel
        // currently ships; fall back to our copy of it when the framework
        // moves or renames the stub, because failing to create the file at all
        // would leave the bootstrap wiring below pointing at nothing.
        $stub = $this->frameworkChannelsStubPath();

        if ($stub !== null) {
            copy($stub, $path);
        } else {
            file_put_contents($path, self::CHANNELS_ROUTES);
        }

        $this->components->twoColumnDetail('routes/channels.php', '<fg=green>created</>');
    }

    /**
     * Register the channels routes file in bootstrap/app.php.
     *
     * Copied branch for branch from Laravel's
     * BroadcastingInstallCommand::uncommentChannelsRoutesFile(). Matching it
     * matters more than improving on it: two commands that edit the same line
     * of the same file should produce the same file.
     */
    private function uncommentChannelsRoutesFile(): void
    {
        $path = $this->bootstrapAppPath();

        if (! is_file($path)) {
            $this->reportUnwirableBootstrapFile($path);

            return;
        }

        $filesystem = new Filesystem;
        $content = $filesystem->get($path);

        if (str_contains($content, '// channels: ')) {
            $filesystem->replaceInFile('// channels: ', 'channels: ', $path);

            $this->components->twoColumnDetail('bootstrap/app.php', '<fg=green>channels routes uncommented</>');
        } elseif (str_contains($content, 'channels: ')) {
            $this->components->twoColumnDetail('bootstrap/app.php', '<fg=gray>channels routes already registered</>');
        } elseif (str_contains($content, 'commands: __DIR__.\'/../routes/console.php\',')) {
            $filesystem->replaceInFile(
                'commands: __DIR__.\'/../routes/console.php\',',
                'commands: __DIR__.\'/../routes/console.php\','.PHP_EOL.'        channels: __DIR__.\'/../routes/channels.php\',',
                $path,
            );

            $this->components->twoColumnDetail('bootstrap/app.php', '<fg=green>channels routes registered</>');
        } elseif (str_contains($content, '->withRouting(')) {
            $filesystem->replaceInFile(
                '->withRouting(',
                '->withRouting('.PHP_EOL.'        channels: __DIR__.\'/../routes/channels.php\',',
                $path,
            );

            $this->components->twoColumnDetail('bootstrap/app.php', '<fg=green>channels routes registered</>');
        } else {
            $this->reportUnwirableBootstrapFile($path);
        }
    }

    /**
     * Say exactly what to paste, and fail.
     *
     * Without this line no channel authorization callback is ever loaded, so
     * every private and presence subscribe is refused and the client is told
     * only that authorization failed. That is worth a non-zero exit: a deploy
     * script should stop here rather than carry on to a server that cannot
     * authorize anyone.
     */
    private function reportUnwirableBootstrapFile(string $path): void
    {
        $this->failed = true;

        $this->components->twoColumnDetail('bootstrap/app.php', '<fg=red>could not register channels routes</>');

        // The sentence goes through the component, the pasteable line does
        // not: components->error() collapses whitespace and appends a full
        // stop, which turns text a user is told to paste into a run-on
        // sentence with punctuation inside it.
        $this->components->error('Unable to register broadcast routes. Add this line inside ->withRouting(...) in ['.$path.']:');
        $this->line("    channels: __DIR__.'/../routes/channels.php',");
        $this->newLine();
    }

    /**
     * Uncomment App\Providers\BroadcastServiceProvider, for older layouts only.
     *
     * Same conditions as Laravel's enableBroadcastServiceProvider(): both files
     * have to exist, which they do not in a Laravel 11 or 12 skeleton. The one
     * deliberate difference is that the provider file is looked for under the
     * application's base path rather than relative to the working directory,
     * so it is found when artisan is run from elsewhere.
     */
    private function enableBroadcastServiceProvider(): void
    {
        $filesystem = new Filesystem;

        if (! $filesystem->exists($this->appConfigPath()) || ! $filesystem->exists($this->broadcastServiceProviderPath())) {
            return;
        }

        $config = $filesystem->get($this->appConfigPath());

        if (str_contains($config, '// App\Providers\BroadcastServiceProvider::class')) {
            $filesystem->replaceInFile(
                '// App\Providers\BroadcastServiceProvider::class',
                'App\Providers\BroadcastServiceProvider::class',
                $this->appConfigPath(),
            );

            $this->components->twoColumnDetail('config/app.php', '<fg=green>BroadcastServiceProvider enabled</>');
        }
    }

    /**
     * Add the lightspeed connection to config/broadcasting.php.
     *
     * Returns false when the caller must not go on to write .env. Both failure
     * cases are "there is no connections array to add to", which the user has
     * to fix by hand, and until they do, naming the connection in .env would
     * stop the application booting.
     */
    private function addLightspeedConnection(): bool
    {
        $path = $this->broadcastingConfigPath();

        if (! is_file($path)) {
            $this->components->twoColumnDetail('broadcast connection', '<fg=red>config/broadcasting.php is missing</>');
            $this->components->error('Run `php artisan config:publish broadcasting`, then run this command again.');

            $this->failed = true;

            return false;
        }

        $config = (string) file_get_contents($path);

        // The driver name, not the connection name: an application is free to
        // call the connection whatever it likes, and several do (see the
        // service provider docblock). Any connection already using this driver
        // means the file is done.
        if (preg_match("/'driver'\s*=>\s*'lightspeed'/", $config) === 1) {
            $this->components->twoColumnDetail('broadcast connection', '<fg=gray>already in config/broadcasting.php</>');

            return true;
        }

        if (! preg_match("/(['\"])connections\\1\s*=>\s*\[\r?\n/", $config, $match, PREG_OFFSET_CAPTURE)) {
            $this->components->twoColumnDetail('broadcast connection', '<fg=red>no connections array found</>');

            // Sentence through the component, pasteable line raw; see
            // reportUnwirableBootstrapFile for why.
            $this->components->error('Could not find the `connections` array in ['.$path.']. Add this to it by hand:');
            $this->line("    'lightspeed' => ['driver' => 'lightspeed'],");
            $this->newLine();

            $this->failed = true;

            return false;
        }

        $insertAt = $match[0][1] + strlen($match[0][0]);

        $entry = PHP_EOL
            ."        'lightspeed' => ["
            .PHP_EOL."            'driver' => 'lightspeed',"
            .PHP_EOL.'        ],'.PHP_EOL;

        file_put_contents($path, substr($config, 0, $insertAt).$entry.substr($config, $insertAt));

        $this->components->twoColumnDetail('broadcast connection', '<fg=green>added to config/broadcasting.php</>');

        return true;
    }

    /**
     * Write the credentials and the broadcast connection into .env.
     *
     * The secret is generated once and then never touched again, with or
     * without --force. Rotating it silently would break every already
     * connected client and every already signed auth string on a deployment
     * that was working, and there is no way for the command to know it is not
     * looking at one.
     */
    private function writeEnvironment(): void
    {
        $envPath = $this->envPath();
        $examplePath = $this->envExamplePath();

        $appId = Str::slug((string) config('app.name', 'lightspeed')) ?: 'lightspeed';
        $appKey = Str::lower(Str::random(20));
        $appSecret = Str::random(40);

        if (! is_file($envPath)) {
            $this->components->twoColumnDetail('.env', '<fg=yellow>not found</>');

            // Sentence through the component, pasteable lines raw; see
            // reportUnwirableBootstrapFile for why.
            $this->components->warn('No .env file, so nothing was written. Add these lines to yours:');
            $this->line('    BROADCAST_CONNECTION=lightspeed');
            $this->line("    LIGHTSPEED_APP_ID={$appId}");
            $this->line("    LIGHTSPEED_APP_KEY={$appKey}");
            $this->line("    LIGHTSPEED_APP_SECRET={$appSecret}");
            $this->newLine();

            return;
        }

        $env = (string) file_get_contents($envPath);

        foreach ([
            'LIGHTSPEED_APP_ID' => $appId,
            'LIGHTSPEED_APP_KEY' => $appKey,
        ] as $key => $value) {
            $existing = $this->envValue($env, $key);

            if ($existing !== null && $existing !== '' && ! $this->option('force')) {
                continue;
            }

            $this->writeEnvVariable($key, $value, $envPath, true);
        }

        // The one value --force may not touch. See the docblock.
        if (($this->envValue($env, 'LIGHTSPEED_APP_SECRET') ?? '') === '') {
            $this->writeEnvVariable('LIGHTSPEED_APP_SECRET', $appSecret, $envPath, true);
            $this->components->twoColumnDetail('LIGHTSPEED_APP_SECRET', '<fg=green>generated</>');
        } else {
            $this->components->twoColumnDetail('LIGHTSPEED_APP_SECRET', '<fg=gray>kept, never overwritten</>');
        }

        $this->writeBroadcastConnection($env, $envPath);

        if (is_file($examplePath)) {
            // Placeholders only. This file is committed, so the real secret
            // must never reach it, and neither must the real key.
            $this->writeEnvVariable('BROADCAST_CONNECTION', 'lightspeed', $examplePath, true);
            $this->writeEnvVariable('LIGHTSPEED_APP_ID', $appId, $examplePath, false);
            $this->writeEnvVariable('LIGHTSPEED_APP_KEY', '', $examplePath, false);
            $this->writeEnvVariable('LIGHTSPEED_APP_SECRET', '', $examplePath, false);

            $this->components->twoColumnDetail('.env.example', '<fg=green>placeholders written</>');
        }
    }

    /**
     * Point the application's default broadcast connection at Lightspeed.
     *
     * Left alone when it already names a connection whose driver is
     * lightspeed. The connection's NAME is free, so an application running on
     * `BROADCAST_CONNECTION=realtime` is correctly set up already, and
     * rewriting it to `lightspeed` would point it at the connection this
     * command just added instead of the one it configured.
     */
    private function writeBroadcastConnection(string $env, string $envPath): void
    {
        $current = $this->envValue($env, 'BROADCAST_CONNECTION');

        if ($current !== null && $current !== '' && config("broadcasting.connections.{$current}.driver") === 'lightspeed') {
            $this->components->twoColumnDetail('BROADCAST_CONNECTION', "<fg=gray>kept as {$current}, already a lightspeed connection</>");

            return;
        }

        $this->writeEnvVariable('BROADCAST_CONNECTION', 'lightspeed', $envPath, true);

        $this->components->twoColumnDetail('BROADCAST_CONNECTION', '<fg=green>lightspeed</>');
    }

    /**
     * Publish config/lightspeed.php.
     *
     * Done by default, and the reason is `lightspeed:doctor`, which is what
     * the user runs next: it WARNs "config/lightspeed.php has not been
     * published" and tells them to publish it, because a handler class listed
     * in a file that does not exist is never read. An install command that
     * left the doctor asking for a publish step would just be an install
     * command with a missing step. --without-config opts out for applications
     * that would rather track the package defaults.
     */
    private function installPackageConfig(): void
    {
        if ($this->option('without-config')) {
            $this->components->twoColumnDetail('config/lightspeed.php', '<fg=gray>skipped</>');

            return;
        }

        if ($this->packageConfigPublished() && ! $this->option('force')) {
            $this->components->twoColumnDetail('config/lightspeed.php', '<fg=gray>already published</>');

            return;
        }

        if ($this->publishPackageConfig()) {
            $this->components->twoColumnDetail('config/lightspeed.php', '<fg=green>published</>');

            return;
        }

        $this->components->twoColumnDetail('config/lightspeed.php', '<fg=yellow>not published</>');
        $this->components->warn('Run `php artisan vendor:publish --tag=lightspeed-config` to publish it.');
    }

    // --- Seams -------------------------------------------------------------

    protected function broadcastingConfigPath(): string
    {
        return $this->laravel->configPath('broadcasting.php');
    }

    protected function appConfigPath(): string
    {
        return $this->laravel->configPath('app.php');
    }

    protected function broadcastServiceProviderPath(): string
    {
        return $this->laravel->basePath('app/Providers/BroadcastServiceProvider.php');
    }

    protected function channelsRoutePath(): string
    {
        return $this->laravel->basePath('routes/channels.php');
    }

    protected function bootstrapAppPath(): string
    {
        return $this->laravel->bootstrapPath('app.php');
    }

    protected function envPath(): string
    {
        return $this->laravel->basePath('.env');
    }

    protected function envExamplePath(): string
    {
        return $this->laravel->basePath('.env.example');
    }

    protected function packageConfigPublished(): bool
    {
        return is_file($this->laravel->configPath('lightspeed.php'));
    }

    protected function publishBroadcastingConfig(): bool
    {
        return $this->callSilent('config:publish', ['name' => 'broadcasting']) === self::SUCCESS;
    }

    protected function publishPackageConfig(): bool
    {
        return $this->callSilent('vendor:publish', array_filter([
            '--tag' => 'lightspeed-config',
            '--force' => $this->option('force') ?: null,
        ])) === self::SUCCESS;
    }

    // --- Internals ---------------------------------------------------------

    /**
     * Laravel's own channels routes stub, if this framework version has one
     * where it has always been.
     */
    private function frameworkChannelsStubPath(): ?string
    {
        $installer = 'Illuminate\Foundation\Console\BroadcastingInstallCommand';

        if (! class_exists($installer)) {
            return null;
        }

        $file = (new ReflectionClass($installer))->getFileName();

        if ($file === false) {
            return null;
        }

        $stub = dirname($file).'/stubs/broadcasting-routes.stub';

        return is_readable($stub) ? $stub : null;
    }

    /** The value of a key in already read .env contents, or null if absent. */
    private function envValue(string $contents, string $key): ?string
    {
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (str_starts_with($line, $key.'=')) {
                return trim(trim(substr($line, strlen($key) + 1)), '"\'');
            }
        }

        return null;
    }

    /**
     * Write one variable into an env file.
     *
     * Uses Illuminate\Support\Env::writeVariable, which keeps a variable next
     * to others sharing its prefix and quotes values that need it, rather than
     * appending raw text to the end of the file.
     *
     * That method arrived in Laravel 12. This package supports Laravel 11 as
     * well, where appending raw text would be the only alternative, so the
     * fallback below reproduces the same two rules: replace the existing line
     * when overwriting, otherwise leave the file exactly as it was.
     */
    private function writeEnvVariable(string $key, string $value, string $path, bool $overwrite): void
    {
        if (method_exists(Env::class, 'writeVariable')) {
            Env::writeVariable($key, $value, $path, $overwrite);

            return;
        }

        $lines = explode(PHP_EOL, (string) file_get_contents($path));

        file_put_contents($path, implode(PHP_EOL, $this->addVariableToEnvLines($key, $value, $lines, $overwrite)));
    }

    /**
     * The Laravel 11 body of writeEnvVariable, one method for one reason: the
     * suite runs on Laravel 12 where the branch above always wins, and a
     * fallback nothing can reach is a fallback nothing can test. This seam is
     * pinned byte for byte against the real Env::writeVariable in
     * tests/Unit/EnvWriterFallbackParityTest.php.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function addVariableToEnvLines(string $key, string $value, array $lines, bool $overwrite): array
    {
        $prefix = explode('_', $key)[0].'_';
        $lastPrefixIndex = -1;

        // Both spellings the framework recognises as "already there": the
        // quoted form and the bare one.
        $variations = [$key.'='.$this->quoteEnvValue($value), $key.'='.$value];

        $line = preg_match('/^[a-zA-Z0-9]+$/', $value) === 1 ? $key.'='.$value : $variations[0];

        if ($value === '') {
            $line = $key.'=';
        }

        foreach ($lines as $index => $existing) {
            if (str_starts_with($existing, $prefix)) {
                $lastPrefixIndex = $index;
            }

            if (in_array($existing, $variations, true)) {
                return $lines;
            }

            // An empty existing value is filled even without $overwrite; the
            // framework treats "the key is there but says nothing" as a slot
            // to fill, not a decision to respect. The old fallback kept it
            // empty, so an .env.example on Laravel 11 stayed blank where
            // Laravel 12 filled it.
            if ($existing === $key.'=') {
                $lines[$index] = $line;

                return $lines;
            }

            if (str_starts_with($existing, $key.'=')) {
                if (! $overwrite) {
                    return $lines;
                }

                $lines[$index] = $line;

                return $lines;
            }
        }

        if ($lastPrefixIndex === -1) {
            // A blank line before an appended variable, exactly when the file
            // does not already end in one.
            if ($lines !== [] && $lines[count($lines) - 1] !== '') {
                $lines[] = '';
            }

            $lines[] = $line;

            return $lines;
        }

        array_splice($lines, $lastPrefixIndex + 1, 0, [$line]);

        return $lines;
    }

    /**
     * Quote a value exactly the way Env::prepareQuotedValue does: addslashes,
     * then the wrapper flips to single quotes when the value itself carries a
     * double quote, and the quote character the wrapper is not using stays
     * unescaped.
     */
    private function quoteEnvValue(string $value): string
    {
        return str_contains($value, '"')
            ? "'".str_replace('\\"', '"', addslashes($value))."'"
            : '"'.str_replace("\\'", "'", addslashes($value)).'"';
    }
}
