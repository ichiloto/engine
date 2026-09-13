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
