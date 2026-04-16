# General Guidelines

- Don't include any superfluous PHP Annotations, except ones that start with `@` for typing variables.

## Package Manager

- This project uses **bun** — never use `npm`, `npx`, or `pnpm` for any commands.
- Install dependencies: `bun install`
- Run scripts: `bun run <script>`
- Add packages: `bun add <package>`
- Remove packages: `bun remove <package>`

## Linting

- This project uses **oxlint** (not ESLint), configured in `.oxlintrc.json`.
- Auto-fix lint and formatting: `bun run lint`
- Verify without making changes: `bun run test:lint`
- `resources/js/components/ui/` is excluded from linting (shadcn vendor files — overwritten on component updates).
