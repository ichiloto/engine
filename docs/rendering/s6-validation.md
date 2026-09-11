# S6 validation: optional Game runtime and Last Legend

**S6 validation passed on 2026-09-11.** Both complete automated suites pass,
including the game-suite repeat after the final constructor correction. Native
Home movement, all four graphical directions, blocked-wall facing, window-close
cleanup and the ordinary terminal regression passed. Renderer source is unchanged.

## Preserved baselines

- Engine: `develop`, `ccbb07fd4370c8134420ee6d32baf9d275737abd` (S5).
- Last Legend: isolated `examples/last-legend-s6` worktree on `develop`,
  baseline `d4f383f`. The user's original `examples/last-legend` remains on
  `feature/m3-slice-d`; its `config.php` is not changed by S6.
- Renderer: clean `develop` at `c9e87bb56756a7f3d4b18948949be4aa13ddbc79`.
  `cargo build --locked` passed. Executable SHA-256:
  `765aa1ebedd2aa6f04ae728984d6141698fd6910fd0e5256cd4ec1bd7e8570fd`.
- Engine `feature/scenario-music` is preserved at
  `b8b680068eed387294e7c9a3f3e913b0856d7ff2`. Its integration is deliberately
  separate from S6; the Game task is validating music in isolated worktrees.

## Automated evidence

Apple Silicon macOS, PHP 8.5.10:

- Engine before: 1068 passed, one existing skip, 3724 assertions (8.43 s).
- Engine after: 1105 passed, one existing skip, 3852 assertions (8.88 s).
- New S6 suites: 37 passed, 129 assertions (0.72 s), including four real
  Game-constructor subprocess cases rather than only the size resolver in isolation.
- Full PHPStan passes; new runtime, collection and project-config classes also
  pass targeted level 6. Changed PHP files pass syntax checks and both
  repositories pass `git diff --check`.
- Existing game suite: 179 tests passed, 6480 assertions (1208.85 s). The first
  Composer run hit its default 300-second timeout. The diagnostic retry used
  `--debug`, which conflicted with Pest's `--no-output` and produced a runner
  warning/exit 1 despite all test assertions passing. The engine working tree
  was evolving during this run; it is not a pristine S5-only baseline.
- Final game suite: 180 passed, 6494 assertions (1186.55 s), using
  `COMPOSER_PROCESS_TIMEOUT=0` without `--debug`; exit 0.
- After the constructor correction found by native terminal regression, the
  complete game suite passed again in two complementary filter groups:
  176 tests/6430 assertions (478.49 s) and the remaining four long calibration
  cases/64 assertions (704.22 s), both exit 0. Combined: 180 tests/6494
  assertions; no balance simulations were omitted.
- New game PNG/configuration test: one passed, 14 assertions. No desktop or
  GPUI process is required by that test.
- Composer updated the existing local engine path dependency and required
  transitive packages normally. No vendor files or temporary version overrides
  were hand-edited. The game lock is updated to the validated engine commit
  before publishing the game commit.

The new tests exercise new/save project artwork loading without graphical save
data, missing/malformed configuration, host opt-in, actual Player/Camera
projection, blocked movement changing only the asset, duplicate suppression,
nonblank underlay, event-cue/NPC composition ordering, identical-glyph overlays,
wide/multi-row footprints, immutable snapshots, off-grid masking, lifecycle
errors/close, input restoration, fixed dimensions, real Game render/blocked-tick
paths and ordinary-dialogue retention. Existing S2-S5 and save tests are included
in the full engine run. Existing non-TTY shell diagnostics remain visible.
No separate Linux execution has been performed.

## Native evidence

The existing renderer task owns native input and evidence capture. Source in
the renderer repository remains unchanged. Acceptance uses the real Last Legend
Game and the real `happyville/home`, not a simplified map or replacement loop.

The preliminary launch inherited an 80x30 terminal grid with 16x24 cells.
User exploration exposed clipping: battle requires 135x36 and the main menu
110x35. Those cells were lost inside Console, not through a GPUI viewport
transform. The developer launcher now selects/validates at least 135x36 and
defaults to 10x20 cells; explicit legacy-default dimensions are respected.

User exploration also exposed the conservative event-session/blocked-tick
exclusion of Player sprites during ordinary dialogue. Eligibility now follows
field presentation ownership rather than input availability; non-field menus
and active cinematics remain excluded.

The corrected run records:

- Native title with all menu entries and complete border at 135x36.
- Native Return enters New Game at Home (8,4), South, and advances opening
  dialogue while the PNG remains visible.
- User-provided manual-play captures show PNG retention during Home Whiskers
  and overworld Father Emon dialogue, a complete main menu, and a complete
  battle screen. These are manual user exploration, not controlled agent input.
- Mother/Whiskers/map stay terminal representations; the field Player does not
  overlay full-screen menus or battles.
- Actual native close button stops the corrected run: PHP exit 0, empty stderr,
  identical before/after `stty` state, and no remaining PHP/renderer/audio child.
  A preliminary run also exited cleanly after the user closed it.

