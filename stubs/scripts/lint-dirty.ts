// @ts-nocheck
import { execSync } from 'node:child_process';
import { existsSync } from 'node:fs';

try {
  // Extract modified (excluding deleted) and untracked files
  const cmd: string =
    'git diff --name-only --diff-filter=d HEAD && git ls-files --others --exclude-standard';
  const stdout: string = execSync(cmd, { encoding: 'utf8' });

  const allFiles: string[] = Array.from(
    new Set(
      stdout
        .split('\n')
        .map((f) => f.trim())
        .filter(Boolean),
    ),
  ).filter((f) => existsSync(f));

  const phpFiles: string[] = allFiles.filter((f) => f.endsWith('.php'));
  const jsFiles: string[] = allFiles.filter((f) => /\.(js|jsx|ts|tsx|css|json)$/.test(f));

  const commands: string[] = [];
  const names: string[] = [];
  const colors: string[] = [];

  if (phpFiles.length > 0) {
    const phpArgs: string = phpFiles.map((f) => `"${f}"`).join(' ');
    commands.push(`vendor/bin/rector process ${phpArgs} --ansi`);
    names.push('Rector');
    colors.push('blue');

    commands.push(`vendor/bin/pint --dirty --parallel`);
    names.push('Pint');
    colors.push('indigo');
  }

  if (jsFiles.length > 0) {
    const jsArgs: string = jsFiles.map((f) => `"${f}"`).join(' ');
    commands.push(`bunx vp check --fix ${jsArgs}`);
    names.push('Frontend');
    colors.push('emerald');
  }

  if (commands.length === 0) {
    // eslint-disable-next-line no-console
    console.log('✨ No dirty files to lint.');
    process.exit(0);
  }

  const concurCmd: string = `bunx concurrently -c "${colors.join(',')}" --names "${names.join(',')}" ${commands.map((c) => `"${c}"`).join(' ')}`;
  execSync(concurCmd, { stdio: 'inherit' });
} catch {
  process.exit(1);
}
