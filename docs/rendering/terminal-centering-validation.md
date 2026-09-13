# Native terminal viewport centering

## Scope and review route

This Engine-only follow-up centers the complete logical terminal viewport both
horizontally and vertically. It is isolated on `fix/terminal-viewport-centering`,
based on viewport-cap commit `daba78936449d1107b5e0028375e3b83399e624a`.
It is separate from the S8-B tile work at `bef20a2`; the ordinary Game dependency
has not been switched to this worktree. Integration must use the normal
independent-review route into `develop`, after viewport-cap PR #86. No protected
branch bypass, renderer installation or game-content change was performed.

Console owns one physical origin shared by immediate rows, batched spans,
recomposition, region repaint and the legacy one-based Cursor facade. Logical
buffers and Cameras do not receive that offset. For 186x38 physical cells and
135x36 logical cells the zero-based origin is (25, 1), with margins 25/26 columns
and 1/1 rows. Odd spare dimensions leave the extra cell on the right or bottom.

Margin-only resizing clears the physical screen and replays the canonical
buffer, including styles and named-layer content, without resetting logical
state. Even a resize that preserves the rounded origin must repair potential
terminal reflow. The Game shares one physical probe across size and origin
decisions, using its existing quarter-second throttle.

Completed blocked dialogue/timer frames also refresh margins without scene
updates. Composition defers that refresh. While a blocking operation owns the
layout, a physical shrink does not rebuild the logical buffer or Cameras;
clipping is possible until normal gameplay resumes and applies logical sizing.
Startup clears after entering the alternate screen, not on the primary shell.

## Automated validation

- Full Engine suite: **1372 passed, 1 skipped, 5542 assertions**.
- Focused Console/real-Game geometry suite: **39 passed, 602 assertions**.
- PHPStan: **no errors**, serial `--debug` analysis. All six changed PHP files
  pass syntax checks; whitespace checks are clean.
- The skip remains the pre-existing Enemy construction test.
- Tests cover both-axis startup, odd margins, explicit smaller requests,
  equal/smaller terminals, shrink/regrow, unchanged rounded origins and cleanup.
- Byte checks verify startup's translated clear at `CSI 2;26H` for 186x38,
  immediate and batched addressing, full/region replay and incremental output
  after margin-only resize (`CSI 48;177HY` for the bottom-right logical cell).
- Canonical styled snapshots survive margin-only resize and failed replay.
  Failed output restores the prior origin so retry still clears and replays.
- Real Game subprocesses verify Console, options, settings and all five Camera
  viewports, sentinel preservation, one probe per resize and zero while composing.
  These include the real blocked-frame callback and deferred logical resizing.
- Renderer task's read-only patch review found no remaining blockers. Its four
  viewport tests and one fractional tile-edge test pass; no Rust edits were needed.

## Limits

No native window was opened and no user input was needed. Tests capture terminal
bytes and isolate physical size probes; they are not visual Terminal-emulator
acceptance. No installation or new scrolling benchmark was run, and this slice
does not claim a measured performance improvement. S8-B native acceptance stays
pending the prerequisite review/integration gates.

The broad Last Legend suite was not completed because of the unrelated
180,000-battle simulation baseline. Linux/WSLg remains untested. The
dark-player-on-dark-field issue remains an art/background concern. No renderer
protocol or game-content changes were part of this slice. Historical validation
reports remain unchanged.
