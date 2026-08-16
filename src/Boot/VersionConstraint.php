<?php

namespace Lightspeed\Boot;

use Composer\Semver\Semver;

/**
 * Reading a Composer version constraint the way Composer reads it.
 *
 * The doctor's PHP-version check compares the running PHP against the floor
 * this package advertises in its own composer.json, so the two cannot drift
 * apart. That means reading a constraint string, and a constraint string is
 * Composer's format, not PHP's: `version_compare()` alone gets `^8.2` and
 * `~8.2.1` wrong in both directions.
 *
 * composer/semver is present wherever Composer itself installed it and is used
 * when it is there. An application is not required to have it, though, so the
 * fallback below understands the constraint shapes this package's composer.json
 * uses. A fallback that disagrees with Composer would be worse than no check at
 * all, because it is the check that sends somebody off to upgrade PHP.
 * `tests/Unit/DoctorCommandTest.php` and
 * `tests/Unit/VersionConstraintComposerParityTest.php` pin it differentially
 * against composer/semver for the shapes it reads: single comparators, `||`
 * alternatives, AND ranges, wildcards, carets and tildes including below 1.0.
 * A shape outside those reads as satisfied on purpose (see clauseSatisfied),
 * never as a refusal.
 *
 * Owns: the constraint string, and where the package's own floor is read from.
 * Deliberately does not own: what a mismatch means to a user. The doctor turns
 * an unsatisfied constraint into the sentence an operator reads.
 */
class VersionConstraint
{
    /**
     * The PHP constraint this package advertises, or null when its
     * composer.json cannot be read (an unusual install layout, say). Null is a
     * distinct answer from "unsatisfied": the caller warns rather than fails.
     */
    public function packagePhp(): ?string
    {
        $path = dirname(__DIR__, 2).'/composer.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        if (! is_array($decoded)) {
            return null;
        }

        $constraint = $decoded['require']['php'] ?? null;

