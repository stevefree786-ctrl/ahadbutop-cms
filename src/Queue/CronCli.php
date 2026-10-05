<?php

declare(strict_types=1);

namespace CMS\Queue;

/**
 * Shared plumbing for the CLI cron entry points in crons/.
 *
 * Both entry points parse the same flag vocabulary (`--dry-run`, `--force`,
 * `--date=`, `--limit=`) and have to degrade gracefully when the LLM keys are
 * blank. Keeping that in one place means the two scripts cannot drift into
 * disagreeing about what `--force` means, and it gives the tests a single
 * unit to reason about.
 *
 * Deliberately NOT here: the bootstrap. Every entry point repeats the three
 * bootstrap lines from bin/worker.php verbatim, because a cron script that
 * silently skipped Dotenv would resolve a different database than the worker.
 */
final class CronCli
{
    /**
     * Set by the parser when -h/--help is present. Every entry point must
     * handle it before doing any work.
     */
    public const HELP = 'help';

    /** Exit code for a usage error (unknown flag, bad value). */
    public const EXIT_USAGE = 2;

    /** Exit code for a runtime failure (DB unreachable, config missing). */
    public const EXIT_FAILURE = 1;

    private function __construct()
    {
    }

    /**
     * Parse `--flag` / `--key=value` / `--key value` argument lists.
     *
     * $spec maps a flag name to its type: 'bool', 'string' or 'int'. Bools
     * default false, strings/ints default null. Anything not in $spec —
     * including a bare positional argument — is a usage error rather than
     * being ignored, because silently dropping `--dry-runn` would make a cron
     * write when the operator believed it was only previewing.
     *
     * @param array<int,string>    $args  argv without the script name
     * @param array<string,string> $spec  flag name => bool|string|int
     * @return array{opts: array<string,mixed>, error: ?string}
     */
    public static function parse(array $args, array $spec): array
    {
        $opts = [self::HELP => false];
        foreach ($spec as $name => $type) {
            $opts[$name] = $type === 'bool' ? false : null;
        }

        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = (string) $args[$i];

            if ($arg === '--help' || $arg === '-h') {
                $opts[self::HELP] = true;
                continue;
            }

            if (!str_starts_with($arg, '--')) {
                return ['opts' => $opts, 'error' => sprintf('unexpected argument "%s"', $arg)];
            }

            $name  = substr($arg, 2);
            $value = null;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if ($name === self::HELP) {
                $opts[self::HELP] = true;
                continue;
            }

            if (!isset($spec[$name])) {
                $known = array_merge(array_keys($spec), ['help']);
                sort($known);

                return ['opts' => $opts, 'error' => sprintf(
                    'unknown option "--%s"; known options: --%s',
                    $name,
                    implode(', --', $known)
                )];
            }

            $type = $spec[$name];

            if ($type === 'bool') {
                if ($value !== null) {
                    return ['opts' => $opts, 'error' => sprintf('option "--%s" takes no value', $name)];
                }
                $opts[$name] = true;
                continue;
            }

            // `--key value` form: consume the next token when no `=` was used.
            if ($value === null) {
                if ($i + 1 >= $count) {
                    return ['opts' => $opts, 'error' => sprintf('option "--%s" requires a value', $name)];
                }
                $value = (string) $args[++$i];
            }

            if ($type === 'int') {
                if (preg_match('/^-?\d+$/', $value) !== 1) {
                    return ['opts' => $opts, 'error' => sprintf(
                        'option "--%s" expects an integer, got "%s"',
                        $name,
                        $value
                    )];
                }
                $opts[$name] = (int) $value;
                continue;
            }

            $opts[$name] = $value;
        }

        return ['opts' => $opts, 'error' => null];
    }

    /**
     * Parse a YYYY-MM-DD calendar day in UTC.
     *
     * UTC because Cron::slotFor() computes slots in UTC: letting a local
     * timezone shift the day would mean a cron at 23:00 local and the same
     * cron an hour later could land in two different slots.
     *
     * @throws \InvalidArgumentException on anything that is not a real date
     */
    public static function day(string $raw): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $raw,
            new \DateTimeZone('UTC')
        );

        // createFromFormat normalises overflow dates ('2026-13-45' becomes a
        // valid date in the following year) rather than failing, so the only
        // trustworthy test is the round-trip.
        if ($day === false || $day->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException(
                sprintf('invalid --date "%s"; expected a real calendar day as YYYY-MM-DD', $raw)
            );
        }

        return $day;
    }

    /**
     * Report on the LLM keys without ever requiring one.
     *
     * The known dev-environment condition this exists for: `.env` ships with
     * KILO_API_KEY and OPENCODE_ZEN_KEY set to EMPTY strings, and that is a
     * legitimate configuration — "run the CMS without AI" is what the .env
     * comment says it is for. So neither entry point may treat a blank key as
     * fatal, and neither may invent one. The crons only *enqueue* work; the
     * worker is what calls an agent, and BaseAgent::callProvider() already
     * returns null for a blank key, which BlogAgent/SeoAgent turn into a
     * `status => failed` result rather than an uncaught exception.
     *
     * What the cron can usefully do is say out loud that the job it just
     * queued will not succeed yet.
     *
     * Must be called AFTER the Dotenv bootstrap, otherwise the keys are read
     * before .env exists.
     *
     * @return string|null a human sentence, or null when at least one key is set
     */
    public static function apiKeyNotice(): ?string
    {
        $path = base_path('config/api.php');
        if (!is_file($path)) {
            return sprintf('config/api.php is missing at %s; no LLM provider can be resolved', $path);
        }

        /** @var array<string,mixed> $cfg */
        $cfg  = require $path;
        $kilo = trim((string) ($cfg['kilo']['api_key'] ?? ''));
        $zen  = trim((string) ($cfg['zen']['api_key'] ?? ''));

        if ($kilo === '' && $zen === '') {
            return 'no LLM API key is configured (KILO_API_KEY and OPENCODE_ZEN_KEY are both empty) '
                 . '- this is a valid "CMS without AI" setup, but generation jobs will fail cleanly '
                 . 'in the worker until a key is set';
        }
        if ($kilo === '') {
            return 'KILO_API_KEY is empty; only the opencode-zen provider is available';
        }
        if ($zen === '') {
            return 'OPENCODE_ZEN_KEY is empty; the zen failover provider is unavailable';
        }

        return null;
    }

    public static function out(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public static function err(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
