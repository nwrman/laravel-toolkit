# What a Good Test Is

A test pins a behavior someone relies on. It must **fail when that behavior breaks** and **keep passing when only the
implementation changes**. A test that cannot fail, or fails on every refactor, costs more than it protects.

- **Assert through the app's front door**: the HTTP response, the props the page receives, the database rows, the
  mail / queue / event / notification fakes, a component's rendered output. Never private methods, call order, or the
  internal collaborators a class happens to use.
- **Stage only states the app can reach.** Use named factory states that describe a real state (`unverified()`,
  `withTwoFactor()`), or the app's own Actions when only a flow produces the state. No raw attributes that bypass the
  app's own rules — a record with no `team_id` the app would never write, a hand-set password hash.
- **Fake only at the system's edges**: Laravel's fakes (`Http`, `Mail`, `Queue`, `Event`, `Notification`, time) and
  `vi.mock` for network and browser APIs. Never mock the app's own Actions, models or services. A third-party SDK with
  no fake goes behind an interface, and the test fakes the interface.
- **Every expected value is a literal you know independently.** Never derive it by re-running the production logic in
  the test — both are then wrong together and the test still passes.
- **Exact assertions.** `toBe` / `toEqual` / `assertExactJson` with the real value. `toBeTruthy`, `toBeDefined`,
  `toBeGreaterThan(0)` or `assertOk()` alone pass for wrong answers too.
- **One deterministic path.** No `if` / `else`, asserting loops or `try` / `catch` in a test body. Different cases are
  a dataset or separate tests.
- **No tautologies.** A test that builds a value and asserts that same value tests nothing.
- **No change detectors.** Don't pin copy, config values, column lists or markup that carries no behavior — those
  tests fail on every legitimate edit and never on a bug.
- **Cover the edges the code decides on**: failure paths, boundaries, empty input, unauthorized and other-team access
  — not only the happy path.
- **A slow or flaky test is a finding, not a number to tune.** Never raise a timeout, add a retry, or skip or mark a
  test to make it pass. Find why it is slow or flaky (an oversized import, real I/O, a missing fake, shared state) and
  fix that; if you cannot, stop and report it.
- **Setup repeated a third time moves into shared tooling**: a factory state first, then a Pest helper in
  `tests/Pest.php`, then a custom `expect()` matcher.

Writing or reviewing tests: call the Skill tool with `test-quality` for the full checklist.
