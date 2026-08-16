<?php

namespace Lightspeed\Owner;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Redis;

/**
 * The message-authentication scheme the owner-command bus speaks.
 *
 * This is the most consequential signature in the package. A drained command
 * entry becomes a call into the application's own OwnerCommandHandler with the
 * command string and payload array the entry carried, under the resource write
 * lease, so a forged one is arbitrary application write execution cleanly
 * serialized against every real mutation. Nothing secret is needed to address
 * it either: the stream is named after a process key that sits in plaintext in
 * `lightspeed:resource-owner:*` and is SCAN-able, so Redis write access alone
 * used to be enough.
 *
 * Three things together make one entry deliverable once, briefly, to one
 * process, and each is here because covering only the WORDS of a command leaves
 * every signed byte sequence reusable somewhere it was never meant to go:
 *
 *   the signature   over a domain prefix and the length-prefixed fields, with
 *                   the addressing (target process key, request id) inside the
 *                   signed bytes rather than only in the key that carries them.
 *   the freshness   window, so a captured entry stops being deliverable.
 *   the claim       on a request id, so within that window it is deliverable
 *                   exactly once.
 *
 * The window bounds how long and the claim bounds how many; neither is
 * sufficient alone, which is why claimRequestId()'s TTL has to strictly outlive
 * isFresh()'s interval.
 *
 * SAME BYTES ON THE WIRE, FOREVER. Both ends of this scheme are Lightspeed, so
 * a process talking to itself cannot notice that an encoding changed; only a
 * fleet mid-deploy notices, by refusing every command it is sent.
 * OwnerCommandSigningTest pins the prefixes and the encoding for that reason.
 *
 * Owns: the signing string, the prefixes, the freshness window and the spent-id
 * claim.
 * Deliberately does not own: what a valid command MEANS or what a refusal is
 * reported as. OwnerCommandBus decides both.
 */
class OwnerCommandSigner
{
    /**
     * Domain separators for the two signed payloads on this bus.
     *
     * They are inside the signature as well as the payload, exactly as
     * Relay\RedisRelay does it for control entries, so a captured command can
     * never be presented as a response or the other way round. They are
     * versioned so that changing either layout invalidates outstanding entries
     * instead of silently reinterpreting them; entries live for one poll
     * interval, so the cost of that is nil.
     *
     * NOTHING SEPARATES THE PREFIX FROM THE FIELDS, and that is safe for these
     * two values rather than in general. `signedFields()` begins every field
     * with `:`, and these two strings diverge at index 24 (`.v2` against
     * `-response`), so neither is a prefix of the other and no command signing
     * string can equal a response one. A third prefix that WAS a prefix of one
     * of these would break that in silence, so add one only alongside a
     * separator. And pin its on-wire bytes the way OwnerCommandSigningTest
     * pins these two, because both ends share the constant and a process
     * talking only to itself cannot notice that it changed.
     */
    public const COMMAND_SIGNING_PREFIX = 'lightspeed.owner-command.v2';

    public const RESPONSE_SIGNING_PREFIX = 'lightspeed.owner-command-response.v2';

    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * The signature over one signed payload.
     *
     * The prefix is a domain separator, so a captured command cannot be
     * replayed as a response or the other way about: the same reasoning as
     * Relay\RedisRelay's control signature, and the same app secret, which boot
     * already refuses to start without (BootValidation::validateCredentials).
     *
     * The stream key and the response key are still not signed, because they
     * cannot be: the signature has to verify against a name the reader knows,
     * and a Laravel connection prefix means the key the writer used and the key
     * the reader asked for are not always the same string. What they NAME is
     * signed instead (the target process key for a command, the request id for
     * a response), and that is the same binding without the fragility, because
     * each reader knows its own name for those (currentProcessKey() in the
     * drain, the id it is waiting on in waitForResponse).
     *
     * An earlier version of this docblock argued that binding the target would
     * make a signature unverifiable by the process that legitimately reads it
     * "under a different name". There is no different name: the drain reads the
     * stream of currentProcessKey() and nothing else.
     *
     * @param  array<int, string>  $fields
     */
    public function commandSignature(string $prefix, array $fields): string
    {
        return hash_hmac('sha256', $prefix.$this->signedFields($fields), $this->appSecret());
    }

    /**
     * @param  array<int, string>  $fields
     */
    public function hasValidSignature(string $prefix, array $fields, mixed $signature): bool
    {
        return is_string($signature)
            && hash_equals($this->commandSignature($prefix, $fields), $signature);
    }

