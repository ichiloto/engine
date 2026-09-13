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
native writes are clipped to complete glyphs inside the physical bounds until
normal gameplay resumes and applies logical sizing. A partially visible wide
glyph is blanked only in the output copy and returns intact on regrowth.
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

## PR 88 review follow-up

The physical-clipping review found that replaying a retained 135x36 modal into
an 80x24 terminal overwrote its bottom row and right edge with off-screen cells.
Both native row writers now use `terminalRowSpan()`, including immediate writes,
batched spans, full/differential recomposition and explicit region repaint.
The formatter accepts complete composed cells and never mutates the retained
logical canvas or structured snapshots.

Startup now applies its fresh physical probe through the same logical geometry
synchronization as ordinary resizing, before the first splash or scene render.
This updates Game, Console, settings, options and all camera viewports together,
without drawing onto the primary shell. Logical dimension mutations recompute
the origin immediately and invalidate presentation even after a round trip back
to the old dimensions. Explicit terminal resize requests invalidate the old
physical measurement and use a neutral origin until the next probe. Geometry
mutations are rejected before any side effects during an active frame.

- Full Engine suite: **1434 passed, 1 existing skip, 5923 assertions**.
- Focused Console, real-Game geometry and launch suite: **133 passed, 1096 assertions**.
- Serial PHPStan (`--debug --memory-limit=1G`): **no errors**.
- Regressions cover all native drawing paths, styled CJK, combining marks,
  emoji selectors, either half of a wide glyph, shrink/regrowth, startup
  shrink/growth, explicit requests and direct geometry mutations.
- Independent Pyte replay of the original 80x24 reproduction now preserves
  `VISIBLE` on row 24, leaves the right edge blank and suppresses a CJK glyph
  crossing column 80. The complete 135x36 logical canvas stays unchanged.

For the parent's overlay merge, both native callers must supply overlay-composed
row cells to `terminalRowSpan()`. Keep its shared physical clipping and translated
cursor address rather than restoring the overlay branch's raw span formatter.
Combined overlay integration and native-window acceptance remain parent gates.

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
