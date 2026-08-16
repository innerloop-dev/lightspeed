<?php

/**
 * What a default install actually SEES, byte for byte.
 *
 * Logging\OperatorLog and Logging\RuntimeLogger both write with
 * `fwrite(STDOUT, ...)`, and nothing in the suite had ever read one of those
 * lines: every existing test that touches either one replaces `line()` with a
 * recording subclass, which is exactly the method whose body nobody was
 * checking. So the deduplication, the field rendering and the config gates were
 * all asserted through a seam that skipped the code doing the work.
 *
 * The seam this file uses instead is PHP's own: an unqualified `fwrite()` call
 * inside `namespace Lightspeed\Logging` resolves against that namespace first
 * and only then falls back to the global function. Declaring it here therefore
 * captures the real writes, from the real methods, with no subclass in the way.
 * It is declared ONCE, in this file, which is why the OperatorLog and
 * RuntimeLogger tests live together.
 */

namespace Lightspeed\Logging {
    /**
     * Capture what would have gone to stdout.
     *
     * Bounded, because it is in force for every test that runs after this file
     * is loaded and the suite writes a great many of these lines.
     *
     * Two invariants, stated because the shadow's safety rests on them:
     * this file must stay the ONLY declarer of Lightspeed\Logging\fwrite in
     * the process, and the return type mirrors the real fwrite (int|false)
     * so a test asserting write-failure handling is not silently handed a
     * success. The STDOUT branch always "succeeds" by design: what these
     * tests assert is the content of the writes, not stdout's health.
     */
    function fwrite($stream, string $data, ?int $length = null): int|false
    {
        if ($stream === \STDOUT) {
            $GLOBALS['coreMutStdout'][] = $data;

            if (\count($GLOBALS['coreMutStdout']) > 200) {
                \array_shift($GLOBALS['coreMutStdout']);
            }

            return \strlen($data);
        }

        return $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
    }
}

namespace {

    use Illuminate\Support\Facades\Log;
    use Lightspeed\Logging\OperatorLog;
    use Lightspeed\Logging\RuntimeLogger;
    use Lightspeed\Workers\WorkerContext;

    /** Start a fresh capture and hand back the lines written since. */
    function coreMutCapture(callable $body): array
    {
        $GLOBALS['coreMutStdout'] = [];

        $body();

        return $GLOBALS['coreMutStdout'];
    }

    /** A throwable whose class, file, line and message are all known to the test. */
    class CoreMutKnownFailure extends \RuntimeException
    {
        public static function at(string $file, int $line, string $message = 'redis went away'): self
        {
            $e = new self($message);

            foreach (['file' => $file, 'line' => $line] as $property => $value) {
                $handle = new ReflectionProperty(\Exception::class, $property);
                $handle->setAccessible(true);
                $handle->setValue($e, $value);
            }

            return $e;
        }
    }

    function coreMutLogger(): RuntimeLogger
    {
        return new RuntimeLogger(app('config'), new WorkerContext(app('config')));
    }

    // -----------------------------------------------------------------------
    // OperatorLog::line(), the one method every other one here writes through.
    // -----------------------------------------------------------------------

    test('an operator line is the message and a newline, in that order and nothing else', function () {
        // Every line this class emits goes through here, so a lost newline runs
        // two unrelated reports together and a lost message leaves a blank line
        // where the report was. Nothing else in the suite reads this write.
        $written = coreMutCapture(fn () => (new OperatorLog())->line('Lightspeed realtime listening on 0.0.0.0:8000'));

        expect($written)->toBe(["Lightspeed realtime listening on 0.0.0.0:8000".PHP_EOL]);
    });

    // -----------------------------------------------------------------------
    // reportCallbackFailure(): the signature is the dedup key AND the thing an
    // operator greps for, so both halves of it are behaviour.
    // -----------------------------------------------------------------------

    test('a callback failure names the surface, the class, the file and the line it came from', function () {
        // The signature is deliberately not the message: the message carries a
        // per-connection detail, so deduplicating on it would print one line
        // per connection for a single cause. Which four parts it is made of,
        // and in which order, is what makes it the same string for every
        // connection failing at one throw site.
        $log = new OperatorLog();
        $failure = CoreMutKnownFailure::at('/app/src/Protocol/Teardown.php', 412);

        $written = coreMutCapture(fn () => $log->reportCallbackFailure('close', $failure, ['fd' => 7]));

        expect($written)->toBe([
            'Lightspeed close callback failed: redis went away '
            .'(close:'.CoreMutKnownFailure::class.':Teardown.php:412) [fd=7]'.PHP_EOL,
        ]);
    });