Evidence prefixes are `/tmp/ichiloto-s6-native-preliminary.*`,
`/tmp/ichiloto-s6-native-controlled.*` and
`/tmp/ichiloto-s6-native-home.*`. These are local validation artifacts, not
production dependencies. `controlled-title.jpg` shows the corrected geometry.
Exact renderer snapshot masking is proven by automated payload comparisons;
an opaque PNG in a screenshot alone cannot establish what text is underneath.

### Controlled direction and collision proof

The final run used a byte-identical developer launcher in a private temporary
project with real assets/vendor and fresh local saves. At the user's request,
only that private configuration muted audio (master volume 0, music and SFX
disabled). Shared configuration was untouched. The process/window audit found
one PHP parent, one renderer and no audio child; the renderer task exclusively
owned input throughout the sequence.

Return selected New Game; four Returns advanced the real opening dialogue to
free field at Home (8,4), South. The captured sequence was:

| Input | World position | Graphical asset |
| --- | --- | --- |
| Initial | (8,4) | South.png, green/down |
| Right | (9,4) | East.png, cyan/right |
| Left | (8,4) | West.png, magenta/left |
| Up | (8,3) | North.png, amber/up |
| Down | (8,4) | South.png |
| Right | (9,4) | East.png |
| Up | (9,3) | North.png |
| Left | (8,3) | West.png, before wall attempt |
| Up into wall at (8,2) | (8,3), unchanged | North.png |

The blocked-turn captures have identical colored-body bounds
`[586,307,603,330]`, with the direction arrow changing. The terminal mirror
independently writes the west then north glyph at the same one-based cell
(row 15, column 60). The existing HUD heading remains stale after a blocked
turn; the graphical/terminal art demonstrates the new heading, not that HUD.
Mother and Whiskers remain terminal-rendered as the Player moves.

The actual native close button ended this final run with PHP exit 0, empty
stderr, byte-identical before/after `stty` state and no remaining test PHP,
renderer or audio process. The earlier unmuted close test separately proves
cleanup with a live audio child. A historical user report of two windows was
not reproduced or attributed; the final run verified one window/process and
none after close. Two earlier setup/interrupted runs exited 143 and are not
counted as native-close acceptance.

Final artifacts are `/tmp/ichiloto-s6-native-muted.*`,
`/tmp/ichiloto-s6-native-muted-movement.txt` and JPEGs with the prefix
`/tmp/ichiloto-s6-native-muted-`: `title`, `south-8-4`, `east-9-4`, `west-8-4`,
`north-8-3`, `wall-before-west-8-3` and `wall-after-north-8-3`.

### Ordinary terminal regression

The first ordinary-terminal run independently measured a 135x36 PTY but exposed
170x36 logical startup. The constructor had injected default `screen` keys,
incorrectly making them appear caller-authored. It now leaves promoted
dimensions as fallbacks rather than manufacturing explicit options. New real
constructor tests cover default auto-size, nondefault positional size, explicit
flat options and explicit nested options. The first run did already prove immediate arrow input, all original
glyphs, Home movement/collision and normal menu quit with no renderer child.
Its only `stty` round-trip difference was macOS PENDIN, independently reproduced
with a plain `stty` noncanonical/echo round trip; echo and canonical mode were
restored. Artifacts use `/tmp/ichiloto-s6-terminal.*`.

Corrected ordinary-terminal acceptance passed using PHP 8.5.10 and the unchanged
`php last-legend.php` launcher in a save-isolated project copy with the real
assets/vendor. Physical 135x36 now starts logical 135x36; resizing only the PTY
to 145x40 produces 145-column output and the HUD on row 40. No renderer starts.
From Home (8,4), South: Right, Left, Up, Down, Right, Up, Left reaches (8,3), West;
Up into the wall at (8,2) draws the original north glyph at the same cell without
moving. All four original glyphs and immediate noncanonical arrow input work.
The existing HUD's stale heading on a blocked turn is not claimed fixed.
Escape, Up (Quit), Enter, Down (Exit), Enter quits normally: exit 0, empty stderr,
canonical/echo restoration, and no remaining PHP/audio child. Evidence uses
`/tmp/ichiloto-s6-terminal-final.*` and
`/tmp/ichiloto-s6-terminal-final-movement.txt`. No shared saves/config were changed.

## Scope and limitations

The [runtime design](runtime.md) records loading, provider-host, masking, input,
fixed-grid and cleanup policies. The game guide documents its launcher and four
32x48 transparent calibration PNGs; they are spike placeholders, not final art.

Graphical NPCs/maps/battles/UI, animation, interpolation, new input devices,
protocol changes and renderer source changes are not included. Active
cinematics remain text-only. Protocol-v1 sprites draw after flattened text, so
full UI/sprite occlusion is still a limitation. Sparse text and disconnected
box-art strokes come from the unchanged renderer's font metrics and are not
claimed fixed by the logical-grid correction.

The original game `config.php` remains SHA-256
`77a0b26533c8246062182748e46bed4bd651ae24bc1b43ebbebbeb1e6b8177f4`.
The Game task's concurrent music edits in the original worktree are unrelated
and must not be staged by S6. No release branch is created.