        return is_string($constraint) && $constraint !== '' ? $constraint : null;
    }

    /** Does this version satisfy this constraint, `||` alternatives included? */
    public function satisfies(string $version, string $constraint): bool
    {
        if (class_exists(Semver::class)) {
            try {
                return Semver::satisfies($version, $constraint);
            } catch (\Throwable) {
                // Fall through to the local reading rather than reporting a
                // problem with the user's PHP that is really a parse failure.
            }
        }

        return $this->fallbackSatisfies($version, $constraint);
    }

    /**
     * The local reading, for applications without composer/semver.
     *
     * `||` separates alternatives, any one of which may pass; within an
     * alternative, whitespace or commas separate comparators that must ALL
     * pass. Adversarial review found the AND half missing: `>=8.2 <9.0` matched its
     * first comparator and the rest of the string was discarded, so PHP
     * 9.0.0 satisfied a constraint that excludes it, in the permissive
     * direction, on the check that sends someone off to upgrade.
     */
    private function fallbackSatisfies(string $version, string $constraint): bool
    {
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            if ($alternative === '') {
                continue;
            }

            // Matched as tokens rather than split on whitespace, because an
            // operator may be separated from its version (`>= 8.2`), and a
            // split there would strand the operator.
            preg_match_all('/(?:\^|~|>=|<=|>|<|=)?\s*v?\d+(?:\.\d+)*(?:\.\*)?|\*/', $alternative, $matches);

            $comparators = array_values(array_filter($matches[0], fn (string $comparator): bool => trim($comparator) !== ''));

            if ($comparators === []) {
                // Unreadable rather than unsatisfied; see clauseSatisfied().
                return true;
            }

            $allSatisfied = true;

            foreach ($comparators as $comparator) {
                if (! $this->clauseSatisfied($version, $comparator)) {
                    $allSatisfied = false;

                    break;
                }
            }

            if ($allSatisfied) {
                return true;
            }
        }

        return false;
    }

    /**
     * One comparator clause, read the way Composer reads it.
     *
     * Composer pads a partial version to three components before comparing,
     * and treats a bare partial version as a range: `8.2` is `>=8.2.0 <8.3.0`,
     * not `>=8.2`. Getting that wrong in either direction is worse than not
     * checking, this is the check that tells somebody to go and upgrade PHP.
     */
    private function clauseSatisfied(string $version, string $clause): bool
    {
        $clause = trim($clause);

        // `8.2.*` is `>=8.2.0 <8.3.0`; a bare `*` matches anything. Adversarial review
        // found the wildcard falling through to the exact-match arm below,
        // where `8.2.*` read as `==8.2.0` and refused every 8.2.x above .0,
        // a false refusal from the class whose docblock calls that outcome
        // worse than no check at all.
        if ($clause === '*') {
            return true;
        }

        if (preg_match('/^v?(\d+(?:\.\d+)*)\.\*$/', $clause, $wildcard)) {
            $parts = array_map('intval', explode('.', $wildcard[1]));

            return version_compare($version, $this->padded($parts), '>=')
                && version_compare($version, $this->tildeCeiling([...$parts, 0]), '<');
        }

        if (! preg_match('/^(\^|~|>=|<=|>|<|=)?\s*v?(\d+(?:\.\d+)*)/', $clause, $matches)) {
            // Unreadable rather than unsatisfied. Inventing a PHP-version
            // failure out of a constraint this cannot parse would send someone
            // to upgrade a PHP that was fine.
            return true;
        }

        $operator = $matches[1] ?? '';
        $parts = array_map('intval', explode('.', $matches[2]));
        $floor = $this->padded($parts);

        return match ($operator) {
            '^' => version_compare($version, $floor, '>=')
                && version_compare($version, $this->caretCeiling($parts), '<'),
            '~' => version_compare($version, $floor, '>=')
                && version_compare($version, $this->tildeCeiling($parts), '<'),
            '>' => version_compare($version, $floor, '>'),
            '<' => version_compare($version, $floor, '<'),
            '<=' => version_compare($version, $floor, '<='),
            '>=' => version_compare($version, $floor, '>='),
            // A bare or `=` version is exact once padded: `8.2` is 8.2.0, and
            // 8.2.1 does not satisfy it. The range form is `8.2.*`, handled
            // above before the operator match.
            default => version_compare($version, $floor, '=='),
        };
    }

    /**
     * Pad up to three components, never truncate.
     *
     * Composer compares `>=8.2.1.4` against all four parts; slicing to three
     * read that floor as 8.2.1 and let a PHP of exactly 8.2.1 satisfy a
     * constraint it does not. Pinned differentially against composer/semver
     * in tests/Unit/VersionConstraintComposerParityTest.php.
     *
     * @param array<int, int> $parts
     */
    private function padded(array $parts): string
    {
        return implode('.', array_pad($parts, 3, 0));
    }

    /** `^8.2` is `<9.0.0`; below 1.0 the caret pins the first non-zero part instead. */
    private function caretCeiling(array $parts): string
    {
        // "First non-zero part" means exactly that: ^0.0.3 pins the PATCH
        // (<0.0.4), not the minor. Bumping the minor whenever the major was 0
        // read ^0.0.3 as <0.1.0 and disagreed with Composer.
        if (($parts[0] ?? 0) === 0 && ($parts[1] ?? 0) === 0 && count($parts) > 2) {
            return $this->padded([0, 0, $parts[2] + 1]);
        }

        if (($parts[0] ?? 0) === 0 && count($parts) > 1) {
            return $this->padded([0, $parts[1] + 1]);
        }

        return $this->padded([$parts[0] + 1]);
    }

    /** `~8.2` is `<9.0.0`, `~8.2.1` is `<8.3.0`: drop the last part, raise the new one. */
    private function tildeCeiling(array $parts): string
    {
        if (count($parts) < 2) {
            return $this->padded([$parts[0] + 1]);
        }

        return $this->bumpLast(array_slice($parts, 0, count($parts) - 1));
    }

    /** @param array<int, int> $parts */
    private function bumpLast(array $parts): string
    {
        $parts[count($parts) - 1]++;

        return $this->padded($parts);
    }
}
