# Native terminal viewport cap

## Scope

Native terminal Game sessions now use at most **135x36 cells**, matching the
full battle footprint. Startup and resize share one sizing rule. Smaller physical
terminals and smaller caller requests remain respected; large terminal requests
are capped. Graphical overrides are unchanged. When the effective grid does not
change, the buffer and camera viewports are not reset. Game startup no longer
requests a physical terminal-window resize.

The policy belongs to Game, not low-level Console probes or graphical protocol
geometry. Larger maps still scroll through the camera. A terminal below 135x36
still cannot display the entire fixed battle layout; this is not a responsive
battle-UI change. See [runtime sizing](runtime.md#native-terminal-cap).

## Correctness

- Full Engine suite: **1357 passed, 1 skipped, 5325 assertions**.
- Focused constructor/runtime/launch suites: **79 passed, 603 assertions**.
- PHPStan: **no errors**, serial `--debug` analysis; whitespace check clean.
- Twenty-four constructor scenarios include capped/wide/tall/small terminals,
  explicit requests, late graphical attachment and unchanged graphical overrides.
- Shrink/regrow tests verify Console, settings, options and all five cameras.
  A sentinel cell verifies that above-cap physical resizing preserves the buffer.
- Real terminal startup/cleanup output contains alternate-screen entry/exit but
  no physical window-resize escape sequence.

One earlier full-suite run hit the existing random-critical-hit assertion in
`SkillTest` (372 HP instead of the noncritical 430). The pre-change Engine commit
reproduced both results over 200 identical applications: 187 noncritical and 13
critical. The full rerun passed. No combat or RNG code/tests were changed.

## Bounded scrolling comparison

Baseline: Engine commit `b3e2a26faaea8a2d720a35ad291050cd8dd9bc7f`, exported into
an isolated tree with a copy of the same installed dependencies. Candidate:
this terminal-cap slice (`Game.php` Git blob
`d2b3ccc31c06d9cdf645078a4c25beb53d25e564`). Reflection verified that each run
loaded Game from its intended Engine tree.

Both used the ordinary CLI with `--renderer=terminal --no-tmux`, a **220x60 PTY**,
the same private Garden of Roads checkpoint, muted audio, autosave disabled,
seed 12345 and 30 FPS configuration. Each run completed 12 idle iterations and
36 identical camera positions, with a 30-second safety deadline. Order was
baseline 1, candidate 1, candidate 2, baseline 2, baseline 3, candidate 3.

The metric wraps the PHP camera move and `FieldState::renderTheField()`, including
Console composition and terminal writes. It excludes the normal later Game
render and does not measure physical input, terminal-emulator painting or FPS.
The smaller viewport intentionally renders fewer map cells: **4860 vs 13200**.

| Metric | Uncapped 220x60 | Capped 135x36 |
| --- | ---: | ---: |
| Run 1 median | 191.20 ms | 69.43 ms |
| Run 2 median | 190.07 ms | 69.18 ms |
| Run 3 median | 189.88 ms | 68.91 ms |
| Pooled median (108 pans each) | 190.31 ms | 69.08 ms |
| Pooled p95 (nearest rank) | 206.74 ms | 87.72 ms |

That is **63.7% less measured composition time**, not a guaranteed whole-game
speedup. All six runs exited 0 with empty stderr/error logs and terminal output
enabled. Candidate terminal modes matched before/after in every run. Baseline
run 1 differed only in macOS's PENDIN flag, also observed in the earlier Garden
validation; no terminal-mode workaround was introduced.

Raw samples are retained in [evidence/terminal-viewport](evidence/terminal-viewport).
Private launchers, checkpoint references and terminal receipts remain under
`/tmp/ichiloto-terminal-cap/`. This is an instrumented PTY comparison, not a
sealed game-dev benchmark; no adapter is installed. An earlier exploratory
comparison measured 190.9 vs 66.8 ms; the table above uses the final source after
removing the automatic physical-window resize request.

## Limits

Terminal UI control was denied by the app's safety policy, so no Terminal test
window was opened and no visual Terminal-emulator acceptance is claimed. No
user input was required. All PTY comparison sessions completed and closed.

The broad Last Legend suite was not completed because of the unrelated
180,000-battle simulation baseline. Linux/WSLg remains untested. The
dark-player-on-dark-field issue remains an art/background concern. No renderer
protocol or game-content changes were part of this slice. Historical Garden,
S8-A and graphical viewport validation reports remain unchanged.
