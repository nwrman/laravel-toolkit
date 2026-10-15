---
name: test-quality
description: "Tear-apart checklist for test quality — weak assertions, conditional test logic, tautologies, change detectors, setup that mirrors the implementation, unreachable staged state, internal mocks, missing edges. Use before writing tests, and to review tests (your own or another agent's) before the gate. Covers Pest and Vitest."
---

# Test Quality

AI-written tests are a minefield: they pass, they raise coverage, and they prove nothing. This skill is the ruthless
pass. It adds to `testing-best-practices` (structure, naming, factories, fakes, endpoint coverage) — load that too;
this file does not repeat it.

## The one question

For every test, name **one wrong implementation that this test would catch**. Then name one that keeps the behavior
and would still pass. If you cannot name the first, the test proves nothing. If a pure refactor would break it, it is a
change detector. Both are defects.

Sources: [The problem with your AI tests](https://www.michaelbromley.co.uk/blog/the-problem-with-your-ai-tests/),
[Change-detector tests considered harmful](https://testing.googleblog.com/2015/01/testing-on-toilet-change-detector-tests.html).

## Checklist

### 1. Weak assertions

An assertion that passes for the right **and** a wrong value is worthless.

| Weak | Use |
| --- | --- |
| `toBeTruthy()`, `toBeDefined()`, `not.toBeNull()` | `toBe(<value>)`, `toEqual(<shape>)` |
| `toBeGreaterThan(0)`, `toBeGreaterThanOrEqual(1)` | `toBe(3)` — you arranged the data, you know the count |
| `toContain('x')` on a value you fully control | `toEqual([...])` with the whole value |
| `assertOk()` alone after a write | the response **and** the row **and** the side effect |
| `assertJsonStructure([...])` | `assertExactJson([...])` / `assertJsonPath('a.b', <value>)` |
| `assertSessionHasErrors('email')` | the error the **page** receives (`assertInertia(... ->where('errors.email', '...'))`) |
| `toHaveBeenCalled()` | `toHaveBeenCalledWith(<args>)` — and only on an edge fake |

### 2. Conditional test logic

`if`, `else`, `switch`, `try` / `catch`, `?:` choosing an assertion, or `expect` inside a loop gives the test more than
one path — usually one that passes no matter what. A test body has **one deterministic path**.

```php
// ✗ passes whether or not the user was suspended
if ($user->fresh()->suspended_at) {
    expect($response->status())->toBe(302);
}

// ✓ one path; a dataset if there are several cases
expect($user->fresh()->suspended_at)->toEqual(now());
$response->assertRedirect(route('users.index'));
```

`expect(fn () => ...)->toThrow(X::class, 'message')`, not `try { ... } catch { expect(...) }`.

### 3. Tautologies

The test constructs a value and asserts that same value — through a factory, a fake's own return value, or a mock
that returns what it was told to.

```ts
// ✗ asserts the mock, not the component
vi.mocked(format).mockReturnValue('$10.00');
render(<Price cents={1000} />);
expect(format).toHaveReturnedWith('$10.00');
```

```php
// ✗ asserts the factory
$user = User::factory()->create(['name' => 'Ana']);
expect($user->name)->toBe('Ana');
```

### 4. Setup that mirrors the implementation

The expected value is computed with the production logic (or a copy of it). Both are wrong together, and the test
passes. Write the literal you worked out by hand: `expect($total)->toBe(11_600)`, not
`expect($total)->toBe($subtotal * (1 + config('tax.rate')))`.

### 5. Change detectors

The test fails on a legitimate edit and never on a bug:

- asserting copy, labels or translation strings that carry no behavior;
- asserting a config value, a `$fillable` list, a column list, a route's middleware array by name;
- snapshotting markup;
- asserting that internal method `A` called internal method `B`, or in which order.

Copy is behavior only when something depends on it — then test the dependency (the constant **is** the copy the page
shows), not the string.

### 6. Unreachable staged state

Setup must describe a state the app can actually be in, produced by the app's own means:

- ✓ a named factory state that encodes a real state: `User::factory()->unverified()`, `->withTwoFactor()`;
- ✓ the app's Action, when only a multi-step flow produces the state (`app(AcceptInvitation::class)->handle(...)`);
- ✗ raw attributes that break the app's invariants: `['team_id' => null]` on a team-scoped record, a hand-built
  password hash, a status no transition leads to, `DB::table()->insert()` past the model.

A test that passes on a state the app can't reach proves nothing about the app.

### 7. Internal mocks

Fake only at the system's edges (see the always-on testing guideline). `mock(CreateInvoice::class)` inside a
controller test asserts the wiring diagram, not the behavior — run the real Action and assert its result. In Vitest,
`vi.mock` the network and browser APIs, never a sibling component or hook of the app.

### 8. Missing edges

For every decision in the code under test (branch, validation, calculation, authorization), there is a test for each
side. Check specifically:

- the failure path asserts **nothing changed** (no row, no mail, no job);
- boundaries: zero, one, the limit, one past the limit;
- empty input: empty string, empty array, missing key, `null`;
- unauthenticated, unauthorized, and **another team's** record;
- the duplicate / already-done case (submitting twice, accepting an accepted invite).

### 9. Repeated setup

The same arrangement written a third time moves into shared tooling, in this order of preference: a named factory
state → a helper function in `tests/Pest.php` (or a Vitest test util next to the code) → a custom `expect()` matcher.
The helper's name states the domain state (`userOnTeamAs(Role::Admin)`), not the steps.

## Writing tests

Before writing, list the decisions in the code and the wrong implementation each test will catch. Write the tests.
Then run this checklist over them as if someone else wrote them.

## Reviewing tests

You are the reviewer when a prompt hands you tests to grade. Do not edit them. For each test in scope:

1. State the wrong implementation it catches, or `none`.
2. Check items 1–9.
3. Report each finding as:

```
<file>:<line> — <checklist item> — <what is wrong> — <the fix, concretely>
```

End with the decisions in the code under test that **no** test covers (item 8). Report nothing you did not verify by
reading the code under test.
