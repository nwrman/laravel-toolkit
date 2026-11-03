// The failure report's shared file format, for the Vitest side. Mirrors
// Nwrman\LaravelToolkit\Report\FailureReport: one section per runner in
// test-failures.json, the Markdown rebuilt from every section, and the same
// directory lock, because preflight runs Pest and Vitest at once.
import { existsSync, mkdirSync, readFileSync, renameSync, rmdirSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

export const defaultPaths = {
  jsonFile: 'storage/logs/test-failures.json',
  reportFile: 'storage/logs/test-failures.md',
};

const LOCK_TIMEOUT_MS = 10_000;
const STALE_LOCK_MS = 60_000;

export function readSections(jsonFile) {
  if (!existsSync(jsonFile)) return {};
  try {
    const data = JSON.parse(readFileSync(jsonFile, 'utf8'));
    return data && typeof data.sections === 'object' && data.sections !== null ? data.sections : {};
  } catch {
    return {};
  }
}

export function putSection({ jsonFile, reportFile }, name, section) {
  withLock(`${jsonFile}.lock`, () => {
    const sections = readSections(jsonFile);
    if (section === null) delete sections[name];
    else sections[name] = section;

    const names = Object.keys(sections).sort();
    if (names.length === 0) {
      for (const file of [jsonFile, reportFile]) if (existsSync(file)) unlinkSync(file);
      return;
    }

    const sorted = Object.fromEntries(names.map((key) => [key, sections[key]]));
    writeAtomically(jsonFile, JSON.stringify({ generated: new Date().toISOString(), sections: sorted }, null, 4));
    writeAtomically(reportFile, markdown(sorted));
  });
}

function markdown(sections) {
  let text = `# Test Failure Report\n\n**Generated:** ${timestamp()}\n\n`;
  for (const section of Object.values(sections)) {
    if (section && typeof section.markdown === 'string') text += `${section.markdown.trimEnd()}\n\n`;
  }
  return `${text}## Re-run every failed test\n\n\`\`\`bash\ncomposer test:retry\n\`\`\`\n`;
}

export function timestamp() {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
}

function writeAtomically(file, contents) {
  mkdirSync(dirname(file), { recursive: true });
  const temporary = `${file}.tmp-${process.pid}`;
  writeFileSync(temporary, contents);
  renameSync(temporary, file);
}

function withLock(lock, callback) {
  mkdirSync(dirname(lock), { recursive: true });
  const deadline = Date.now() + LOCK_TIMEOUT_MS;

  for (;;) {
    try {
      mkdirSync(lock);
      break;
    } catch (error) {
      if (error.code !== 'EEXIST') throw error;
      try {
        if (Date.now() - statSync(lock).mtimeMs > STALE_LOCK_MS) {
          rmdirSync(lock);
          continue;
        }
      } catch {
        continue;
      }
      if (Date.now() > deadline) throw new Error(`Could not lock the failure report: another test run holds ${lock}.`);
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 50);
    }
  }

  try {
    callback();
  } finally {
    try {
      rmdirSync(lock);
    } catch {}
  }
}

export function escapeRegExp(text) {
  return text.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
}

export function shellQuote(text) {
  return `'${text.replace(/'/g, `'\\''`)}'`;
}
