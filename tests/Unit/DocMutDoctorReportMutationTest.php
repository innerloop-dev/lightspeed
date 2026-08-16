<?php

use Illuminate\Console\OutputStyle;
use Lightspeed\Console\DoctorReport;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the doctor's report actually puts on the terminal, and in the JSON.
 *
 * `DoctorCommandTest.php` reads the command's output with every run of
 * whitespace collapsed, which is the right way to assert on a sentence and the
 * wrong way to notice that the blank lines, the badge column, the wrapping and
 * the fix list have all quietly stopped happening. So these render the report
 * directly against a buffer and read the bytes.
 *
 * The JSON side matters for a different reason: `--json` is the contract a
 * deploy script reads, so `ok`, the shape of `checks`, and the encoder flags
 * are behaviour rather than presentation.
 */

/** Render into a buffer and hand back exactly what was written. */
function docMutRender(array $checks, array $counts, bool $withFixes = false, bool $decorated = true): string
{
    $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);

    (new DoctorReport(new OutputStyle(new ArrayInput([]), $buffer)))
        ->render($checks, $counts, $withFixes);

    return $buffer->fetch();
}

/** The `--json` document, decoded, plus the raw text it was decoded from. */
function docMutJson(array $checks, array $counts): array
{
    $buffer = new BufferedOutput;

    (new DoctorReport(new OutputStyle(new ArrayInput([]), $buffer)))->json($checks, $counts);

    $raw = $buffer->fetch();

    return ['raw' => $raw, 'decoded' => json_decode($raw, true)];
}

function docMutCheck(string $name, string $status, string $detail, ?string $note = null, ?string $fix = null): array
{
    return ['name' => $name, 'status' => $status, 'detail' => $detail, 'note' => $note, 'fix' => $fix];
}

/** Counts in the shape the command hands over. */
function docMutCounts(int $pass, int $warn, int $fail): array
{
    return [DoctorReport::PASS => $pass, DoctorReport::WARN => $warn, DoctorReport::FAIL => $fail];
}

/**
 * Run something with the terminal a stated number of columns wide.
 *
 * The wrapping width is read from the terminal at the moment a fix is printed,
 * so a test that did not state the width would assert on how wide the terminal
 * running the suite happens to be.
 */
function docMutAtWidth(int $columns, Closure $body): mixed
{
    $previous = getenv('COLUMNS');

    putenv('COLUMNS='.$columns);

    try {
        return $body();
    } finally {
        $previous === false ? putenv('COLUMNS') : putenv('COLUMNS='.$previous);
    }
}

/** Text of exactly this many characters, breakable at every other position. */
function docMutTextOfLength(int $length): string
{
    $text = rtrim(str_repeat('a ', (int) ceil($length / 2)));

    return substr($text, 0, $length - 1).'a';
}

/** The indented gray lines, which is where a wrapped note or fix ends up. */
function docMutIndentedLines(string $output): array
{
    return array_values(array_filter(
        explode("\n", $output),
        fn (string $line): bool => str_starts_with($line, '    ') && trim($line) !== '',
    ));
}

/**
 * `ok` is the field a deploy script branches on, and it is about failures
 * only: a warning is still a deployment that serves.
 */
it('says ok in the JSON exactly when nothing failed', function () {
    $checks = [docMutCheck('A', DoctorReport::PASS, 'fine')];

    expect(docMutJson($checks, docMutCounts(1, 0, 0))['decoded']['ok'])->toBeTrue()
        ->and(docMutJson($checks, docMutCounts(1, 2, 0))['decoded']['ok'])->toBeTrue()
        ->and(docMutJson($checks, docMutCounts(1, 0, 1))['decoded']['ok'])->toBeFalse();
});

/**
 * `checks` is documented as a list, and the command builds it by merging
 * several arrays. Handing the keys straight to the encoder turns a list with a
 * gap in its keys into a JSON OBJECT, and everything reading `.checks[]` gets
 * nothing back.
 */
