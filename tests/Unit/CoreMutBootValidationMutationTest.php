<?php

use Lightspeed\Boot\BootValidation;

/**
 * The refusals that stop a misconfigured server before it listens, checked
 * against the object itself rather than through serve().
 *
 * tests/Unit/BootValidationTest.php drives these through `Server::serve()`,
 * which is the right shape for "does a bad configuration abort the command".
 * It leaves two things unwatched: the exact refusal text, which is the only
 * instruction an operator gets, and the shapes a config value arrives in when
 * it comes from an env var rather than from a test.
 */

function coreMutBootValidation(): BootValidation
{
    return new BootValidation();
}

/** The refusal message, or an empty string if the configuration was accepted. */
function coreMutRefusal(callable $check): string
{
    try {
        $check();
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
}

// ---------------------------------------------------------------------------
// Credentials.
// ---------------------------------------------------------------------------

test('a credential that is absent rather than empty is still a refusal', function () {
    // An unset env var reaches config as null, not as ''. Without the cast,
    // null is not identical to '' and the server starts with no secret at all,
    // which is the exact configuration the check exists for: every private and
    // presence channel signature becomes computable by anyone, and the relay's
    // signed control entries become forgeable.
    config()->set('lightspeed.reverb_compat.app_secret', null);

    expect(coreMutRefusal(fn () => coreMutBootValidation()->validateCredentials()))
        ->toContain('lightspeed.reverb_compat.app_secret');
});

test('a credentials block with no key or secret in it at all is a refusal', function () {
    // A published config file edited down, or a reverb_compat block copied from
    // somewhere that only carried the app id: the settings are not empty, they
    // are missing. The fallback the check reads them through has to be the
    // empty string for that to count, because anything else is a value and a
    // value passes.
    config()->set('lightspeed.reverb_compat', ['app_id' => 'test-app']);

    expect(coreMutRefusal(fn () => coreMutBootValidation()->validateCredentials()))
        ->toContain('lightspeed.reverb_compat.app_key');
});

test('the refusal says what is empty, which env vars set it, and what an empty one costs', function () {
    // This message is the whole of the operator's instructions: it is printed
    // once, at boot, by a command that then exits. Naming the setting without
    // naming the env var leaves them looking for it, and naming neither
    // consequence makes an empty secret look like a formality.
    config()->set('lightspeed.reverb_compat.app_secret', '');

    expect(coreMutRefusal(fn () => coreMutBootValidation()->validateCredentials()))->toBe(
        'Lightspeed cannot start: [lightspeed.reverb_compat.app_secret] is empty. '
        .'Set LIGHTSPEED_APP_SECRET (or REVERB_APP_SECRET). '
        .'Without it every private and presence channel signature can be computed by anyone, '
        .'and the relay control entries every worker obeys can be forged.'
    );
});

test('the same refusal, with the same shape, names the app key when that is what is missing', function () {
    // Two settings, one message template, and the env var pair changes with
    // the setting. A message that named the wrong env var would send an
    // operator to edit a variable that was already correct.
    config()->set('lightspeed.reverb_compat.app_key', '');

    expect(coreMutRefusal(fn () => coreMutBootValidation()->validateCredentials()))->toBe(
        'Lightspeed cannot start: [lightspeed.reverb_compat.app_key] is empty. '
        .'Set LIGHTSPEED_APP_KEY (or REVERB_APP_KEY). '
        .'Without it every private and presence channel signature can be computed by anyone, '
        .'and the relay control entries every worker obeys can be forged.'
    );
});

test('a configured application is accepted, so the refusals above are a judgement', function () {
    expect(coreMutRefusal(fn () => coreMutBootValidation()->validateCredentials()))->toBe('');
});

// ---------------------------------------------------------------------------
// The grant dials.
// ---------------------------------------------------------------------------

test('boot re-runs the grant ceiling check, where an operator is the one watching', function () {
    // The work is RevocationLog::validateConfiguration(), which also runs when
    // the grant machinery is resolved on any other tier. It is called AGAIN
    // here because a lifetime Redis will not accept otherwise fails every
    // tagged /broadcasting/auth request in production with nothing having been
    // said anywhere, rather than failing at the one moment somebody is reading
    // the output.
    config()->set('lightspeed.auth.grant_lifetime_seconds', \Lightspeed\Auth\RevocationLog::MAX_GRANT_LIFETIME_SECONDS + 1);

    expect(coreMutRefusal(fn () => coreMutBootValidation()->validateGrantLifetime()))
        ->toContain('lightspeed.auth.grant_lifetime_seconds');
});

// ---------------------------------------------------------------------------
// The handler lists.
// ---------------------------------------------------------------------------

test('the owner command handler list is checked too, not only the client event one', function () {
    // Handlers are resolved lazily by the dispatchers, so a typo in either list
    // stays invisible until the first client event or owner command arrives:
    // in production, on one unlucky caller. A check that covered only one of
    // the two lists leaves the other exactly as it was.
    config()->set('lightspeed.client_event_handlers', []);
    config()->set('lightspeed.owner_command_handlers', ['Lightspeed\\Tests\\CoreMutNoSuchOwnerHandler']);

    expect(coreMutRefusal(fn () => coreMutBootValidation()->validateHandlerConfiguration()))
        ->toContain('lightspeed.owner_command_handlers');
});

test('a handler list written as a bare class name is read as a list of one', function () {
    // `'lightspeed.client_event_handlers' => App\Handler::class` without the
    // brackets is the ordinary way this is mistyped, and it is a configuration
    // the package can still make sense of. Without the cast the loop is handed
    // a string, iterates nothing, and the typo it is there to catch goes
    // unchecked in exactly the case where the operator has already made one.
    config()->set('lightspeed.client_event_handlers', 'Lightspeed\\Tests\\CoreMutNoSuchClientHandler');

    expect(coreMutRefusal(fn () => coreMutBootValidation()->validateHandlerConfiguration()))
        ->toContain('does not exist');
});
