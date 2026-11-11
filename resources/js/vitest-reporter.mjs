// Vitest reporter: writes the frontend section of the toolkit's failure report
// (storage/logs/test-failures.md and .json), beside the Pest plugin's backend section.
//
//   // vitest.config.ts
//   test: { reporters: ['default', './vendor/nwrman/laravel-toolkit/resources/js/vitest-reporter.mjs'] }
//
// Options (second tuple element): { jsonFile, reportFile }, relative to the project root.
import { relative, resolve } from 'node:path';
import { existsSync } from 'node:fs';
import { defaultPaths, escapeRegExp, putSection, readSections, shellQuote, timestamp } from './failure-report.mjs';

export default class ToolkitFailureReporter {
  constructor(options = {}) {
    this.paths = {
      jsonFile: resolve(options.jsonFile ?? defaultPaths.jsonFile),
      reportFile: resolve(options.reportFile ?? defaultPaths.reportFile),
    };
    this.namePattern = undefined;
  }

  onInit(vitest) {
    this.namePattern = vitest?.config?.testNamePattern;
  }

  onTestRunEnd(testModules, unhandledErrors = [], reason = 'passed') {
    const root = process.cwd();
    const failures = [];
    const ran = new Set();
    const filesRan = new Set();
    let total = 0;

    for (const module of testModules) {
      const file = relative(root, module.moduleId);
      filesRan.add(file);
      ran.add(`${file}\0(the file itself)`);

      for (const error of module.errors()) {
        failures.push({ file, name: '(the file itself)', message: describe(error) });
      }

      for (const test of module.children.allTests()) {
        total++;
        const result = test.result();
        // A name filter reports the tests it left out as skipped: they did not run.
        if (result.state !== 'skipped') ran.add(`${file}\0${test.fullName}`);
        if (result.state !== 'failed') continue;
        failures.push({ file, name: test.fullName, message: (result.errors ?? []).map(describe).join('\n\n') });
      }
    }

    // An earlier failure this run did not run is kept only while its test may still exist.
    const selection = this.selection();
    const earlier = readSections(this.paths.jsonFile).frontend?.failures ?? [];
    const kept = earlier.filter((failure) => !ran.has(`${failure.file}\0${failure.name}`)
      && existsSync(resolve(root, failure.file))
      && (selection === 'narrowed' || (selection === 'whole_files' && !filesRan.has(failure.file))));
    failures.push(...kept);

    const notes = unhandledErrors.map((error) => `Unhandled error outside any test: ${describe(error)}`);
    if (kept.length > 0) notes.push(`${kept.length} failure(s) below are from an earlier run: this run did not run those tests.`);
    if (reason === 'interrupted') notes.push('The run was interrupted: these results are partial.');

    if (failures.length === 0 && notes.length === 0) {
      putSection(this.paths, 'frontend', null);
      return;
    }

    putSection(this.paths, 'frontend', section(failures, notes, total));
  }

  selection() {
    // A retry's filter named every earlier failure: one it did not run is gone.
    if (process.env.TOOLKIT_VITEST_RETRY === '1') return 'retry';
    // A name filter: a test it did not run may still exist.
    if (this.namePattern) return 'narrowed';
    // Every test of each file it ran: a test missing from them is gone.
    return 'whole_files';
  }
}

function describe(error) {
  if (!error) return '';
  const message = error.message ?? String(error);
  const stack = typeof error.stack === 'string' ? error.stack.split('\n').slice(0, 6).join('\n') : '';
  return stack.includes(message) ? stack : [message, stack].filter(Boolean).join('\n');
}

function section(failures, notes, total) {
  const command = `vitest ${process.argv.slice(2).join(' ')}`.trim();
  let markdown = `## Frontend (Vitest)\n\n**Command:** \`${command}\` | **Ran:** ${timestamp()} | **Tests run:** ${total} | **Failed:** ${failures.length}\n\n`;

  for (const note of notes) markdown += `> [!WARNING]\n> ${note.replace(/\n/g, '\n> ')}\n\n`;

  failures.forEach((failure, index) => {
    markdown += `### ${index + 1}. \`${failure.name}\`\n\n- **File:** \`${failure.file}\`\n\n`;
    markdown += `\`\`\`\n${failure.message.trim()}\n\`\`\`\n\n`;
    markdown += `Re-run it:\n\n\`\`\`bash\n${retryCommand(failure)}\n\`\`\`\n\n`;
  });

  return { command, ran_at: new Date().toISOString(), total, failed: failures.length, failures, notes, markdown };
}

export function retryCommand(failure) {
  const pattern = failure.name === '(the file itself)' ? '' : ` -t ${shellQuote(`^${escapeRegExp(failure.name)}$`)}`;
  return `node_modules/.bin/vitest run ${shellQuote(failure.file)}${pattern}`;
}