it('emits the checks as a JSON list whatever the keys of the array are', function () {
    $checks = [3 => docMutCheck('A', DoctorReport::PASS, 'fine'), 9 => docMutCheck('B', DoctorReport::PASS, 'fine')];

    $json = docMutJson($checks, docMutCounts(2, 0, 0));

    expect($json['decoded']['checks'])->toBeArray()
        ->and(array_keys($json['decoded']['checks']))->toBe([0, 1])
        ->and($json['raw'])->toContain('"checks": [');
});

/**
 * The encoder flags are all four for a reason: a human reads this document
 * when a script has just told them something is wrong, a Redis error carries
 * slashes and any of these strings may carry a name that is not ASCII.
 */
it('encodes the JSON readably, with slashes and unicode left alone', function () {
    $json = docMutJson(
        [docMutCheck('Rédis', DoctorReport::FAIL, 'config/database.php is wrong')],
        docMutCounts(0, 0, 1),
    );

    expect($json['raw'])->toContain("\n    \"ok\": false")
        ->and($json['raw'])->toContain('config/database.php')
        ->and($json['raw'])->not->toContain('config\/database.php')
        ->and($json['raw'])->toContain('Rédis');
});

/**
 * The row: what the check is called, what it found, and the badge that says
 * how bad that is, in that order. A badge that stopped being printed, or that
 * arrived before the detail it grades, is a report nobody can skim.
 */
it('prints each check as a name, its detail, and then its badge', function () {
    $output = docMutRender([
        docMutCheck('Swoole', DoctorReport::PASS, '6.0.0'),
        docMutCheck('Workers', DoctorReport::WARN, '1'),
        docMutCheck('Redis', DoctorReport::FAIL, 'did not answer'),
    ], docMutCounts(1, 1, 1), false, false);

    expect($output)->toMatch('/Swoole [. ]*6\.0\.0 PASS/')
        ->and($output)->toMatch('/Workers [. ]*1 WARN/')
        ->and($output)->toMatch('/Redis [. ]*did not answer FAIL/');
});

/**
 * The report opens on a blank line and puts one between the rows and the
 * sentence that summarises them. Both are the only thing separating this from
 * a wall of text that starts on the same line as the shell prompt.
 */
it('breathes: a blank line before the rows and before the summary', function () {
    $output = docMutRender(
        [docMutCheck('Swoole', DoctorReport::PASS, '6.0.0')],
        docMutCounts(1, 0, 0),
        false,
        false,
    );

    $lines = explode("\n", $output);
    $summary = array_key_first(array_filter($lines, fn (string $line): bool => str_contains($line, 'All 1 checks passed')));

    expect($lines[0])->toBe('')
        ->and(trim($lines[$summary - 1]))->toBe('');
});

/** A note explains a row that is not a problem, and it is printed under it. */
it('prints the note that goes with a check', function () {
    $output = docMutRender(
        [docMutCheck('Workers', DoctorReport::PASS, '1', 'One process serves every connection.')],
        docMutCounts(1, 0, 0),
        false,
        false,
    );

    expect($output)->toContain('One process serves every connection.');
});

/**
 * The summary is a decision, not a decoration: a failure says the installation
 * will not work, a warning says it will serve anyway, and neither may be said
 * about the other. The counts are exact, and the sentence is written for one
 * thing or for several.
 */
it('summarises by the worst thing it found, counted correctly', function () {
    $check = docMutCheck('A', DoctorReport::PASS, 'fine');

    expect(docMutRender([$check], docMutCounts(0, 0, 1), false, false))
        ->toContain('1 check failed. Lightspeed will not work until the lines above are fixed.');

    expect(docMutRender([$check], docMutCounts(0, 0, 2), false, false))
        ->toContain('2 checks failed. Lightspeed will not work until the lines above are fixed.');

    // A failure outranks a warning, even where there are more warnings.
    expect(docMutRender([$check], docMutCounts(0, 3, 1), false, false))
        ->toContain('1 check failed.');

    expect(docMutRender([$check], docMutCounts(2, 1, 0), false, false))
        ->toContain('Ready to serve, with 1 thing worth reading above.');

    expect(docMutRender([$check], docMutCounts(2, 2, 0), false, false))
        ->toContain('Ready to serve, with 2 things worth reading above.');

    $clean = docMutRender([$check], docMutCounts(3, 0, 0), false, false);

    expect($clean)->toContain('All 3 checks passed. Run `php artisan lightspeed:serve`.')
        ->and($clean)->not->toContain('Ready to serve')
        ->and($clean)->not->toContain('will not work');
});

