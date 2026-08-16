<?php

/**
 * The fallback constraint reader may never disagree with Composer.
 *
 * Its own docblock states the stake: a fallback that disagrees with Composer
 * is worse than no check at all, because this is the check that sends someone
 * off to upgrade PHP. Mutation testing found two disagreements, both
 * pinned here differentially against composer/semver itself, so the assertion
 * is Composer's answer, not a hand-copied expectation.
 */

use Composer\Semver\Semver;
use Lightspeed\Boot\VersionConstraint;

function composerParityClause(string $version, string $clause): bool
{
    $method = new ReflectionMethod(VersionConstraint::class, 'clauseSatisfied');
    $method->setAccessible(true);

    return $method->invoke(new VersionConstraint(), $version, $clause);
}

test('a four-part floor is not truncated to three parts', function () {
    // padded() used array_slice(..., 0, 3): the floor of >=8.2.1.4 was read
    // as 8.2.1, so a PHP of exactly 8.2.1 satisfied a constraint it does not.
    foreach ([['8.2.1', '>=8.2.1.4'], ['8.2.1.3', '>=8.2.1.4'], ['8.2.1.4', '>=8.2.1.4'], ['8.2.2', '>=8.2.1.4']] as [$version, $clause]) {
        expect(composerParityClause($version, $clause))
            ->toBe(Semver::satisfies($version, $clause), "{$version} vs {$clause}");
    }
});

test('an AND range refuses above its ceiling, instead of reading only the floor', function () {
    // Adversarial review: the fallback split on || only, so `>=8.2 <9.0` matched its
    // first comparator and PHP 9.0.0 satisfied a constraint that excludes it.
    // The permissive direction, on the check that sends people to upgrade.
    foreach ([
        ['9.0.0', '>=8.2 <9.0'], ['8.9.9', '>=8.2 <9.0'], ['8.1.0', '>=8.2 <9.0'],
        ['9.0.0', '>=8.2,<9.0'], ['8.5.0', '>=8.2,<9.0'],
        ['9.0.0', '>=8.2 , <9.0'],
        ['8.4.1', '>=8.2.0 <8.4.0'], ['8.3.9', '>=8.2.0 <8.4.0'],
        ['8.4.1', '^7.4 || >=8.2 <8.4'], ['8.3.0', '^7.4 || >=8.2 <8.4'], ['7.4.33', '^7.4 || >=8.2 <8.4'],
    ] as [$version, $clause]) {
        // satisfies() prefers composer/semver when installed, which it is
        // here; the fallback seam is what a plain Laravel app runs.
        $method = new ReflectionMethod(VersionConstraint::class, 'fallbackSatisfies');
        $method->setAccessible(true);

        expect($method->invoke(new VersionConstraint(), $version, $clause))
            ->toBe(Semver::satisfies($version, $clause), "{$version} vs {$clause}");
    }
});

test('a wildcard reads as the range Composer reads, not as an exact version', function () {
    // Adversarial review: `8.2.*` matched the regex as a bare 8.2 and became an exact
    // ==8.2.0, refusing every 8.2.x above .0. A false refusal, the outcome
    // the class docblock calls worst.
    foreach ([
        ['8.2.14', '8.2.*'], ['8.3.0', '8.2.*'], ['8.2.0', '8.2.*'],
        ['8.3.0', '8.*'], ['9.0.0', '8.*'], ['8.0.0', '8.*'],
    ] as [$version, $clause]) {
        expect(composerParityClause($version, $clause))
            ->toBe(Semver::satisfies($version, $clause), "{$version} vs {$clause}");
    }
});

test('a caret below 0.1 pins the patch, as its own docblock claims', function () {
    // The docblock says "below 1.0 the caret pins the first non-zero part";
    // the code always bumped the minor when the major was 0, so ^0.0.3 read
    // as <0.1.0 where Composer reads <0.0.4.
    foreach ([['0.0.9', '^0.0.3'], ['0.0.3', '^0.0.3'], ['0.0.4', '^0.0.3'], ['0.5.9', '^0.5.1'], ['0.6.0', '^0.5.1']] as [$version, $clause]) {
        expect(composerParityClause($version, $clause))
            ->toBe(Semver::satisfies($version, $clause), "{$version} vs {$clause}");
    }
});
