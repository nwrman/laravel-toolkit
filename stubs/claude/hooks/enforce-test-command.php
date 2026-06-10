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
$segments = preg_split('/(?:&&|\|\||;|\||\n|\(|\))/', $command) ?: [$command];

foreach ($segments as $segment) {
    if (isBlockedTestCommand((string) $segment)) {
        denyWithGuidance();
    }
}

exit(0);

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
