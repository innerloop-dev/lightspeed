<?php

/**
 * Text the installer tells a user to paste must arrive pasteable.
 *
 * Three failure messages end in a block the user is told to copy into a file:
 * the channels: line for bootstrap/app.php, the connection entry for
 * config/broadcasting.php, and the four .env variables. They were built with
 * PHP_EOL layout and handed to components->error()/warn(), which collapse all
 * whitespace to single spaces and append a full stop, so the "pasteable" text
 * arrived as one run-on sentence with punctuation inside it. Found by
 * mutation testing: 15 mutants that only deleted PHP_EOLs were unkillable,
 * because the renderer was already discarding them.
 */

use Illuminate\Support\Facades\Artisan;
use Lightspeed\Console\Commands\LightspeedInstall;

final class PasteableOutputInstall extends LightspeedInstall
{
    public string $root = '';

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

    protected function packageConfigPublished(): bool
    {
        return true;
    }

    protected function publishBroadcastingConfig(): bool
    {
        return false;
    }

    protected function publishPackageConfig(): bool
    {
        return true;
    }
}

function pasteableInstallRoot(): string
{
    $root = sys_get_temp_dir().'/lightspeed-pasteable-'.bin2hex(random_bytes(6));
    mkdir($root.'/config', 0755, true);
    mkdir($root.'/routes', 0755, true);
    mkdir($root.'/bootstrap', 0755, true);

    return $root;
}

function runPasteableInstall(string $root): string
{
    $command = new PasteableOutputInstall();
    $command->root = $root;

    app()->instance(LightspeedInstall::class, $command);

    Artisan::call('lightspeed:install');

    return Artisan::output();
}

test('the bootstrap channels line is printed on its own line, uncollapsed', function () {
    $root = pasteableInstallRoot();

    // config/broadcasting.php exists with a connections array, so the run
    // reaches the bootstrap wiring; bootstrap/app.php carries none of the
    // markers the wiring recognises, so the paste-this fallback must fire.
    file_put_contents($root.'/config/broadcasting.php', "<?php\nreturn ['connections' => [\n]];\n");
    file_put_contents($root.'/bootstrap/app.php', "<?php\nreturn 1;\n");
    file_put_contents($root.'/.env', "APP_NAME=demo\n");

    $output = runPasteableInstall($root);

    expect($output)->toMatch('/^\s*channels: __DIR__\.\'\/\.\.\/routes\/channels\.php\',\s*$/m');
});

test('the connection entry is printed on its own line, uncollapsed', function () {
    // Adversarial review: this third message was only asserted against a
    // whitespace-flattened string, which passes identically whether the line
    // survives the renderer or not.
    $root = pasteableInstallRoot();

    // A broadcasting config with no connections array: the add-by-hand
    // error path must fire, and the run must stop before .env.
    file_put_contents($root.'/config/broadcasting.php', "<?php\nreturn [];\n");
    file_put_contents($root.'/bootstrap/app.php', "<?php\nreturn 1;\n");
    file_put_contents($root.'/.env', "APP_NAME=demo\n");

    $output = runPasteableInstall($root);

    expect($output)->toMatch('/^\s*\'lightspeed\' => \[\'driver\' => \'lightspeed\'\],\s*$/m');
});

test('the four .env lines are printed one per line, uncollapsed', function () {
    $root = pasteableInstallRoot();

    // A connections array to pass the ordering gate, a wirable bootstrap, and
    // NO .env: the warn with the four variables to add by hand must fire.
    file_put_contents($root.'/config/broadcasting.php', "<?php\nreturn ['connections' => [\n]];\n");
    file_put_contents($root.'/bootstrap/app.php', "<?php\nreturn Application::configure()->withRouting(\n);\n");

    $output = runPasteableInstall($root);

    expect($output)->toMatch('/^\s*BROADCAST_CONNECTION=lightspeed\s*$/m')
        ->and($output)->toMatch('/^\s*LIGHTSPEED_APP_ID=.+$/m')
        ->and($output)->toMatch('/^\s*LIGHTSPEED_APP_KEY=.+$/m')
        ->and($output)->toMatch('/^\s*LIGHTSPEED_APP_SECRET=.+$/m');
});
