<?php

/**
 * The Laravel 11 .env fallback writer must produce the same file the real
 * Env::writeVariable produces, byte for byte.
 *
 * The fallback only runs on Laravel 11, where Env::writeVariable does not
 * exist, so an install there and an install on 12 must not disagree about the
 * user's .env. That is also why this file is the one part of the suite that
 * cannot run everywhere the suite runs: the oracle it compares against is the
 * method Laravel 11 does not have. On the Laravel 12 side of the CI matrix the
 * real method is available and every case runs both writers on the same
 * starting file and asserts identical bytes; on the 11 side the comparison is
 * SKIPPED rather than failed (see the skip at the bottom, which states the
 * reason). Mutation testing found the old
 * fallback diverging three ways (an existing empty value was kept where the
 * framework fills it, appends skipped the framework's blank-line separator,
 * and a CRLF file came back rewritten to LF wholesale); this test exists so
 * no divergence can come back quietly.
 */

use Illuminate\Support\Env;
use Lightspeed\Console\Commands\LightspeedInstall;

function envParityFallback(string $contents, string $key, string $value, bool $overwrite): string
{
    $method = new ReflectionMethod(LightspeedInstall::class, 'addVariableToEnvLines');
    $method->setAccessible(true);

    $command = (new ReflectionClass(LightspeedInstall::class))->newInstanceWithoutConstructor();
    $lines = $method->invoke($command, $key, $value, explode(PHP_EOL, $contents), $overwrite);

    return implode(PHP_EOL, $lines);
}

function envParityOracle(string $contents, string $key, string $value, bool $overwrite): string
{
    $path = tempnam(sys_get_temp_dir(), 'lightspeed-env-parity-');
    file_put_contents($path, $contents);

    try {
        Env::writeVariable($key, $value, $path, $overwrite);

        return (string) file_get_contents($path);
    } finally {
        @unlink($path);
    }
}

dataset('env writer cases', [
    'append with no prefix group' => ["APP_NAME=demo\n", 'LIGHTSPEED_APP_ID', 'hello', true],
    'append into a prefix group' => ["LIGHTSPEED_APP_ID=a\nAPP_NAME=demo\n", 'LIGHTSPEED_APP_KEY', 'k', true],
    'existing empty value, overwrite off' => ["LIGHTSPEED_APP_ID=\n", 'LIGHTSPEED_APP_ID', 'filled', false],
    'existing value, overwrite off' => ["LIGHTSPEED_APP_ID=keep\n", 'LIGHTSPEED_APP_ID', 'new', false],
    'existing value, overwrite on' => ["LIGHTSPEED_APP_ID=old\n", 'LIGHTSPEED_APP_ID', 'new', true],
    'exact line already present' => ["LIGHTSPEED_APP_ID=same\n", 'LIGHTSPEED_APP_ID', 'same', true],
    'value needing double quotes' => ["APP_NAME=demo\n", 'LIGHTSPEED_APP_SECRET', 'has space', true],
    'value containing double quotes' => ["APP_NAME=demo\n", 'LIGHTSPEED_APP_SECRET', 'has "quotes"', true],
    'value containing single quotes' => ["APP_NAME=demo\n", 'LIGHTSPEED_APP_SECRET', "it's", true],
    'value containing backslash' => ["APP_NAME=demo\n", 'LIGHTSPEED_APP_SECRET', 'back\\slash', true],
    'writing an empty value' => ["LIGHTSPEED_APP_KEY=old\n", 'LIGHTSPEED_APP_KEY', '', true],
    'no trailing newline' => ["APP_NAME=demo", 'LIGHTSPEED_APP_ID', 'x', true],
    'empty file' => ['', 'LIGHTSPEED_APP_ID', 'x', true],
    'crlf file stays what the framework makes of it' => ["APP_NAME=demo\r\nLIGHTSPEED_APP_ID=a\r\n", 'LIGHTSPEED_APP_KEY', 'k', true],
    'key that prefix-matches another key' => ["LIGHTSPEED_APP_KEY_OLD=x\n", 'LIGHTSPEED_APP_KEY', 'fresh', true],
]);

test('the fallback writes the same bytes as Env::writeVariable', function (string $contents, string $key, string $value, bool $overwrite) {
    expect(envParityFallback($contents, $key, $value, $overwrite))
        ->toBe(envParityOracle($contents, $key, $value, $overwrite));
})->with('env writer cases')
    // SKIPPED, not failed, on the Laravel 11 half of the CI matrix: the oracle
    // this whole file compares against is the method Laravel 11 does not have,
    // which is the entire reason the fallback exists. There is nothing to
    // compare the fallback to here, and a red test on 11 would say the fallback
    // is broken when what is absent is the yardstick. The comparison still runs
    // on 12, where the oracle is real, and that is where it has always caught
    // the divergences the docblock lists.
    ->skip(
        fn () => !method_exists(Env::class, 'writeVariable'),
        'Env::writeVariable is the oracle, and it does not exist on this framework version.',
    );
