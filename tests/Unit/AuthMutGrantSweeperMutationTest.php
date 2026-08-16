<?php

use Lightspeed\Auth\ConnectionGrants;
use Lightspeed\Auth\Grant;
use Lightspeed\Auth\GrantSweeper;
use Lightspeed\Auth\RevocationDrops;
use Lightspeed\Channels\Subscriptions;
use Lightspeed\Protocol\Delivery;
use Lightspeed\Workers\AttachedServer;
use Lightspeed\Logging\RuntimeLogger;
use Swoole\Timer;
use Swoole\WebSocket\Server as SwooleServer;

/**
 * The only thing that ever judges a grant nobody is using, and the budget it
 * shares with the revocation drain.
 *
 * Everything here runs inside a Swoole timer body with coroutines off, which is
 * what makes both halves of it load bearing: the tick may not throw, because an
 * escaping exception takes the worker's tick with it, and it may not spend
 * unboundedly, because every presence cleanup in it is a blocking round trip on
 * the event loop. What is left over waits for the next tick; a connection whose
 * grant expired is refused on every message it sends in the meantime either way.
 */

class AuthMutSweepSubscriptions extends Subscriptions
{
    /** @var list<array{0: int, 1: string}> */
    public array $dropped = [];

    public bool $explode = false;

    public function __construct(private readonly ConnectionGrants $grants)
    {
    }

    public function dropSubscription(int $fd, string $channel): void
    {
        if ($this->explode) {
            throw new \RuntimeException('presence store is unreachable');
        }

        $this->dropped[] = [$fd, $channel];
        $this->grants->forget($fd, $channel);
    }
}

class AuthMutSweepDelivery extends Delivery
{
    /** @var list<array{0: int, 1: string, 2: string}> */
    public array $stale = [];

    public bool $explode = false;

    public function __construct()
    {
    }

    public function pushStale(SwooleServer $server, int $fd, string $channel, string $reason): void
    {
        if ($this->explode) {
            throw new \RuntimeException('socket is gone');
        }

        $this->stale[] = [$fd, $channel, $reason];
    }
}

class AuthMutSweepLogger extends RuntimeLogger
{
    /** @var list<array{0: string, 1: array}> */
    public array $lines = [];

    public function __construct()
    {
    }

    public function logWebsocket(string $action, array $fields = []): void
    {
        $this->lines[] = [$action, $fields];
    }
}

/** A revocation drain that records the budget it was handed. */
class AuthMutSweepDrops extends RevocationDrops
{
    /** @var list<int> */
    public array $budgets = [];

    public int $spends = 0;

    public function __construct()
    {
    }

    public function drainPendingRevocations(int $budget): int
    {
        $this->budgets[] = $budget;

        return $this->spends;
    }
}

/** A sweeper whose expiry pass records the budget instead of running. */
class AuthMutSweepSpy extends GrantSweeper
{
    /** @var list<?int> */
    public array $expiryBudgets = [];

    public function sweepExpiredGrants(?int $budget = null): int
    {
        $this->expiryBudgets[] = $budget;

        return 0;
    }
}

class AuthMutSweepServer extends SwooleServer
{
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }
}

/**
 * @return array{0: GrantSweeper, 1: ConnectionGrants, 2: AuthMutSweepSubscriptions, 3: AuthMutSweepDelivery, 4: AuthMutSweepLogger}
 */
function authMutSweepHarness(bool $attach = true, string $class = GrantSweeper::class, ?AuthMutSweepDrops $drops = null): array
{
    $grants = new ConnectionGrants();
    $subscriptions = new AuthMutSweepSubscriptions($grants);
    $delivery = new AuthMutSweepDelivery();
    $logger = new AuthMutSweepLogger();
    $attached = new AttachedServer();

    if ($attach) {
        $attached->attach(AuthMutSweepServer::make());
    }

    $sweeper = new $class(
        $grants,
        $drops ?? new AuthMutSweepDrops(),
        $subscriptions,
        $attached,
        $delivery,
        $logger,
    );

    return [$sweeper, $grants, $subscriptions, $delivery, $logger];
}

function authMutExpiredGrant(): Grant
{
    $now = (int) (microtime(true) * 1_000_000);

    return new Grant(['project:7'], [], $now - 2_000, $now - 1_000);
}

