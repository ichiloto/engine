# Battle-sized graphical viewport validation

## Change

Graphical sessions default to the existing complete battle footprint, 135x36
cells, independently of their launching terminal. Battle, menu and field share
that grid for the session. Explicit per-axis size requests remain supported;
terminal sessions retain terminal auto-sizing. Attaching a runtime after Game
construction re-resolves auto axes and synchronizes all registered cameras,
without starting or drawing inactive scenes.

This changes Engine geometry selection, not the renderer protocol, Rust renderer,
game content, assets or saves. Physical window resizing remains presentation-only.
See [runtime geometry](runtime.md#fixed-geometry) for the contract.

## Automated checks

- Full Engine suite: **1349 passed, 1 skipped, 5143 assertions**.
- Constructor/runtime/launch focus: **71 passed, 421 assertions**.
- PHPStan: **no errors**, using serial `--debug` analysis because the sandbox
  does not permit the parallel worker's loopback listening socket.
- `git diff --check`: clean.

Sixteen real-constructor subprocess scenarios cover terminal and graphical
defaults, 80x24 and 220x60 launching terminals, positional/flat/nested overrides,
per-axis overrides, repeated configuration and late runtime attachment. They
check Console, settings, normalized options, all five registered camera viewports
and, for started graphical scenarios, the actual PHP renderer-stub hello grid.

## Bounded macOS native check

One owned, muted, private Last Legend diagnostic launched through ordinary
`ichiloto play --no-tmux --renderer=gpui`. Its Game specified only `fps`, not
width/height. The real parent PTY was **220x60**. Assets/vendor were read through
the existing S6 project; configuration and the loaded checkpoint were private,
with autosave disabled. No existing user game was running or terminated.

The installed renderer opened a **1350x720** content viewport (10x20 pixel cells).
The following real scene compositions were inspected through native screenshots:

- Complete battlefield, its right/bottom borders and all bottom control panels.
- Complete main menu, including all four party members and bottom panels.
- Garden of Roads field, followed by a PHP camera pan to another map region.
- Battle after native window zoom and again after restoring its original size.

Recorded Console and active-camera dimensions remained **135x36** for battle,
menu, field, pan and battle again. Terminal output stayed disabled. Native zoom
increased the content viewport to **1920x1062** while retaining the centered
1350x720 canvas at scale 1, offsets (285, 171). No scene resized the session grid.

This was a controlled composition/geometry check, not a complete battle
playthrough or physical movement-input test. The probe held simulation between
scene selections and used a 180-second safety deadline. It exited normally with
status **0**, empty stderr/error log and unchanged parent terminal modes. CUA
inventory confirmed the test renderer was closed afterwards; no input was
requested from the user.

Local diagnostic source, phase/grid records and renderer trace remain under
`/tmp/ichiloto-geometry-native/`. Screenshots were inspected in the task, not
exported as a sealed capture bundle; this repository has no game-dev adapter.

## Unchanged validation limits

The historical [Garden/output report](garden-output-validation.md) and
[S8-A report](s8-a-validation.md) remain unchanged. The broad Last Legend suite
was not completed because of the unrelated 180,000-battle simulation baseline.
Linux/WSLg remains untested. The dark-player-on-dark-field issue is an
art/background concern. No renderer protocol or game-content changes were part
of this slice.
