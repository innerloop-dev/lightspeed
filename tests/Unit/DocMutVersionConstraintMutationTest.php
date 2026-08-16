<?php

use Lightspeed\Boot\VersionConstraint;

/**
 * The constraint reader, pinned at the shapes the earlier table did not reach.
 *
 * `tests/Unit/DoctorCommandTest.php` already pins the fallback against
 * composer/semver for the constraint shapes this package's own composer.json
 * uses. Everything here is about the parts of the reader those shapes never
 * execute: a caret below 1.0, a caret with no minor at all, the clause arriving
 * with whitespace around it, and the decision to prefer composer/semver over
 * the fallback when Composer's own reader is installed.
 *
 * This is the check that sends somebody off to upgrade PHP, so a fallback that
 * disagrees with Composer is worse than no check at all. Every case below that
 * can be stated in Composer's terms is asserted against Composer itself rather
 * than against a number written out here.
 */

/** The private clause reader, which is where the arithmetic actually lives. */
function docMutClause(): Closure
{
    $method = new ReflectionMethod(VersionConstraint::class, 'clauseSatisfied');
    $method->setAccessible(true);
    $constraints = new VersionConstraint;

    return fn (string $version, string $clause): bool => $method->invoke($constraints, $version, $clause);
}

/**
 * A caret or tilde below 1.0 takes the branch `^8.2` never touches, and `^0`
 * with no minor takes the branch that reads a minor that is not there.
 *
 * Pinned against composer/semver, because the point of the fallback is to
 * answer what Composer would answer.
 */
it('reads a caret and a tilde below 1.0 the way composer/semver does', function () {
    if (! class_exists(Composer\Semver\Semver::class)) {
        $this->markTestSkipped('composer/semver is not installed, so there is nothing to pin against.');
    }

    $clause = docMutClause();

    $clauses = ['^0', '^0.3', '^1.2', '~0', '~0.3', '~0.3.1', '~1.2.3'];
    $versions = ['0.0.1', '0.2.9', '0.3.0', '0.3.1', '0.3.9', '0.4.0', '1.0.0', '1.2.0', '1.2.3', '1.2.9', '1.3.0', '1.5.0', '2.0.0'];

    foreach ($clauses as $constraint) {
        foreach ($versions as $version) {
            expect($clause($version, $constraint))
                ->toBe(
                    Composer\Semver\Semver::satisfies($version, $constraint),
                    "fallback disagrees with composer/semver for {$version} against {$constraint}",
                );
        }
    }
});

/**
 * The caret ceiling is arithmetic on integers, and the parts have to BE
 * integers for it.
 *
 * The major part is compared with `===`, so parsing `0.3` into the strings
 * '0' and '3' rather than the integers 0 and 3 quietly stops the below-1.0
 * branch from ever being taken: `^0.3` would then read as `<1.0.0` and admit
 * an 0.9 that Composer refuses.
 */
it('refuses a version above the caret ceiling of a constraint below 1.0', function () {
    $clause = docMutClause();

    expect($clause('0.3.9', '^0.3'))->toBeTrue()
        ->and($clause('0.4.0', '^0.3'))->toBeFalse()
        ->and($clause('0.9.0', '^0.3'))->toBeFalse();
});

/** `^0` names no minor to raise, so the ceiling is the major above it: `<1.0.0`. */
it('reads a bare caret zero as everything below 1.0', function () {
    $clause = docMutClause();

    expect($clause('0.9.9', '^0'))->toBeTrue()
        ->and($clause('1.0.0', '^0'))->toBeFalse();
});

/**
 * A caret at or above 1.0 raises the major, and must not be mistaken for the
 * below-1.0 case: `^1.2` is `<2.0.0`, not `<0.3.0`.
 */
it('reads a caret at or above 1.0 as raising the major', function () {
    $clause = docMutClause();

    expect($clause('1.5.0', '^1.2'))->toBeTrue()
        ->and($clause('2.0.0', '^1.2'))->toBeFalse();
});

/**
 * Constraints arrive with whatever spacing was written around them, and a
 * clause this cannot parse is answered "unreadable, so satisfied" rather than
 * "unsatisfied". Losing the trim would therefore turn a leading space into a
 * silent pass for a PHP that does not satisfy the constraint at all.
 */
it('reads a clause that arrives with whitespace around it', function () {
    $clause = docMutClause();

    expect($clause('9.0.0', '  ^8.2  '))->toBeFalse()
        ->and($clause('8.2.7', '  ^8.2  '))->toBeTrue();
});

/**
 * A wildcard reads as the range Composer reads.
 *
 * This test used to pin the opposite: the fallback treated `8.2.*` as the
 * exact version 8.2.0 and refused 8.2.5, and the pin documented that as a
 * known limitation. Adversarial review called it what it was, a false refusal from
 * the check that sends somebody off to upgrade a PHP that was fine, and the
 * fallback now reads the wildcard as `>=8.2.0 <8.3.0`, differentially
 * pinned in tests/Unit/VersionConstraintComposerParityTest.php.
 */
it('answers a wildcard constraint the same with and without composer/semver', function () {
    expect((new VersionConstraint)->satisfies('8.2.5', '8.2.*'))->toBeTrue()
        ->and(docMutClause()('8.2.5', '8.2.*'))->toBeTrue()
        ->and(docMutClause()('8.3.0', '8.2.*'))->toBeFalse();
});

/**
 * The floor is read out of the package's own composer.json rather than written
 * down twice, which is only true while the value that comes back is the one in
 * the file. Answering null instead would downgrade the PHP row to a warning
 * that says the constraint is unknown, on an install where it is perfectly
 * readable.
 */
it('reads the package PHP constraint out of the package composer.json', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect((new VersionConstraint)->packagePhp())->toBe($manifest['require']['php']);
});
