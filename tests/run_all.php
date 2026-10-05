<?php
/**
 * Run every test file and report one total.
 *
 * Each file is a standalone script with its own pass/fail counters, so the
 * numbers are read back out of its output. The two summary shapes in the
 * suite are "PASSED: N   FAILED: M" and "N passed, M failed"; both are
 * matched here, and a file whose output matches NEITHER is reported as
 * unparsed rather than counted as zero — a silently-skipped file looks
 * exactly like a passing one.
 *
 * Usage:
 *   php tests/run_all.php            # every file
 *   php tests/run_all.php byok api   # only files whose name contains these
 */

declare(strict_types=1);

$filters = array_slice($argv, 1);

$files = glob(__DIR__ . '/*.php') ?: [];
$files = array_values(array_filter($files, static function (string $f) use ($filters): bool {
    // Never run this runner, or its own scratch files.
    $base = basename($f);
    if ($base === 'run_all.php' || str_starts_with($base, '_')) {
        return false;
    }
    if ($filters === []) {
        return true;
    }
    foreach ($filters as $needle) {
        if (str_contains($base, $needle)) {
            return true;
        }
    }
    return false;
}));

sort($files);

if ($files === []) {
    fwrite(STDERR, "no test files matched\n");
    exit(1);
}

$totalPass = 0;
$totalFail = 0;
$unparsed  = [];
$nonZero   = [];
$started   = microtime(true);

foreach ($files as $file) {
    $name = basename($file);
    $out  = [];
    $code = 0;

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);

    $text = implode("\n", $out);

    $pass = null;
    $fail = null;

    if (preg_match('/PASSED:\s*(\d+)\s+FAILED:\s*(\d+)/i', $text, $m)) {
        [$pass, $fail] = [(int) $m[1], (int) $m[2]];
    } elseif (preg_match('/(\d+)\s+passed,\s*(\d+)\s+failed/i', $text, $m)) {
        [$pass, $fail] = [(int) $m[1], (int) $m[2]];
    } elseif (preg_match('/==\s*Result:\s*(\d+)\s+passed,\s*(\d+)\s+failed/i', $text, $m)) {
        [$pass, $fail] = [(int) $m[1], (int) $m[2]];
    }

    if ($pass === null) {
        $unparsed[] = $name;
        printf("  ??  %-26s no summary found\n", $name);
        continue;
    }

    $totalPass += $pass;
    $totalFail += $fail;

    if ($code !== 0) {
        $nonZero[] = "{$name} (exit {$code})";
    }

    printf(
        "  %s  %-26s %4d pass  %3d fail  exit=%d\n",
        ($code === 0 && $fail === 0) ? 'ok' : '!!',
        $name,
        $pass,
        $fail,
        $code
    );
}

$elapsed = round(microtime(true) - $started, 1);

echo str_repeat('-', 58), "\n";
printf("  %d files, %d passed, %d failed  (%.1fs)\n",
    count($files) - count($unparsed), $totalPass, $totalFail, $elapsed);

if ($unparsed !== []) {
    echo "\n  Files with no recognisable summary:\n";
    foreach ($unparsed as $n) {
        echo "    - {$n}\n";
    }
    echo "  These are NOT counted as passing. Run one by hand to see why.\n";
}

if ($nonZero !== []) {
    echo "\n  Non-zero exit codes (a fatal after the summary counts as failure):\n";
    foreach ($nonZero as $n) {
        echo "    - {$n}\n";
    }
}

exit(($totalFail === 0 && $unparsed === [] && $nonZero === []) ? 0 : 1);