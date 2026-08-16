<?php

namespace Lightspeed\Console;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory as ComponentFactory;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;

/**
 * How `lightspeed:doctor` says what it found.
 *
 * The command decides what is true about an installation; everything here
 * decides what that looks like on a terminal, or as JSON when a script is the
 * reader. The split exists because the two change for unrelated reasons: a new
 * check is a fact about Lightspeed, a wider badge column or a quieter summary
 * line is a fact about reading output.
 *
 * A check is the array the command builds: name, status, detail, and the
 * optional note and fix. Nothing here inspects what a check MEANS, only its
 * status, which is why the status vocabulary lives here next to the badges that
 * render it.
 *
 * Owns: the two column layout, the badges, the summary sentence, the advisory
 * fix list, and the JSON shape.
 * Deliberately does not own: which checks run, whether any of them failed, or
 * the exit code. The command settles all three before it hands the checks over.
 */
class DoctorReport
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    private ComponentFactory $components;

    public function __construct(private readonly OutputStyle $output)
    {
        $this->components = new ComponentFactory($output);
    }

    /**
     * The whole report as one JSON document and nothing else, so a deploy
     * script can read `ok` without parsing a terminal layout.
     *
     * @param  array<int, array>  $checks
     * @param  array<string, int>  $counts
     */
    public function json(array $checks, array $counts): void
    {
        $this->output->writeln(json_encode([
            'ok' => $counts[self::FAIL] === 0,
            'counts' => $counts,
            'checks' => array_values($checks),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, array>  $checks
     * @param  array<string, int>  $counts
     */
    public function render(array $checks, array $counts, bool $withFixes): void
    {
        $this->output->newLine();

        foreach ($checks as $check) {
            $this->components->twoColumnDetail(
                $this->escape($check['name']),
                $this->escape($check['detail']).' '.$this->badge($check['status']),
            );

            if ($check['note'] !== null) {
                $this->indented($check['note']);
            }

            if ($check['fix'] !== null) {
                $this->indented($check['fix']);
            }
        }

        $this->output->newLine();

        if ($counts[self::FAIL] > 0) {
            $this->components->error(sprintf(
                '%d check%s failed. Lightspeed will not work until the lines above are fixed.',
                $counts[self::FAIL],
                $counts[self::FAIL] === 1 ? '' : 's',
            ));
        } elseif ($counts[self::WARN] > 0) {
            $this->components->warn(sprintf(
                'Ready to serve, with %d thing%s worth reading above.',
                $counts[self::WARN],
                $counts[self::WARN] === 1 ? '' : 's',
            ));
        } else {
            $this->components->info(sprintf('All %d checks passed. Run `php artisan lightspeed:serve`.', $counts[self::PASS]));
        }

        if ($withFixes) {
            $this->renderFixes($checks);
        }
    }

    /**
     * Advisory only, and says so. Half of these fixes edit a file this command
     * has no business editing, and the other half are the kind of thing a user
     * should read before it happens to their .env.
     *
     * @param  array<int, array>  $checks
     */
    private function renderFixes(array $checks): void
    {
        $pending = array_values(array_filter($checks, fn (array $check) => $check['fix'] !== null));

        if ($pending === []) {
            return;
        }

        $this->components->twoColumnDetail('<fg=cyan;options=bold>What to do</>', '<fg=gray>Nothing above was run</>');

        foreach ($pending as $check) {
            $this->line('  <options=bold>'.$this->escape($check['name']).'</>');
            $this->indented($check['fix']);
        }

        $this->output->newLine();
    }

    private function indented(string $text): void
    {
        $width = max(52, (new Terminal)->getWidth() - 8);

        foreach (explode("\n", wordwrap($text, $width)) as $line) {
            $this->line('    <fg=gray>'.$this->escape($line).'</>');
        }
    }

    private function badge(string $status): string
    {
        return match ($status) {
            self::PASS => '<fg=green;options=bold>PASS</>',
            self::WARN => '<fg=yellow;options=bold>WARN</>',
            default => '<fg=red;options=bold>FAIL</>',
        };
    }

    /**
     * Redis error text and class names are not ours, and console styling would
     * take a `<` in one of them as a tag.
     */
    private function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }

    private function line(string $text): void
    {
        $this->output->writeln($text);
    }
}
