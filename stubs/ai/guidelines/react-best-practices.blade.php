# React Performance Best Practices

Adapted from [Vercel's React Best Practices](https://github.com/vercel-labs/agent-skills/tree/main/skills/react-best-practices) for Inertia 3 + React. Next.js/RSC-specific rules have been removed. For detailed rule explanations with code examples, activate the `react-best-practices` skill in `.ai/skills/react-best-practices/`.

## Critical Priority

- **Eliminate waterfalls**: Use `Promise.all()` for independent async operations. Move `await` into branches where actually used.
- **Optimize bundle size**: Import directly from modules, avoid barrel files. Defer third-party scripts (analytics, logging) until after hydration.

## Medium Priority

- **Re-render optimization**: Don't subscribe to state only used in callbacks. Hoist default non-primitive props outside components. Derive state during render, not in effects. Use functional `setState` for stable callbacks. Never define components inside other components.
- **Rendering performance**: Use `content-visibility` for long lists. Use ternary operators instead of `&&` for conditional rendering. Prefer `useTransition` for loading states.

## Key Rules

- Use `useRef` for transient values that change frequently (e.g., mouse position).
- Use `useDeferredValue` to defer expensive renders and keep inputs responsive.
- Use `startTransition` for non-urgent state updates.
- Group CSS changes via classes or `cssText` instead of individual property mutations.
- Use `Set`/`Map` for O(1) lookups instead of array iteration.
- Use `flatMap` to map and filter in a single pass.
- Return early from functions to avoid deep nesting.
