<?php

namespace Lightspeed\Boot;

use Lightspeed\Auth\RevocationLog;
use Lightspeed\Contracts\ClientEventHandler;
use Lightspeed\Contracts\ConnectionClosedHandler;
use Lightspeed\Contracts\OwnerCommandHandler;

/**
 * The refusals that stop a misconfigured server before it listens.
 *
 * Every check here runs from `Server::serve()` BEFORE the listener exists, so
 * a misconfigured server aborts the command with one clear message rather than
 * throwing in every worker and leaving Swoole to restart them in a loop. Boot
 * is the one moment an operator is looking at the output, which is why it is
 * where a configuration that cannot work is allowed to be fatal.
 *
 * Nothing here resolves an application service or touches Redis: a check that
 * needed the world to be up could not run at the moment it is needed.
 */
class BootValidation
{
    /**
     * Refuse to start without the credentials that are the whole of channel
     * security on this surface.
     *
     * The app secret is not a nicety. An auth string is
     * `key:hash_hmac('sha256', "{socketId}:{channel}", $secret)`, so with the
     * secret empty anybody can compute one for any channel and land in a
     * private channel's fan-out, and the same empty key makes the relay's
     * signed control entries forgeable by anyone who can write to Redis, which
     * is exactly the property 221f2d6 added them for.
     *
     * It WAS validated, but only where the broadcaster is resolved, which is
     * the first `/broadcasting/auth` request. `Protocol\PusherApp::secret()`
     * and `Relay\RedisRelay::appSecret()` both defaulted to `''` with no check,
     * and this method used to validate handler class names and nothing else. A
     * server whose secret env var was missing, misspelled, or emptied therefore
     * started, listened, logged nothing, and accepted forged subscriptions
     * until somebody happened to authorize a channel.
     *
     * Boot is where an operator is watching, so this is where it fails. It runs
     * before the listener exists, so a misconfigured server aborts the command
     * rather than throwing in every worker and leaving Swoole to restart them
     * in a loop.
     */
    public function validateCredentials(): void
    {
        $required = [
            'app_key' => 'LIGHTSPEED_APP_KEY (or REVERB_APP_KEY)',
            'app_secret' => 'LIGHTSPEED_APP_SECRET (or REVERB_APP_SECRET)',
        ];

        foreach ($required as $setting => $envVars) {
            if ((string) config("lightspeed.reverb_compat.{$setting}", '') === '') {
                throw new \RuntimeException(
                    "Lightspeed cannot start: [lightspeed.reverb_compat.{$setting}] is empty. "
                    ."Set {$envVars}. "
                    .'Without it every private and presence channel signature can be computed by anyone, '
                    .'and the relay control entries every worker obeys can be forged.'
                );
            }
        }
    }

    /**
     * Check the grant dials where an operator is watching.
     *
     * The work is RevocationLog::validateConfiguration(), which is also run
     * when the grant machinery is resolved on any other tier. It is called
     * again here because boot is the one moment an operator is looking at the
     * output, and because serve() runs it before the listener exists, so a
     * misconfigured server aborts the command rather than throwing in every
     * worker and leaving Swoole to restart them in a loop.
     *
     * WHAT THIS NO LONGER CHECKS, AND WHY. It used to also refuse to start when
     * `revocation_retention_floor_seconds` was below `grant_lifetime_seconds * 2`.
     * That check was aimed at something that cannot happen. REVOKE_SCRIPT
     * computes `ttl = max(max(ARGV[1], mark) * 2, floor)` and ARGV[1] is the
     * revoking process's OWN dial, so a process's own grants are covered by
     * `own dial * 2` whatever the floor says; the floor is only ever consulted
     * when it is LARGER than that. `floor >= own dial * 2` is therefore exactly
     * the condition under which the floor is dead code.
     *
     * Measured against real Redis, with the Lua verbatim and the mark deleted:
     *
     *   own dial 7200, floor 3600 -> revocation TTL 14400   (it claimed 3600)
     *   own dial  300, floor 3600 -> revocation TTL  3600
     *
     * The second line is the configuration the check PASSED, and is the holed
     * one: a peer still minting 7200s grants keeps them a full hour past the
     * revocation. The first is the configuration it REFUSED, and was never in
     * danger from its own grants. It was also a live availability regression,
     * because the shipped floor is 3600 and so any lifetime above 1800 could
     * not start.
     *
     * The invariant that is actually true is about the LONGEST lifetime minted
     * anywhere in the fleet, and one process cannot see that at boot. It is
     * stated as a residual in docs/authorization.md, and revoke() logs the pass
     * where it goes live: the one that found no high-water mark to read.
     */
    public function validateGrantLifetime(): void
    {
        RevocationLog::validateConfiguration(app('config'));
    }

    /**
     * Fail fast on a handler class list that cannot possibly work.
     *
     * Handlers are resolved lazily by the dispatchers, so without this check a
     * typo in the configured class list stays invisible until the first client
     * event or owner command arrives: in production, on one unlucky client.
     * The check stays deliberately cheap: class and contract only, never a
     * container resolution, so booting the server does not construct
     * application services.
     */
    public function validateHandlerConfiguration(): void
    {
        $contracts = [
            'lightspeed.client_event_handlers' => ClientEventHandler::class,
            'lightspeed.connection_closed_handlers' => ConnectionClosedHandler::class,
            'lightspeed.owner_command_handlers' => OwnerCommandHandler::class,
        ];

        foreach ($contracts as $configKey => $contract) {
            foreach ((array) config($configKey, []) as $handlerClass) {
                if (!is_string($handlerClass) || $handlerClass === '') {
                    throw new \RuntimeException("Lightspeed config [{$configKey}] must contain handler class names.");
                }

                if (!class_exists($handlerClass)) {
                    throw new \RuntimeException("Lightspeed handler [{$handlerClass}] configured in [{$configKey}] does not exist.");
                }

                if (!is_subclass_of($handlerClass, $contract)) {
                    throw new \RuntimeException("Lightspeed handler [{$handlerClass}] configured in [{$configKey}] must implement [{$contract}].");
                }
            }
        }
    }
}
