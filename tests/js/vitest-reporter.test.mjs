// Run by tests/Report/VitestReporterTest.php through `node --test`.
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import ToolkitFailureReporter, { retryCommand } from '../../resources/js/vitest-reporter.mjs';

function project() {
  const root = realpathSync(mkdtempSync(join(tmpdir(), 'toolkit-vitest-')));
  process.chdir(root);
  return root;
}

// The parts of Vitest's TestModule the reporter reads.
function moduleWith(root, file, tests, errors = []) {
  return {
    moduleId: join(root, file),
    errors: () => errors,
    children: { allTests: () => tests.map(([fullName, state, message]) => ({ fullName, result: () => ({ state, errors: message ? [{ message }] : [] }) })) },
  };
}

test('writes the frontend section beside the backend one', () => {
  const root = project();
  mkdirSync(join(root, 'storage/logs'), { recursive: true });
  writeFileSync(join(root, 'storage/logs/test-failures.json'), JSON.stringify({ sections: { backend: { failed: 1, markdown: '## Backend (Pest)\n\nback' } } }));

  new ToolkitFailureReporter().onTestRunEnd([
    moduleWith(root, 'resources/js/a.test.ts', [['A > renders', 'passed'], ['A > counts (1|2)', 'failed', 'expected 1 to be 2']]),
  ]);

  const report = JSON.parse(readFileSync(join(root, 'storage/logs/test-failures.json'), 'utf8'));
  assert.deepEqual(Object.keys(report.sections), ['backend', 'frontend']);
  assert.deepEqual(report.sections.frontend.failures, [{ file: 'resources/js/a.test.ts', name: 'A > counts (1|2)', message: 'expected 1 to be 2' }]);
  assert.equal(report.sections.frontend.total, 2);
  const markdown = readFileSync(join(root, 'storage/logs/test-failures.md'), 'utf8');
  assert.match(markdown, /## Backend \(Pest\)\n\nback\n\n## Frontend \(Vitest\)/);
});

test('reports a file that failed to load, and an unhandled error', () => {
  const root = project();

  new ToolkitFailureReporter().onTestRunEnd([moduleWith(root, 'resources/js/b.test.ts', [], [{ message: 'Cannot find module ./missing' }])], [{ message: 'boom' }]);

  const section = JSON.parse(readFileSync(join(root, 'storage/logs/test-failures.json'), 'utf8')).sections.frontend;
  assert.deepEqual(section.failures, [{ file: 'resources/js/b.test.ts', name: '(the file itself)', message: 'Cannot find module ./missing' }]);
  assert.deepEqual(section.notes, ['Unhandled error outside any test: boom']);
});

test('removes its section, and both files with it, once the run passes', () => {
  const root = project();
  new ToolkitFailureReporter().onTestRunEnd([moduleWith(root, 'c.test.ts', [['C > x', 'failed', 'no']])]);

  new ToolkitFailureReporter().onTestRunEnd([moduleWith(root, 'c.test.ts', [['C > x', 'passed']])]);

  assert.equal(existsSync(join(root, 'storage/logs/test-failures.json')), false);
  assert.equal(existsSync(join(root, 'storage/logs/test-failures.md')), false);
});

test('prints a re-run command whose pattern matches only that test', () => {
  const command = retryCommand({ file: "resources/js/it's.test.ts", name: 'A > counts (1|2)' });
  const pattern = command.match(/-t '(.+)'$/)[1];

  assert.equal(command, `node_modules/.bin/vitest run 'resources/js/it'\\''s.test.ts' -t '^A > counts \\(1\\|2\\)$'`);
  assert.equal(new RegExp(pattern).test('A > counts (1|2)'), true);
  assert.equal(new RegExp(pattern).test('A > counts (1|2) again'), false);
  assert.equal(new RegExp(pattern).test('A > counts 1'), false);
});
