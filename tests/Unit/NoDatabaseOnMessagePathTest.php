<?php

/**
 * The README claims that nothing on the message path touches your database,
 * and that claim is load-bearing: it is the reason a fully authenticated
 * round trip is cheap enough to sit behind a keystroke. An app that re-checks
 * permissions on every frame can only afford to do so while this holds.
 *
 * A behavioural test cannot prove this, because it can only prove that the
 * paths it happens to exercise stayed off the database. The claim is about
 * every path, so it is asserted structurally instead: no file in the package
 * may reference the database layer at all. Redis is expected and allowed;
 * "database" here means the app's SQL connection, which is the thing that
 * would put milliseconds and a connection pool in front of every message.
 *
 * If this fails, either the offending call belongs in the host application
 * rather than the package, or the README needs to stop making the promise.
 *
 * WHAT THREE ROUNDS OF REVIEW KEPT CATCHING. The scan used to assert only
 * `$offences === []`, with nothing asserting that any file had been read. A
 * src/ containing no PHP files at all passed it silently, and so would a
 * mistyped root, a RecursiveDirectoryIterator that yielded nothing, or an
 * extension filter that excluded everything. Its companion test then "proved
 * the detector works" by running str_contains over a hardcoded string, which
 * tests PHP's string function rather than the scan.
 *
 * Both are fixed by having one scanner and using it for both jobs: the real
 * run reports how many files it read and that number is asserted, and the
 * detector test runs THE SAME scanner over a fixture tree on disk.
 *
 * WHAT THE FOURTH ROUND CAUGHT, AND WHY THE NEEDLES ARE PATTERNS NOW. The list
 * was five literal strings, `DB::`, `Illuminate\Database`, the DB facade
 * import, `Eloquent`, `DatabaseManager`. And a reviewer put
 *
 *     app('db')->connection()->table('lightspeed_probe')->first()
 *
 * inside `Server::handleMessage()`, on the hot path this file is named after,
 * and all three of these tests passed. Not one of those five strings appears in
 * that line. The whole database layer is reachable without naming any of them:
 *
 *   container resolution   `app('db')`, `resolve('db.connection')`, which is
 *                          how you get the DatabaseManager without importing it
 *   the schema builder     `Schema::hasTable()`, a different facade entirely
 *   injected connections   a constructor typed `ConnectionInterface`, which
 *                          names neither the manager nor the facade
 *   the query builder      `->table(...)`, the first call of every query, which
 *                          is what makes the whole thing SQL rather than Redis
 *   raw PDO                skipping Laravel altogether
 *
 * So the needles are regular expressions covering the ways in as well as the
 * names, and each one carries a SAMPLE, a line of code that is genuinely that
 * way in. Which the reachability test below writes to disk and re-scans. A
 * needle whose sample the scanner does not catch is a hole in this file, and
 * the sample is what makes "we look for this" checkable rather than asserted.
 *
 * @var array<string, array{pattern: string, sample: string}>
 */
$forbidden = [
    'the DB facade' => [
        'pattern' => '/\bDB::/',
        'sample' => 'return DB::table("orders")->get();',
    ],
    'the Illuminate database component' => [
        'pattern' => '/Illuminate\\\\Database/',
        'sample' => 'use Illuminate\\Database\\Query\\Builder;',
    ],
    'the DB facade import' => [
        'pattern' => '/Illuminate\\\\Support\\\\Facades\\\\DB/',
        'sample' => 'use Illuminate\\Support\\Facades\\DB;',
    ],
    'Eloquent' => [
        'pattern' => '/\bEloquent\b/',
        'sample' => 'class Order extends Eloquent {}',
    ],
    'the database manager' => [
        'pattern' => '/\bDatabaseManager\b/',
        'sample' => 'public function __construct(private DatabaseManager $db) {}',
    ],
    'the database resolved out of the container' => [
        'pattern' => '/\b(?:app|resolve)\(\s*[\'"]db(?:\.[a-z]+)?[\'"]\s*\)/',
        'sample' => 'return app(\'db\')->connection()->table("probe")->first();',
    ],
    'the schema builder' => [
        'pattern' => '/\bSchema::/',
        'sample' => 'if (Schema::hasTable("orders")) { return true; }',
    ],
    'an injected database connection' => [
        'pattern' => '/\bConnection(?:Interface|ResolverInterface)\b/',
        'sample' => 'public function __construct(private ConnectionInterface $connection) {}',
    ],
    'the query builder' => [
        'pattern' => '/->table\(/',
        'sample' => '$this->connection->table("orders")->where("id", 1)->first();',
    ],
    'a raw PDO handle' => [
        'pattern' => '/\bPDO\b|->getPdo\(/',
        'sample' => '$pdo = $this->connection->getPdo();',
    ],
];

/**
 * Read every PHP file under a root and report what it found.
 *
 * @param  array<string, array{pattern: string, sample: string}>  $forbidden
 * @return array{scanned: int, offences: list<string>}
 */
function scanForDatabaseUse(string $root, array $forbidden): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    $scanned = 0;
    $offences = [];

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;

        $contents = file_get_contents($file->getPathname());
        $relative = substr($file->getPathname(), strlen($root) + 1);

        foreach ($forbidden as $description => $needle) {
            if (preg_match($needle['pattern'], $contents) === 1) {
                $offences[] = "{$relative} references {$description}";
            }
        }
    }

    return ['scanned' => $scanned, 'offences' => $offences];
}

