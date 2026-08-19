<?php

declare(strict_types=1);

/**
 * Claude Code PreToolUse guard (Bash) — published by nwrman/laravel-toolkit.
 *
 * Backstop for the "Running Tests" AI guideline: blocks raw test runners and the
 * undifferentiated `composer test` so runs go through the toolkit's `composer test:*`
 * wrappers (which produce the structured failure report). Steering lives in the
 * guideline; this only fires when the agent ignores it.
 *
 * Allowed (never blocked): any `composer test:<suite>` / `composer preflight`,
 * single-test debugging via `--filter`, and frontend runners (vitest / bun).
 *
 * Only *command* text is judged. Quoted strings and here-document bodies are data,
 * not invocations, so they are blanked before the command is split into segments —
 * otherwise `gh pr create --body "$(cat <<'EOF' … EOF)"` describing a test change gets
 * chopped on the pipes and parens of its own prose and denied. Command substitutions
 * stay visible through the blanking, so `echo "$(pest)"` is still caught.
 *
 * Contract: reads the PreToolUse JSON from stdin. On a blocked command it prints a
 * `deny` decision and exits 0; otherwise it stays silent and exits 0. It fails open
 * — malformed or empty input never breaks the agent's Bash.
 *
 * Edit the patterns below to tune what this project blocks.
 */
$raw = file_get_contents('php://stdin');

if (! is_string($raw) || trim($raw) === '') {
    exit(0);
}

$payload = json_decode($raw, true);

if (! is_array($payload)) {
    exit(0);
}

// Defensive: only govern Bash, even though settings.json already scopes the matcher.
$toolName = $payload['tool_name'] ?? 'Bash';

if (is_string($toolName) && $toolName !== 'Bash') {
    exit(0);
}

$command = $payload['tool_input']['command'] ?? '';

if (! is_string($command) || trim($command) === '') {
    exit(0);
}

// Split into command segments on shell separators so we inspect each invocation.
$segments = preg_split('/(?:&&|\|\||;|\||\n|\(|\))/', blankLiterals($command)) ?: [$command];

foreach ($segments as $segment) {
    if (isBlockedTestCommand((string) $segment)) {
        denyWithGuidance();
    }
}

exit(0);

/**
 * Replace quoted strings and here-document bodies with blanks, leaving the command
 * structure around them intact.
 *
 * What gets removed is an argument's *contents* — prose, commit messages, PR bodies —
 * which may hold any character a real command can, including the separators the
 * segment split relies on. Blanking rather than deleting keeps neighbouring tokens
 * from gluing together and keeps line breaks where they were.
 *
 * The scanner keeps one frame per command context: the top level, plus one for each
 * open `$(`. Only the frame's own double-quote state decides whether text is data, so
 * a substitution inside a quoted argument is still read as a command.
 *
 * It walks a character array rather than indexing the string. This script is published
 * into consumer apps and reformatted by whatever Pint ruleset they run; one that turns
 * `strlen`/`substr` into their `mb_` forms would leave a byte index measured against a
 * character count, ending the walk at the first non-ASCII byte and leaving the rest of
 * the command unread. Character units throughout survive that rewrite.
 */