    test('a failure with no fields carries no field bracket at all', function () {
        // The empty branch of the suffix ternary. An operator reading these
        // greps for the signature in parentheses; a stray bracket after it, or
        // a suffix on the wrong side of the line, is noise in the one place the
        // report is supposed to be scannable.
        $log = new OperatorLog();
        $failure = CoreMutKnownFailure::at('/app/src/Relay/RedisRelay.php', 88);

        $written = coreMutCapture(fn () => $log->reportCallbackFailure('workerStart', $failure));

        expect($written)->toBe([
            'Lightspeed workerStart callback failed: redis went away '
            .'(workerStart:'.CoreMutKnownFailure::class.':RedisRelay.php:88)'.PHP_EOL,
        ]);
    });

    test('a non-scalar field is reported as its type rather than crashing the reporter', function () {
        // NOTHING HERE MAY THROW: this is the last frame before a Swoole
        // callback boundary. A field holding an array or an object is exactly
        // what a caller reaching for context passes, and casting one to string
        // is fatal.
        $log = new OperatorLog();
        $failure = CoreMutKnownFailure::at('/app/src/Server.php', 1);

        $written = coreMutCapture(fn () => $log->reportCallbackFailure('message', $failure, [
            'fd' => 12,
            'payload' => ['a' => 1],
        ]));

        expect($written[0])->toContain('[fd=12 payload=array]');
    });

    test('one outage failing every connection at the same line is reported once', function () {
        // The whole reason this class exists. A Redis outage fails every
        // connection's close at the same throw site, and a reconnecting client
        // pool retries hard: a line per connection buries the thing it is
        // reporting.
        $log = new OperatorLog();

        $written = coreMutCapture(function () use ($log) {
            foreach (range(1, 5) as $fd) {
                $log->reportCallbackFailure('close', CoreMutKnownFailure::at('/app/src/Protocol/Teardown.php', 412), ['fd' => $fd]);
            }
        });

        expect($written)->toHaveCount(1);
    });

    test('a second distinct throw site is still reported, so dedup does not swallow a new cause', function () {
        // The positive control for the dedup above, and the reason the
        // signature is four parts rather than one: two different failures must
        // not collapse into each other.
        $log = new OperatorLog();

        $written = coreMutCapture(function () use ($log) {
            $log->reportCallbackFailure('close', CoreMutKnownFailure::at('/app/src/Protocol/Teardown.php', 412), []);
            $log->reportCallbackFailure('close', CoreMutKnownFailure::at('/app/src/Protocol/Teardown.php', 999), []);
            $log->reportCallbackFailure('open', CoreMutKnownFailure::at('/app/src/Protocol/Teardown.php', 412), []);
            $log->reportCallbackFailure('close', CoreMutKnownFailure::at('/app/src/Relay/RedisRelay.php', 412), []);
        });

        expect($written)->toHaveCount(4);
    });

    // -----------------------------------------------------------------------
    // reportHandshakeFailure(): the same discipline on the path a client meets.
    // -----------------------------------------------------------------------

    test('a refused handshake names the path and the throw site it failed at', function () {
        // A dependency outage fails the handshake for every connection attempt
        // and a reconnecting client pool retries hard, so the signature is what
        // decides both what an operator can grep for and what gets said once.
        $log = new OperatorLog();
        $failure = CoreMutKnownFailure::at('/app/src/Protocol/Handshake.php', 204);

        $written = coreMutCapture(fn () => $log->reportHandshakeFailure($failure, '/app/test-key'));

        expect($written)->toBe([
            'Lightspeed handshake failed on /app/test-key: redis went away '
            .'('.CoreMutKnownFailure::class.':Handshake.php:204)'.PHP_EOL,
        ]);
    });

    test('one handshake outage is reported once, and the next distinct one is reported again', function () {
        $log = new OperatorLog();

        $written = coreMutCapture(function () use ($log) {
            $log->reportHandshakeFailure(CoreMutKnownFailure::at('/app/src/Protocol/Handshake.php', 204), '/app/k');
            $log->reportHandshakeFailure(CoreMutKnownFailure::at('/app/src/Protocol/Handshake.php', 204), '/app/k');
            $log->reportHandshakeFailure(CoreMutKnownFailure::at('/app/src/Protocol/Handshake.php', 999), '/app/k');
            $log->reportHandshakeFailure(CoreMutKnownFailure::at('/app/src/Relay/RedisRelay.php', 204), '/app/k');
        });

        expect($written)->toHaveCount(3);
    });

