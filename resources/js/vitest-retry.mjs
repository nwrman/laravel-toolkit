// `composer test:retry`'s frontend half: re-runs only the Vitest tests the failure
// report lists, matched by file and full name. Exits 0 when there are none.
//
//   node vendor/nwrman/laravel-toolkit/resources/js/vitest-retry.mjs
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { defaultPaths, escapeRegExp, readSections } from './failure-report.mjs';

const failures = readSections(resolve(defaultPaths.jsonFile)).frontend?.failures ?? [];

if (failures.length === 0) {
  console.log('\n  No failed frontend tests in the failure report: nothing to retry.\n');
  process.exit(0);
}

const files = [...new Set(failures.map((failure) => failure.file))];
const names = failures.filter((failure) => failure.name !== '(the file itself)').map((failure) => escapeRegExp(failure.name));
const wholeFile = failures.some((failure) => failure.name === '(the file itself)');
const args = ['run', ...files, ...(names.length > 0 && !wholeFile ? ['-t', `^(?:${names.join('|')})$`] : [])];

console.log(`\n  Retrying ${failures.length} failed frontend test(s) from the failure report.\n`);

const result = spawnSync(resolve('node_modules/.bin/vitest'), args, { stdio: 'inherit' });
process.exit(result.status ?? 1);