test('a sweep unsubscribes an expired grant and tells the client to authorize again', function () {
    [$sweeper, $grants, $subscriptions, $delivery] = authMutSweepHarness();

    $grants->mint(11, 'private-a', authMutExpiredGrant());

    expect($sweeper->sweepExpiredGrants())->toBe(1)
        ->and($subscriptions->dropped)->toBe([[11, 'private-a']])
        ->and($delivery->stale)->toBe([[11, 'private-a', 'expired']]);
});

test('a sweep with no server attached does nothing rather than assuming one', function () {
    [$sweeper, $grants, $subscriptions] = authMutSweepHarness(attach: false);

    $grants->mint(11, 'private-a', authMutExpiredGrant());

    expect($sweeper->sweepExpiredGrants())->toBe(0)
        ->and($subscriptions->dropped)->toBe([]);
});

test('a sweep stops at its budget and leaves the rest to the next tick', function () {
    // The budget is counted per subscription unwound, starting at none spent:
    // one off in either direction is either an unbounded tick or a sweep that
    // never reaches the first connection on its list.
    config()->set('lightspeed.auth.sweep_max_per_tick', 200);

    [$sweeper, $grants, $subscriptions, , $logger] = authMutSweepHarness();

    foreach ([11, 12, 13] as $fd) {
        $grants->mint($fd, 'private-a', authMutExpiredGrant());
    }

    expect($sweeper->sweepExpiredGrants(1))->toBe(1)
        ->and($subscriptions->dropped)->toBe([[11, 'private-a']])
        ->and($logger->lines)->toBe([
            ['grant-sweep-deferred', ['remaining' => 2]],
        ]);

    // The rest are still expired, and the next tick finds them.
    expect($sweeper->sweepExpiredGrants(10))->toBe(2);
});

test('a sweep given no budget uses the configured one rather than sweeping everything', function () {
    config()->set('lightspeed.auth.sweep_max_per_tick', 2);

    [$sweeper, $grants, $subscriptions] = authMutSweepHarness();

    foreach ([11, 12, 13] as $fd) {
        $grants->mint($fd, 'private-a', authMutExpiredGrant());
    }

    expect($sweeper->sweepExpiredGrants())->toBe(2)
        ->and($subscriptions->dropped)->toHaveCount(2);
});

test('a cleanup that fails is named, and the client is still told', function () {
    // This is the failure that used to go unreported in both directions: the
    // connection left the fan-out and the client was never told to
    // re-authorize, so it did not reconnect and nothing at either end said so.
    [$sweeper, $grants, $subscriptions, $delivery, $logger] = authMutSweepHarness();

    $subscriptions->explode = true;
    $grants->mint(11, 'private-a', authMutExpiredGrant());

    expect($sweeper->sweepExpiredGrants())->toBe(0)
        ->and($delivery->stale)->toBe([[11, 'private-a', 'expired']])
        ->and($logger->lines)->toBe([
            ['error', [
                'fd' => 11,
                'channel' => 'private-a',
                'reason' => 'grant-sweep-failed',
                'message' => 'presence store is unreachable',
            ]],
        ]);
});

test('a client that cannot be told is named too, and the sweep goes on', function () {
    [$sweeper, $grants, $subscriptions, $delivery, $logger] = authMutSweepHarness();

    $delivery->explode = true;
    $grants->mint(11, 'private-a', authMutExpiredGrant());
    $grants->mint(12, 'private-b', authMutExpiredGrant());

    expect($sweeper->sweepExpiredGrants())->toBe(2)
        ->and($logger->lines)->toBe([
            ['error', [
                'fd' => 11,
                'channel' => 'private-a',
                'reason' => 'grant-sweep-notify-failed',
                'message' => 'socket is gone',
            ]],
            ['error', [
                'fd' => 12,
                'channel' => 'private-b',
                'reason' => 'grant-sweep-notify-failed',
                'message' => 'socket is gone',
            ]],
        ]);
});