function blankLiterals(string $command): string
{
    $blanked = '';
    $characters = mb_str_split($command);
    $length = count($characters);
    $index = 0;

    /** @var list<string> $pendingHeredocs Delimiters awaiting their body, in the order bash consumes them. */
    $pendingHeredocs = [];

    /** @var list<bool> $frames Per-context flag: is this context currently inside double quotes? */
    $frames = [false];

    while ($index < $length) {
        $character = $characters[$index];
        $isData = $frames[count($frames) - 1];

        // A backslash escape can hide a quote or a separator; neutralise both bytes.
        if ($character === '\\' && $index + 1 < $length) {
            $blanked .= '  ';
            $index += 2;

            continue;
        }

        if ($character === '"') {
            $frames[count($frames) - 1] = ! $isData;
            $blanked .= ' ';
            $index++;

            continue;
        }

        // `$(` opens a fresh command context, even in the middle of a quoted argument.
        if ($character === '$' && ($characters[$index + 1] ?? '') === '(') {
            $frames[] = false;
            $blanked .= ' (';
            $index += 2;

            continue;
        }

        if ($character === ')' && count($frames) > 1) {
            array_pop($frames);
            $blanked .= ')';
            $index++;

            continue;
        }

        if ($isData) {
            $blanked .= $character === "\n" ? "\n" : ' ';
            $index++;

            continue;
        }

        // Newline: bash now reads the bodies queued by this line's here-documents.
        if ($character === "\n") {
            $blanked .= "\n";
            $index++;

            foreach ($pendingHeredocs as $delimiter) {
                $index = skipHeredocBody($command, $index, $delimiter, $blanked);
            }

            $pendingHeredocs = [];

            continue;
        }

        // `<<DELIM`, `<<-DELIM`, `<<'DELIM'`, `<<"DELIM"` — but not the `<<<` here-string.
        if ($character === '<' && ($characters[$index + 2] ?? '') !== '<'
            && preg_match('/^<<-?\s*(?:"([^"\n]+)"|\'([^\'\n]+)\'|([A-Za-z_][A-Za-z0-9_]*))/', mb_substr($command, $index), $matches) === 1) {
            $pendingHeredocs[] = $matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : ($matches[3] ?? ''));
            $blanked .= str_repeat(' ', mb_strlen($matches[0]));
            $index += mb_strlen($matches[0]);

            continue;
        }

        // Single quotes are literal all the way to their close — no substitution inside.
        if ($character === "'") {
            $close = mb_strpos($command, "'", $index + 1);
            $end = $close === false ? $length : $close + 1;
            $blanked .= blankPreservingNewlines(mb_substr($command, $index, $end - $index));
            $index = $end;

            continue;
        }

        $blanked .= $character;
        $index++;
    }

    return $blanked;
}

/**
 * Consume a here-document body up to and including its terminator line, appending the
 * blanked equivalent so the surrounding line structure survives.
 */
function skipHeredocBody(string $command, int $index, string $delimiter, string &$blanked): int
{
    $length = mb_strlen($command);

    while ($index < $length) {
        $newline = mb_strpos($command, "\n", $index);
        $line = $newline === false ? mb_substr($command, $index) : mb_substr($command, $index, $newline - $index);
        $index = $newline === false ? $length : $newline + 1;
        $blanked .= str_repeat(' ', mb_strlen($line))."\n";

        // `<<-` strips leading tabs from the terminator; trimming covers both forms.
        if (trim($line) === $delimiter) {
            break;
        }
    }

    return $index;
}

/**
 * Blank every byte of a literal except its newlines, so a multi-line argument does not
 * collapse the segments around it.
 */
function blankPreservingNewlines(string $literal): string
{
    return preg_replace('/[^\n]/', ' ', $literal) ?? $literal;
}

/**
 * A segment is blocked when it runs a raw PHP test runner or the bare full
 * `composer test`, unless it scopes to a single test with `--filter`.
 */
function isBlockedTestCommand(string $segment): bool
{
    $segment = trim($segment);

    if ($segment === '') {
        return false;
    }

    // Single-test debugging is always allowed.
    if (str_contains($segment, '--filter')) {
        return false;
    }

    // Strip leading env-var assignments (e.g. `XDEBUG_MODE=coverage pest`).
    $segment = preg_replace('/^(?:[A-Za-z_][A-Za-z0-9_]*=\S*\s+)+/', '', $segment) ?? $segment;

    // Raw pest / phpunit (full or per-suite runs).
    if (preg_match('#^(?:php[\d.]*\s+)?(?:\./)?(?:vendor/bin/)?(?:pest|phpunit)\b#i', $segment) === 1) {
        return true;
    }

    // `php artisan test` (full or per-suite runs).
    if (preg_match('#^(?:php[\d.]*\s+)?artisan\s+test\b#i', $segment) === 1) {
        return true;
    }

    // Bare `composer test` / `composer run test` — but NOT `composer test:<suite>`.
    if (preg_match('#^composer\s+(?:run\s+)?test(?!:)\b#i', $segment) === 1) {
        return true;
    }

    return false;
}

/**
 * Emit the PreToolUse deny decision (pointing back to the wrappers) and exit.
 */
function denyWithGuidance(): never
{
    $reason = <<<'TXT'
        Run tests through the toolkit wrappers, not the raw runner / full `composer test`. Use:
          • composer test:unit | test:feature | test:browser | test:frontend   (narrow, failure-tracked)
          • composer test:report        (interactive suite picker)
          • composer test:retry         (re-run only the last run's failures)
        Single-test debugging is fine: php artisan test --filter='X'
        For a full sweep, run the suites you need or: composer preflight
        See the "Running Tests" guideline. (Guard: .claude/hooks/enforce-test-command.php — edit to adjust.)
        TXT;

    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => 'deny',
            'permissionDecisionReason' => $reason,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit(0);
}