test('no file in the package reaches for the database', function () use ($forbidden) {
    $result = scanForDatabaseUse(dirname(__DIR__, 2).'/src', $forbidden);

    // The scan is only evidence if it actually read the package. The floor is
    // deliberately well under the real count so that adding or removing a class
    // does not fail this, while an empty or wrong root still does.
    expect($result['scanned'])->toBeGreaterThan(30)
        ->and($result['offences'])->toBe([]);
});

/**
 * The guard above is only worth having if it would catch a regression, so this
 * runs the SAME scanner over a tree that contains one, rather than asserting
 * that PHP's str_contains works.
 */
test('the scanner reports a database call when one is present', function () use ($forbidden) {
    $root = sys_get_temp_dir().'/lightspeed-db-scan-'.bin2hex(random_bytes(6));
    mkdir($root.'/Nested', 0777, true);

    try {
        // A clean file, a file with an offence in a subdirectory (so the
        // recursion is exercised too), and a non-PHP file that must be skipped.
        file_put_contents($root.'/Clean.php', '<?php class Clean { public function run(): void {} }');
        file_put_contents($root.'/Nested/Dirty.php', '<?php class Dirty { public function run() { return DB::table("x")->get(); } }');
        file_put_contents($root.'/notes.txt', 'DB::table("x") in prose, which is not code and must not count');

        $result = scanForDatabaseUse($root, $forbidden);

        expect($result['scanned'])->toBe(2)
            ->and($result['offences'])->toHaveCount(1)
            ->and($result['offences'][0])->toContain('Dirty.php')
            ->and($result['offences'][0])->toContain('the DB facade');
    } finally {
        @unlink($root.'/Clean.php');
        @unlink($root.'/Nested/Dirty.php');
        @unlink($root.'/notes.txt');
        @rmdir($root.'/Nested');
        @rmdir($root);
    }
});

/**
 * Every needle has to be reachable, or a typo in the list is a silent hole:
 * the scan would keep passing while no longer looking for that thing at all.
 *
 * Each needle's own sample is what gets written, so this asserts the pattern
 * catches REAL CODE of the kind it names, rather than catching a copy of its
 * own source text. Which is what the previous version did, and which is
 * exactly why `app('db')` could have been added to the list as a literal and
 * still never have matched anything.
 */
test('every forbidden needle is one the scanner would actually catch', function () use ($forbidden) {
    $root = sys_get_temp_dir().'/lightspeed-db-needles-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    $written = [];

    try {
        foreach (array_values($forbidden) as $index => $needle) {
            $path = $root."/Sample{$index}.php";
            $written[] = $path;
            file_put_contents($path, "<?php\n".$needle['sample']."\n");
        }

        $result = scanForDatabaseUse($root, $forbidden);

        expect($result['scanned'])->toBe(count($forbidden));

        // Every sample must be caught by at least its own needle. Counting
        // offences would be wrong here: the samples overlap on purpose (a
        // `->table(` sample is also caught by the query-builder needle), so
        // what is asserted is that no needle went unmatched.
        $report = implode("\n", $result['offences']);

        foreach (array_keys($forbidden) as $description) {
            expect(str_contains($report, $description))
                ->toBeTrue("nothing matched the needle for [{$description}], so it is a hole rather than a guard");
        }
    } finally {
        foreach ($written as $path) {
            @unlink($path);
        }
        @rmdir($root);
    }
});

/**
 * THE ONE THE OLD LIST MISSED, written out as its own test because it is not a
 * hypothetical: this exact expression was put inside `Server::handleMessage()`
 * and three passing tests said the package does not touch the database.
 */
test('the scanner catches the database call that got past the old five-string list', function () use ($forbidden) {
    $root = sys_get_temp_dir().'/lightspeed-db-container-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    try {
        file_put_contents($root.'/Server.php', <<<'PHP'
        <?php

        namespace Lightspeed;

        class Server
        {
            private function handleMessage($server, $frame): void
            {
                $row = app('db')->connection()->table('lightspeed_probe')->first();
            }
        }
        PHP);

        // Named literally so a future edit that drops the container needle
        // cannot quietly satisfy this on the strength of `->table(` alone.
        $offences = scanForDatabaseUse($root, $forbidden)['offences'];

        expect(implode("\n", $offences))->toContain('the database resolved out of the container')
            ->and(implode("\n", $offences))->toContain('the query builder');

        // And the old list, for the record: it saw nothing.
        $oldList = [
            'the DB facade' => ['pattern' => '/DB::/', 'sample' => ''],
            'the Illuminate database component' => ['pattern' => '/Illuminate\\\\Database/', 'sample' => ''],
            'the DB facade import' => ['pattern' => '/Illuminate\\\\Support\\\\Facades\\\\DB/', 'sample' => ''],
            'Eloquent' => ['pattern' => '/Eloquent/', 'sample' => ''],
            'the database manager' => ['pattern' => '/DatabaseManager/', 'sample' => ''],
        ];

        expect(scanForDatabaseUse($root, $oldList)['offences'])->toBe([]);
    } finally {
        @unlink($root.'/Server.php');
        @rmdir($root);
    }
});