test('one tick splits its budget, and each half inherits what the other left', function () {
    // Half each rather than the leftovers, because a revocation backlog at or
    // above the ceiling used to consume every tick whole and switch expiry off
    // for as long as it lasted, which is the backstop being disabled by the
    // thing it is the backstop for.
    config()->set('lightspeed.auth.sweep_max_per_tick', 10);

    $drops = new AuthMutSweepDrops();
    $drops->spends = 5;

    [$sweeper] = authMutSweepHarness(class: AuthMutSweepSpy::class, drops: $drops);

    $sweeper->runGrantSweep();

    expect($drops->budgets)->toBe([5])
        ->and($sweeper->expiryBudgets)->toBe([5]);

    // Nothing to revoke hands the whole tick to expiry.
    $drops->spends = 0;
    $sweeper->runGrantSweep();

    expect($drops->budgets)->toBe([5, 5])
        ->and($sweeper->expiryBudgets)->toBe([5, 10]);

    // And a drain that spent everything still leaves expiry a pass to make.
    $drops->spends = 10;
    $sweeper->runGrantSweep();

    expect($sweeper->expiryBudgets)->toBe([5, 10, 1]);
});

test('a budget of one gives both halves a pass rather than starving one', function () {
    // The degenerate minimum: no split can give both jobs a share, so the tick
    // spends two round trips rather than never revoking or never expiring.
    config()->set('lightspeed.auth.sweep_max_per_tick', 1);

    $drops = new AuthMutSweepDrops();
    $drops->spends = 1;

    [$sweeper] = authMutSweepHarness(class: AuthMutSweepSpy::class, drops: $drops);

    $sweeper->runGrantSweep();

    expect($drops->budgets)->toBe([1])
        ->and($sweeper->expiryBudgets)->toBe([1]);
});

test('the sweep ceiling is 200, floors at one, and is read as a number', function () {
    $auth = config('lightspeed.auth');
    unset($auth['sweep_max_per_tick']);
    config()->set('lightspeed.auth', $auth);

    expect(GrantSweeper::sweepBudget())->toBe(200);

    config()->set('lightspeed.auth.sweep_max_per_tick', 0);
    expect(GrantSweeper::sweepBudget())->toBe(1);

    config()->set('lightspeed.auth.sweep_max_per_tick', 1);
    expect(GrantSweeper::sweepBudget())->toBe(1);

    // Read as text, "abc" is greater than any number, which would hand the
    // tick a ceiling it can never reach.
    config()->set('lightspeed.auth.sweep_max_per_tick', 'abc');
    expect(GrantSweeper::sweepBudget())->toBe(1);
});

test('the sweeper is armed at the configured interval, and never twice', function () {
    // A worker restart runs the boot path again in the same process. A second
    // timer would sweep the same connections twice a tick forever, and the
    // first one is unreachable once its id is overwritten.
    [$sweeper, $grants, $subscriptions] = authMutSweepHarness(attach: false);

    config()->set('lightspeed.auth.sweep_interval_ms', 250);

    $sweeper->bootGrantSweeper(AuthMutSweepServer::make());
    $first = readLightspeedProperty($sweeper, 'grantSweepTimerId');

    expect(Timer::info($first)['interval'])->toBe(250);

    $sweeper->bootGrantSweeper(AuthMutSweepServer::make());
    $second = readLightspeedProperty($sweeper, 'grantSweepTimerId');

    expect($second)->not->toBe($first)
        ->and(Timer::exists($first))->toBeFalse()
        ->and(Timer::exists($second))->toBeTrue();

    // Booting is also what attaches the server the timer body will need; with
    // no server the sweep can only return zero.
    $grants->mint(11, 'private-a', authMutExpiredGrant());

    expect($sweeper->sweepExpiredGrants())->toBe(1)
        ->and($subscriptions->dropped)->toBe([[11, 'private-a']]);

    $sweeper->shutdownGrantSweeper();

    expect(Timer::exists($second))->toBeFalse();
});

test('the sweep interval is 1000ms, floors at 100, and is read as a number', function () {
    // The floor is not politeness: a sweep walks every subscription this
    // worker holds, and an interval of a millisecond would spend the tick on a
    // scan that can only find something once per grant lifetime.
    [$sweeper] = authMutSweepHarness();

    $intervals = [];

    foreach ([null, 50, 100, 'abc'] as $dial) {
        $auth = config('lightspeed.auth');

        if ($dial === null) {
            unset($auth['sweep_interval_ms']);
        } else {
            $auth['sweep_interval_ms'] = $dial;
        }

        config()->set('lightspeed.auth', $auth);

        $sweeper->bootGrantSweeper(AuthMutSweepServer::make());
        $intervals[] = Timer::info(readLightspeedProperty($sweeper, 'grantSweepTimerId'))['interval'];
    }

    $sweeper->shutdownGrantSweeper();

    expect($intervals)->toBe([1000, 100, 100, 100]);
});
