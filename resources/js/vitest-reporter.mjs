// Vitest reporter: writes the frontend section of the toolkit's failure report
// (storage/logs/test-failures.md and .json), beside the Pest plugin's backend section.
//
//   // vitest.config.ts
//   test: { reporters: ['default', './vendor/nwrman/laravel-toolkit/resources/js/vitest-reporter.mjs'] }
//
// Options (second tuple element): { jsonFile, reportFile }, relative to the project root.
import { relative, resolve } from 'node:path';
import { defaultPaths, escapeRegExp, putSection, shellQuote, timestamp } from './failure-report.mjs';

export default class ToolkitFailureReporter {
  constructor(options = {}) {
    this.paths = {
      jsonFile: resolve(options.jsonFile ?? defaultPaths.jsonFile),
      reportFile: resolve(options.reportFile ?? defaultPaths.reportFile),
    };
  }

  onTestRunEnd(testModules, unhandledErrors = [], reason = 'passed') {
    const root = process.cwd();
    const failures = [];
    let total = 0;

    for (const module of testModules) {
      const file = relative(root, module.moduleId);

      for (const error of module.errors()) {
        failures.push({ file, name: '(the file itself)', message: describe(error) });
      }

      for (const test of module.children.allTests()) {
        total++;
        const result = test.result();
        if (result.state !== 'failed') continue;
        failures.push({ file, name: test.fullName, message: (result.errors ?? []).map(describe).join('\n\n') });
      }
    }

    const notes = unhandledErrors.map((error) => `Unhandled error outside any test: ${describe(error)}`);
    if (reason === 'interrupted') notes.push('The run was interrupted: these results are partial.');

    if (failures.length === 0 && notes.length === 0) {
      putSection(this.paths, 'frontend', null);
      return;
    }

    putSection(this.paths, 'frontend', section(failures, notes, total));
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
  let markdown = `## Frontend (Vitest)\n\n**Command:** \`${command}\` | **Ran:** ${timestamp()} | **Total:** ${total} | **Failed:** ${failures.length}\n\n`;

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
