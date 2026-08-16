<?php

/**
 * The suite's bootstrap.
 *
 * It was `vendor/autoload.php` directly, and this file exists for exactly one
 * reason: Support/ConstantTimeProbe.php has to be loaded before the first test
 * runs, because PHP caches how an unqualified function call resolves and a
 * shadow defined after a call site has already been reached is a shadow that
 * silently sees nothing. See that file for what it does and why.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/Support/ConstantTimeProbe.php';