    /**
     * Whether a signed instant is close enough to now to still be honoured.
     *
     * Symmetric, because the two ends are different machines and the one that
     * is ahead is as likely as the one that is behind; a command from a clock a
     * few seconds fast is a clock problem, not an attack, and failing it would
     * break routing for a reason the operator cannot see from here. The window
     * is generous relative to what it bounds: a command is drained a poll
     * interval (25ms) after it is written, and the uniqueness claim is what
     * actually stops a replay inside it.
     */
    public function isFresh(int $issuedAt): bool
    {
        return abs(time() - $issuedAt) <= $this->freshnessSeconds();
    }

    public function freshnessSeconds(): int
    {
        return max(1, (int) $this->config->get('lightspeed.owner_commands.freshness_seconds', 30));
    }

    /**
     * Spend a request id, or refuse because it is already spent.
     *
     * SET NX is the whole mechanism: the first drain to reach an id gets the
     * key and executes, and every later copy of the same signed bytes finds it
     * taken. The ids are already unique per send (a UUID minted in
     * forwardIfOwnedByAnotherProcess), so nothing new has to be generated;
     * they only needed to be spendable.
     *
     * The TTL is twice the freshness window PLUS ONE SECOND, and every part of
     * that is load-bearing.
     *
     * isFresh() accepts `abs(time() - issuedAt) <= window`, which is inclusive
     * at BOTH ends, so an entry issued at T is deliverable at every whole second
     * in the CLOSED interval [T - window, T + window]. The first copy of it can
     * therefore arrive at T - window and the last at T + window, and the gap
     * between those two instants is `2 * window` seconds.
     *
     * A TTL of exactly `2 * window` covers that gap up to, but not including,
     * its far end. A claim written at T - window expires at
     * `T - window + 2 * window` = T + window, which is precisely the last
     * second on which the entry is still accepted as fresh. Redis expiry and
     * the freshness check then disagree by one second, in the direction that
     * READMITS the entry: the claim is gone, the entry is fresh, and the same
     * signed mutation executes a second time. Replay protection is the two
     * mechanisms together, a window that bounds how long, and a claim that
     * bounds how many. So a second where only one of them holds is a second
     * with no replay protection at all.
     *
     * `+ 1` makes the claim outlive the last instant its entry can be accepted,
     * which is the whole property. Found by hand rather than by a test, which is
     * why the boundary is pinned by one now; see OwnerCommandBusTest.
     *
     * A Redis failure here refuses the command. That direction is deliberate:
     * the alternative is executing a mutation that may already have run,
     * because a bus that cannot tell does not know which.
     */
    public function claimRequestId(string $requestId): bool
    {
        try {
            return (bool) Redis::connection($this->redisConnection())->set(
                "lightspeed:owner-command-seen:{$requestId}",
                (string) time(),
                'EX',
                $this->spentIdTtlSeconds(),
                'NX',
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * How long a spent request id must stay spent.
     *
     * A method rather than an expression inside the SET, because the
     * relationship it has to hold, strictly longer than the widest interval
     * over which one signed entry can be accepted, is a thing a test has to be
     * able to ask about without writing a Redis key and reading its TTL back.
     * See claimRequestId() for the derivation and the off-by-one it fixes.
     */
    public function spentIdTtlSeconds(): int
    {
        return $this->freshnessSeconds() * 2 + 1;
    }

    /**
     * One signing string from several fields, injectively.
     *
     * `:{byte length}:{value}` per field, the encoding SubscriptionAuthorizer
     * uses for the same reason: the length tells a reader where each field ends
     * without hunting for a delimiter, so no value can spill into the next one
     * by containing whatever the delimiter is. The old bare "\0" join was safe
     * only because there was exactly one variable field and it was NUL-free
     * JSON. Both halves of that accident stop holding the moment a second
     * field is added, which is exactly what binding the addressing does.
     *
     * @param  array<int, string>  $fields
     */
    private function signedFields(array $fields): string
    {
        $signed = '';

        foreach ($fields as $field) {
            $signed .= ':'.strlen($field).':'.$field;
        }

        return $signed;
    }

    private function appSecret(): string
    {
        return (string) $this->config->get('lightspeed.reverb_compat.app_secret', '');
    }

    /**
     * Read here as well as on the bus, rather than handed over at construction,
     * so the claim lands on whatever connection the config names at the moment
     * it is spent, exactly as the bus's own reads do.
     */
    private function redisConnection(): string
    {
        return (string) $this->config->get('lightspeed.owner_commands.redis_connection', 'default');
    }
}