/**
 * `--fix` prints the fixes again as a list at the end, each under the name of
 * the row it belongs to, and only for the rows that have one. A fix printed
 * without its heading, or a heading with no fix under it, is a list nobody can
 * act on.
 */
it('lists every pending fix under the name of its check', function () {
    $output = docMutAtWidth(200, fn (): string => docMutRender([
        docMutCheck('Swoole', DoctorReport::PASS, '6.0.0'),
        docMutCheck('Broadcast driver', DoctorReport::FAIL, 'wrong', null, 'Set BROADCAST_CONNECTION=lightspeed in .env.'),
    ], docMutCounts(1, 0, 1), true, true));

    $lines = explode("\n", $output);
    $heading = array_search("  \e[1mBroadcast driver\e[22m", $lines, true);

    expect($heading)->not->toBeFalse()
        ->and($lines[$heading + 1])->toBe("    \e[90mSet BROADCAST_CONNECTION=lightspeed in .env.\e[39m")
        // The check that has no fix contributes no heading to the list.
        ->and($output)->toContain('What to do')
        ->and(substr_count($output, 'Swoole'))->toBe(1)
        // And the list closes on a blank line rather than running into the prompt.
        ->and($output)->toEndWith("\n\n");
});

/** With nothing to do, there is no list and no heading promising one. */
it('prints no fix list when nothing has a fix', function () {
    $output = docMutRender(
        [docMutCheck('Swoole', DoctorReport::PASS, '6.0.0')],
        docMutCounts(1, 0, 0),
        true,
        false,
    );

    expect($output)->not->toContain('What to do');
});

/**
 * A fix is a paragraph, and a paragraph printed at the full width of a wide
 * terminal is unreadable. It is wrapped to the terminal less the indent it is
 * printed at, and never to less than 52 columns however narrow the terminal
 * claims to be.
 */
it('wraps a fix to the terminal width, less the indent', function () {
    $wrapped = fn (int $columns, int $length): int => count(docMutIndentedLines(docMutAtWidth(
        $columns,
        fn (): string => docMutRender(
            [docMutCheck('A', DoctorReport::FAIL, 'broken', null, docMutTextOfLength($length))],
            docMutCounts(0, 0, 1),
            false,
            false,
        ),
    )));

    // A 200 column terminal wraps at 192, and one column either side of that
    // boundary is the whole of the arithmetic.
    expect($wrapped(200, 192))->toBe(1)
        ->and($wrapped(200, 193))->toBe(2);
});

/**
 * The 52 column floor is a floor, not a width: on a narrow terminal the fix is
 * wrapped at 52 rather than at the handful of columns the arithmetic would
 * otherwise give it.
 */
it('never wraps a fix narrower than 52 columns', function () {
    $wrapped = fn (int $columns, int $length): int => count(docMutIndentedLines(docMutAtWidth(
        $columns,
        fn (): string => docMutRender(
            [docMutCheck('A', DoctorReport::FAIL, 'broken', null, docMutTextOfLength($length))],
            docMutCounts(0, 0, 1),
            false,
            false,
        ),
    )));

    expect($wrapped(55, 52))->toBe(1)
        ->and($wrapped(55, 53))->toBe(2);
});

/**
 * Every wrapped line is indented and printed in gray, tags and all. Losing
 * either end of that leaves an unclosed style tag, and console styling is
 * stateful: the colour then runs on into whatever the shell prints next.
 */
it('indents and closes the styling on every line of a fix', function () {
    $output = docMutAtWidth(200, fn (): string => docMutRender(
        [docMutCheck('A', DoctorReport::FAIL, 'broken', null, 'Run `php artisan lightspeed:install`.')],
        docMutCounts(0, 0, 1),
        false,
        true,
    ));

    expect(explode("\n", $output))->toContain("    \e[90mRun `php artisan lightspeed:install`.\e[39m");
});
