<?php

/**
 * Makes `hash_equals()` OBSERVABLE inside the package, so that a test can prove
 * a comparison is REACHED and DECIDES, rather than merely that a line of source
 * containing it still exists.
 *
 * Why this file exists at all. ConstantTimeComparisonTest used to assert that
 * each verifying file CONTAINS its `hash_equals(...)` expression. A reviewer left
 * every one of those lines exactly where it was, put a `==` above it that
 * decided the result first, and the whole 308-test suite passed, because a
 * loose comparison accepts and refuses exactly the same strings, and a
 * behavioural test cannot see the difference. Presence is not reachability.
 *
 * HOW IT WORKS. PHP resolves an unqualified function call by looking in the
 * CURRENT NAMESPACE first and only then falling back to the global one. Every
 * signature comparison in this package is an unqualified `hash_equals(...)`
 * inside a namespaced file, so defining `Lightspeed\Protocol\hash_equals()` (and
 * the same for the other two namespaces) puts a probe in front of the real
 * function without touching a byte of `src/`. The probe delegates to the genuine
 * global `\hash_equals()` and hands back its answer unchanged.
 *
 * Unchanged unless a test explicitly asks otherwise: invertedAt() flips the
 * answer of ONE call site, identified by file and line, for the duration of one
 * closure. If flipping a comparison's answer flips the verification's outcome,
 * that comparison is what decided it. If it does not, something else did. which
 * is precisely the state the reviewer left behind.
 *
 * WHY IT LOADS AT BOOTSTRAP RATHER THAN FROM THE TEST FILE. PHP caches the
 * resolution of an unqualified function call per call site. If any earlier test
 * reached one of these `hash_equals()` calls before this file was included, that
 * call site could stay bound to the global function for the rest of the process
 * and the probe would silently see nothing. Defining the shadows before a single
 * test runs removes the ordering question entirely; see tests/bootstrap.php.
 *
 * WHAT IT COSTS THE REST OF THE SUITE. Nothing observable. Outside an
 * invertedAt() closure the probe is a pure delegate, which the last test in
 * ConstantTimeComparisonTest asserts directly rather than assuming.
 */

namespace Lightspeed\Tests\Support {

    class ConstantTimeProbe
    {
        /**
         * The most recent call sites, newest last.
         *
         * Capped because this records EVERY hash_equals in three namespaces for
         * the whole suite, and an uncapped list would be one more thing growing
         * without bound across a run. Nothing here needs history: a test asserts
         * about the calls its own closure just made.
         *
         * @var list<array{file: string, line: int}>
         */
        private static array $calls = [];

        private const MAX_RECORDED_CALLS = 100;

        /** @var ?array{file: string, line: int} */
        private static ?array $invertAt = null;

        /** Answer one comparison, recording where it was asked from. */
        public static function decide(string $known, string $user): bool
        {
            // [0] is this method, called from the shadow function below; [1] is
            // the shadow function, whose file/line is the SOURCE call site.
            $frame = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

            $file = \str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            $line = (int) ($frame['line'] ?? 0);

            self::$calls[] = ['file' => $file, 'line' => $line];

            if (\count(self::$calls) > self::MAX_RECORDED_CALLS) {
                self::$calls = \array_slice(self::$calls, -self::MAX_RECORDED_CALLS);
            }

            $answer = \hash_equals($known, $user);

            if (self::$invertAt !== null
                && $line === self::$invertAt['line']
                && \str_ends_with($file, self::$invertAt['file'])) {
                return !$answer;
            }

            return $answer;
        }

        /** Forget every recorded call. Called at the start of an observation. */
        public static function forget(): void
        {
            self::$calls = [];
        }

        /** Was the comparison at this exact source position reached? */
        public static function reached(string $relativeFile, int $line): bool
        {
            foreach (self::$calls as $call) {
                if ($call['line'] === $line && \str_ends_with($call['file'], $relativeFile)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Run $work with ONE comparison answering the opposite of the truth.
         *
         * Scoped to a single call site so that a file with two comparisons in it
         * can have each proven separately, and released in a finally so a
         * throwing assertion cannot leave the probe lying to the rest of the
         * suite.
         */
        public static function invertedAt(string $relativeFile, int $line, callable $work): mixed
        {
            self::$invertAt = ['file' => $relativeFile, 'line' => $line];

            try {
                return $work();
            } finally {
                self::$invertAt = null;
            }
        }
    }
}

namespace Lightspeed\Protocol {
    function hash_equals(string $known_string, string $user_string): bool
    {
        return \Lightspeed\Tests\Support\ConstantTimeProbe::decide($known_string, $user_string);
    }
}

namespace Lightspeed\Relay {
    function hash_equals(string $known_string, string $user_string): bool
    {
        return \Lightspeed\Tests\Support\ConstantTimeProbe::decide($known_string, $user_string);
    }
}

namespace Lightspeed\Owner {
    function hash_equals(string $known_string, string $user_string): bool
    {
        return \Lightspeed\Tests\Support\ConstantTimeProbe::decide($known_string, $user_string);
    }
}
