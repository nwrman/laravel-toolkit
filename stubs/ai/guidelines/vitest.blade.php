# Vitest Unit Testing Guidelines

- This project uses **Vitest** with `@testing-library/react` for frontend unit tests.
- Tests are co-located next to the source files they test, using the `.test.ts` or `.test.tsx` extension.
- Vitest globals (`describe`, `it`, `expect`) are enabled globally — do NOT import them manually.
- Use `@testing-library/user-event` for simulating user interactions (clicks, typing, etc.) instead of `fireEvent`.
- Use `@testing-library/jest-dom` matchers (e.g., `toBeInTheDocument()`, `toBeDisabled()`) for DOM assertions.
- Do NOT test ShadCN UI components (`resources/js/components/ui/**`), Wayfinder routes, or auto-generated action files.
- Coverage is scoped to `resources/js/components/**` and `resources/js/hooks/**` only.
- The `vitest.config.ts` is intentionally separate from `vite.config.ts` to avoid loading heavy plugins (Tailwind, Laravel, Wayfinder) during tests. Do NOT merge them.
- Run tests with `bun run test:ui` or `bun run test:ui:watch` for watch mode.
- Run coverage with `bun run test:coverage`.
- CSS is disabled in tests (`css: false`) — do NOT write assertions based on computed styles.

@boostsnippet('Example component test', 'tsx')
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MyButton } from './my-button';

describe('MyButton', () => {
    it('calls onClick when clicked', async () => {
        const user = userEvent.setup();
        const handleClick = vi.fn();

        render(<MyButton onClick={handleClick}>Click me</MyButton>);

        await user.click(screen.getByRole('button', { name: /click me/i }));

        expect(handleClick).toHaveBeenCalledOnce();
    });

    it('renders as disabled when disabled prop is true', () => {
        render(<MyButton disabled>Submit</MyButton>);

        expect(screen.getByRole('button', { name: /submit/i })).toBeDisabled();
    });
});
@endboostsnippet

@boostsnippet('Example hook test', 'ts')
import { renderHook, act } from '@testing-library/react';
import { useCounter } from './use-counter';

describe('useCounter', () => {
    it('increments the count', () => {
        const { result } = renderHook(() => useCounter());

        act(() => {
            result.current.increment();
        });

        expect(result.current.count).toBe(1);
    });
});
@endboostsnippet
