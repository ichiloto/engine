# Continuous integration

The `CI` workflow runs for every pull request and pushes to `develop` and `main`.
It can also be started manually. Changes continue through `develop` to `main`;
CI neither creates releases nor bypasses branch protection.

- Ubuntu 24.04 tests PHP 8.4 and 8.5.
- macOS 15 tests PHP 8.4, including the process and terminal-output fixtures.
- Every job validates Composer metadata, installs `composer.lock`, checks the
  installed platform requirements, and runs the complete Engine Pest suite.
- The Ubuntu PHP 8.4 job also runs PHPStan and Composer's security audit.

Actions use immutable commit pins, with weekly grouped Dependabot updates
targeting `develop`. Jobs have read-only repository access, no persisted checkout
credentials, a 15-minute timeout, and cancellation of superseded runs. Only
Composer downloads are cached; installed dependencies are rebuilt each time.
Failed test runs retain their JUnit report for seven days.

To reproduce the checks locally:

```sh
composer validate --strict
composer install --prefer-dist --no-interaction
composer check-platform-reqs
composer analyse -- --no-progress
composer audit --locked --no-interaction
composer test -- --compact
```

These headless checks are not a native GPUI, interactive terminal, audio-device,
Windows/WSL, or WSLg acceptance test. The historical native Linux/WSLg validation
gap remains until those checks are performed. The Last Legend game suite and its
expensive battle simulations are owned by the game repository, not this workflow.

## Temporary test files

Use `createTestDirectory()` for a fixture directory, or
`getTestTemporaryDirectory()` as the base for files, `tempnam()` and diagnostic
paths. The shared `TemporaryFixtureTestCase` removes the test's namespace after
all local teardown handlers have released their resources. Helpers evaluated
outside a test retain a separate process namespace until shutdown. Manual
`cleanUpTestDirectories()` clears the current scope without ending it.

`tests/Support/TemporaryFixtures.php` holds a filesystem lock for each run.
Normal and fatal exits remove owned contents; a later process recovers a killed
run only after its lock is available. Generated lockless runs left by late
destructor diagnostics are recovered too. Links are removed without traversing
their targets, and cleanup errors name the path and fail verification. Actual
project assets, saves and deliberate review exports are separate resources.

With a shared `vendor` symlink, Pest discovers its setup relative to the vendor
installation. Select the candidate's tests with `--test-directory` relative to
that installation's project root and use its explicit PHPUnit configuration;
verify the loaded setup before qualifying changes to test infrastructure.