    test('a handshake that completes clears the report, so the next outage is seen', function () {
        // The dedup is per outage, not for the lifetime of the worker. Without
        // the clear, an outage that recovered and returned would be silent.
        $log = new OperatorLog();

        $written = coreMutCapture(function () use ($log) {
            $log->reportHandshakeFailure(CoreMutKnownFailure::at('/app/src/Protocol/Handshake.php', 204), '/app/k');
            $log->handshakeSucceeded();
            $log->reportHandshakeFailure(CoreMutKnownFailure::at('/app/src/Protocol/Handshake.php', 204), '/app/k');
        });

        expect($written)->toHaveCount(2);
    });

    // -----------------------------------------------------------------------
    // reportGrantDropped(): a channel that has quietly stopped being re-checked.
    // -----------------------------------------------------------------------

    test('a dropped grant warning names the channel, its prefix, and what went missing', function () {
        // The transition is invisible from every other angle: the subscribe
        // still succeeds, no counter moves, and the only evidence is a tag()
        // call that is no longer in the deploy. The prefix is what makes the
        // throttle bound by channel PATTERNS rather than by open documents, so
        // an operator reading this needs to see which pattern it stands for,
        // and the note is the only place the cause is stated.
        Log::spy();

        (new OperatorLog())->reportGrantDropped('private-doc.4718');

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return $context['channel'] === 'private-doc.4718'
                && $context['channel_prefix'] === 'private-doc'
                && str_contains($context['note'] ?? '', 'tag()');
        })->once();
    });

    // -----------------------------------------------------------------------
    // RuntimeLogger: an opt-in channel that must stay off unless asked for.
    // -----------------------------------------------------------------------

    test('websocket logging is off on a configuration nobody edited', function () {
        // The default is the contract: this channel is opt-in precisely so that
        // Logging\OperatorLog can be the thing a default install sees. A
        // default that flipped to on would put every frame on stdout of every
        // production server.
        config()->set('lightspeed.logging', []);

        $written = coreMutCapture(fn () => coreMutLogger()->logWebsocket('subscribe', ['channel' => 'private-doc.1']));

        expect($written)->toBe([]);
    });

    test('websocket logging emits once it is turned on', function () {
        // The positive control. Without it a logger that emitted nothing ever
        // would satisfy the assertion above.
        config()->set('lightspeed.logging.websocket', true);

        $written = coreMutCapture(fn () => coreMutLogger()->logWebsocket('subscribe', ['channel' => 'private-doc.1']));

        expect($written)->toHaveCount(1)
            ->and($written[0])->toContain('action="subscribe"')
            ->and($written[0])->toContain('channel="private-doc.1"');
    });

    test('a websocket log value that is not a boolean does not stop the line being written', function () {
        // The gate reads a config value that arrives from an env var, so it is
        // a string as often as it is a bool, and it must not be the thing that
        // decides whether the runtime can log at all.
        config()->set('lightspeed.logging.websocket', []);

        $written = coreMutCapture(fn () => coreMutLogger()->logWebsocket('subscribe'));

        expect($written)->toBe([]);
    });

    test('synthetic HTTP logging is off by default and on when asked for', function () {
        config()->set('lightspeed.logging', []);

        $silent = coreMutCapture(fn () => coreMutLogger()->logSyntheticHttp('get', '/healthz', 200));

        config()->set('lightspeed.logging.http', true);

        $loud = coreMutCapture(fn () => coreMutLogger()->logSyntheticHttp('get', '/healthz', 200));

        expect($silent)->toBe([])
            ->and($loud)->toHaveCount(1)
            ->and($loud[0])->toContain('method="GET"')
            ->and($loud[0])->toContain('path="/healthz"');
    });

    test('an http logging value that is not a boolean does not become a type error', function () {
        config()->set('lightspeed.logging.http', []);

        $written = coreMutCapture(fn () => coreMutLogger()->logSyntheticHttp('get', '/healthz', 200));

        expect($written)->toBe([]);
    });

    test('payload snippets stay out of the log until payload logging is turned on', function () {
        // Payloads are application data. Whether they reach stdout is the one
        // decision here that is about disclosure rather than volume, so an
        // accidental default of on writes user messages into every log
        // aggregator the operator ships to.
        config()->set('lightspeed.logging', []);

        expect(coreMutLogger()->maybePayloadSnippet(['message' => 'hello']))->toBeNull();

        config()->set('lightspeed.logging.payloads', true);

        expect(coreMutLogger()->maybePayloadSnippet(['message' => 'hello']))
            ->toBe('{"message":"hello"}');
    });

    test('a payloads flag that is not a boolean does not become a type error', function () {
        config()->set('lightspeed.logging.payloads', []);

        expect(coreMutLogger()->maybePayloadSnippet('hello'))->toBeNull();
    });

    // -----------------------------------------------------------------------
    // The snippet itself: how much of a payload reaches the log, and in what
    // shape.
    // -----------------------------------------------------------------------

    test('a snippet is one line, with slashes and accents left as themselves', function () {
        // These lines are read by a human at a terminal and grepped by a log
        // aggregator, and both want one event per line. Escaped slashes and
        // \u sequences make a payload unrecognisable next to the message the
        // application actually sent.
        config()->set('lightspeed.logging.payloads', true);

        expect(coreMutLogger()->maybePayloadSnippet([
            'url' => 'https://example.test/a/b',
            'name' => "caf\u{e9}",
        ]))->toBe('{"url":"https://example.test/a/b","name":"café"}');
    });

    test('whitespace inside a payload is collapsed, so one message stays one line', function () {
        config()->set('lightspeed.logging.payloads', true);

        expect(coreMutLogger()->maybePayloadSnippet("first line\n\t  second line"))
            ->toBe('first line second line');
    });

    test('a payload that encodes to nothing is no snippet at all', function () {
        // An empty string is not a snippet, it is a `payload=` with nothing
        // after it, and the emit loop would then drop the field anyway. Making
        // that decision here keeps the two ends agreeing.
        config()->set('lightspeed.logging.payloads', true);

        expect(coreMutLogger()->maybePayloadSnippet(''))->toBeNull()
            ->and(coreMutLogger()->maybePayloadSnippet(null))->toBeNull();
    });

    test('a snippet is truncated at the configured width, floored at eighty characters', function () {
        // The dial exists so a large frame does not put a kilobyte on every log
        // line, and the floor exists because a limit small enough to cut the
        // payload down to nothing turns the feature into noise with no content.
        config()->set('lightspeed.logging.payloads', true);
        config()->set('lightspeed.logging.payload_limit', 10);

        expect(coreMutLogger()->maybePayloadSnippet(str_repeat('x', 200)))
            ->toBe(str_repeat('x', 77).'...');

        config()->set('lightspeed.logging.payload_limit', 100);

        expect(coreMutLogger()->maybePayloadSnippet(str_repeat('x', 200)))
            ->toBe(str_repeat('x', 97).'...');
    });

    test('the shipped payload width is six hundred characters', function () {
        config()->set('lightspeed.logging', ['payloads' => true]);

        expect(coreMutLogger()->maybePayloadSnippet(str_repeat('x', 900)))
            ->toBe(str_repeat('x', 597).'...');
    });

    test('a payload width that arrives as an unusable string falls back to the floor', function () {
        config()->set('lightspeed.logging.payloads', true);
        config()->set('lightspeed.logging.payload_limit', 'six hundred');

        expect(coreMutLogger()->maybePayloadSnippet(str_repeat('x', 200)))
            ->toBe(str_repeat('x', 77).'...');
    });

    // -----------------------------------------------------------------------
    // One emitted line, field by field.
    // -----------------------------------------------------------------------

    test('a synthetic HTTP line carries the request, its outcome and where it came from', function () {
        // These are the fields an access log is FOR. The request id is what
        // correlates this line with the application's own; the status and the
        // duration are the outcome; and the host and the client address are how
        // one bad caller is told apart from a general failure.
        config()->set('lightspeed.logging.http', true);

        $written = coreMutCapture(fn () => coreMutLogger()->logSyntheticHttp(
            method: 'post',
            path: '/apps/1/events',
            status: 403,
            host: 'realtime.example.test',
            remoteAddr: '203.0.113.9',
            startedAt: microtime(true) - 0.05,
            requestId: 'req-7',
            extra: ['reason' => 'bad-signature'],
        ));

        expect($written[0])->toContain('req="req-7"')
            ->and($written[0])->toContain('status=403')
            ->and($written[0])->toContain('dur_ms=')
            ->and($written[0])->toContain('host="realtime.example.test"')
            ->and($written[0])->toContain('ip="203.0.113.9"')
            // The caller's own fields, which is the whole reason logSyntheticHttp
            // takes any: without them a refusal says only that one happened.
            ->and($written[0])->toContain('reason="bad-signature"');
    });

    test('an emitted line opens with a timestamp and says which channel it is', function () {
        // Two log streams share this stdout, and they are read differently: an
        // access log line and a websocket lifecycle line answer different
        // questions. Without the channel tag they interleave into one
        // unsplittable stream, and without the timestamp none of it can be
        // lined up against anything else.
        config()->set('lightspeed.logging.websocket', true);

        $written = coreMutCapture(fn () => coreMutLogger()->logWebsocket('close'));

        expect($written[0])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3} \[lightspeed\]\[ws\] /')
            ->and($written[0])->toEndWith(PHP_EOL)
            ->and(substr_count($written[0], PHP_EOL))->toBe(1);
    });

    test('a line from inside a worker names the worker, and one from outside does not', function () {
        // Worker zero is a real worker, so the presence of the field cannot be
        // decided on truthiness. Outside a worker there is no worker to name,
        // and printing `worker=` with nothing after it is worse than the pid
        // that is already there.
        config()->set('lightspeed.logging.websocket', true);
        config()->set('lightspeed.server.instance_id', 'core-mut-instance');

        $outside = coreMutCapture(fn () => coreMutLogger()->logWebsocket('open'));

        $context = new WorkerContext(app('config'));
        $context->boot((new ReflectionClass(\Swoole\WebSocket\Server::class))->newInstanceWithoutConstructor(), 0);
        $inside = coreMutCapture(fn () => (new RuntimeLogger(app('config'), $context))->logWebsocket('open'));

        expect($outside[0])->not->toContain('worker=')
            ->and($outside[0])->toContain('pid='.getmypid())
            ->and($inside[0])->toContain('worker=0');
    });

    test('a field with nothing in it is left out rather than written as empty', function () {
        // Most of these fields are optional on most paths, and a line that
        // carries `route= req= dur_ms=` for every request is a line whose real
        // content is buried in placeholders.
        config()->set('lightspeed.logging.websocket', true);

        $written = coreMutCapture(fn () => coreMutLogger()->logWebsocket('subscribe', [
            'channel' => 'room',
            'socket' => null,
            'reason' => '',
        ]));

        expect($written[0])->toContain('channel="room"')
            ->and($written[0])->not->toContain('socket')
            ->and($written[0])->not->toContain('reason');
    });

    test('field values keep their types legible: numbers bare, booleans as one and zero, arrays as JSON', function () {
        // The rendering is what a reader parses. A number wrapped in quotes and
        // a string left bare are the same ambiguity in opposite directions, and
        // an array that came out as its type name says nothing about what was
        // in it.
        config()->set('lightspeed.logging.websocket', true);

        $written = coreMutCapture(fn () => coreMutLogger()->logWebsocket('fan-out', [
            'count' => 3,
            'seconds' => 1.5,
            'excluded' => true,
            'skipped' => false,
            'channels' => ['room', 'private-doc.1'],
            'handler' => new \stdClass(),
        ]));

        expect($written[0])->toContain('count=3')
            ->and($written[0])->toContain('seconds=1.5')
            ->and($written[0])->toContain('excluded=1')
            ->and($written[0])->toContain('channels=["room","private-doc.1"]')
            ->and($written[0])->toContain('handler="[stdClass]"')
            // false is not written at all: the emit loop keeps null and the
            // empty string out, and `0` is a value worth seeing.
            ->and($written[0])->toContain('skipped=0');
    });

    test('an array field is rendered as JSON with slashes and accents intact', function () {
        // The same reasoning as the payload snippet, applied to the structured
        // fields: a channel name or a path that comes back escaped is not the
        // string an operator would grep for.
        config()->set('lightspeed.logging.websocket', true);

        $written = coreMutCapture(fn () => coreMutLogger()->logWebsocket('fan-out', [
            'targets' => ['https://a.test/b', "caf\u{e9}"],
        ]));

        expect($written[0])->toContain('targets=["https://a.test/b","café"]');
    });

    test('a duration is milliseconds, to two decimal places, with nothing separating the thousands', function () {
        // It is a NUMBER a log tool will want to sort and threshold on, so the
        // unit, the precision and the absence of a thousands separator are all
        // part of it: a grouped "10,000.00" does not parse as a float, and a
        // duration in seconds silently reads as three orders of magnitude
        // faster than the request really was.
        config()->set('lightspeed.logging.http', true);

        $written = coreMutCapture(fn () => coreMutLogger()->logSyntheticHttp(
            method: 'get',
            path: '/healthz',
            status: 200,
            startedAt: microtime(true) - 10.0,
        ));

        preg_match('/dur_ms="([^"]+)"/', $written[0], $matches);

        expect($matches[1] ?? '')->toMatch('/^\d+\.\d{2}$/')
            ->and((float) $matches[1])->toBeGreaterThanOrEqual(10000.0)
            ->and((float) $matches[1])->toBeLessThan(10005.0);
    });
}
