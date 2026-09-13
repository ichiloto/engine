# S8-A validation and handoff

Date: 2026-09-12. Implementation is complete locally and deliberately uncommitted
in Engine, GPUI Renderer and the `last-legend-s6` example. No release branch,
dependency override, Console change or save migration was introduced.

## Delivered boundary

- Engine: validated source rectangles, sheet metadata, negotiated capability,
  PHP-owned successful-step animation and existing Player projection/transport.
- Renderer: capability-gated crops in v1/v2, actual PNG bounds validation,
  clipped full-sheet rendering and bounded decoded-image reuse.
- Game: only Kaelion's field artwork is animated, using the four supplied sheets
  unchanged. South/East/North/West have 22/21/17/21 populated frames respectively.
- Terminal sprites, world maps, NPCs, battles, gameplay inputs and saved gameplay
  data remain on their existing paths. No tilemaps or animation DSL were added.

The [sprite-sheet guide](sprite-sheets.md) records metadata, timing and protocol.
Automatic GPUI launches require the updated renderer; unsupported binaries fail
clearly instead of showing a full sheet or silently falling back.

## Automated evidence

| Check | Result |
| --- | --- |
| Engine full Pest suite | 1315 passed, 1 existing skip; 4881 assertions in the final run |
| Engine focused sheet/transport/Player tests | 76 passed; 507 assertions |
| Engine save regression subset | 14 passed; 77 assertions |
| Engine PHPStan | No errors |
| Engine PHP syntax | All files under src, tests and resources pass |
| Renderer debug and release tests | 64 passed in each build |
| Renderer clippy, fmt, locked release build | Passed |
| Game graphical Player subset | 6 passed; 72 assertions |
| Game broader fast suite | 188 passed; 6470 assertions; 8 existing long battle simulations excluded |
| Console composer test | All 8 scripts passed, including the local Engine/game fixture |

Engine tests exercise every occupied frame and repeated wraparound in all four
directions, elapsed-time subdivision, idle/direction changes, real successful and
blocked Player movement, new/restored configuration, malformed metadata, strict
capability acknowledgments, crop-only frame changes and legacy whole-image paths.
Renderer tests cover crop geometry, alpha/BGRA, the shorter North sheet, resize
scales, source bounds, snapshot rejection, cache eviction and GPU-image ownership.

Two Game assertions initially failed on ANSI-styled blank map cells. They were
reproduced against an isolated Engine baseline (`f498aab`) with all 61 loaded
Engine classes audited to that baseline. Runtime collision classified those cells
as passable. Reusing the existing `firstBargainPlainLayerRows()` helper fixed the
assertions on both baseline and candidate. No map or collision data was changed.
The legacy calibration-image generator now refuses all writes if any destination
already exists, so it cannot overwrite the supplied character sheets.

## Native integration smoke

Tested on macOS / Apple Silicon through ordinary Console
`ichiloto play --renderer=gpui --no-tmux`, using a private muted runtime, copied
save files and the S6 project's local Engine dependency. Shared project config
and save files were not changed. The final launch used a 135x36 terminal grid,
matching existing battle/menu layout requirements.

The reviewed release executable was installed into the existing ignored local
Engine renderer package. Source and installed binary SHA256:

```text
dfcccf3e91c3b32e3ddcda2fcc0c502895623bd3c683609e5e173cdf954a6c5e
```

Observed in the native window:

- Ordinary startup/title and loading a private saved game into Town Center.
- One correctly cropped, transparent Kaelion sprite in all four directions,
  including the shorter North sheet. No full-sheet or neighboring-cell leakage.
- Successful movement with trace-confirmed source-frame changes (0, 1, 2),
  followed by a return to resting frame 0.
- Opening the main menu hides the field sprite; returning restores it.
- Native close exits successfully (code 0), leaves an empty error log, and restores
  the launching terminal's exact prior `stty` state. All test windows are closed.

A first tool launch inherited an 80x24 PTY and clipped the existing title layout.
It was closed and rerun at 135x36. No layout workaround or gameplay dimensions
were changed to hide this known fixed-grid requirement.

Private local logs are under `/tmp/ichiloto-s8-a-native` and
`/tmp/ichiloto-s8-a-*.log`; these are temporary diagnostic evidence, not repository
dependencies. No raw large trace or generated crop files belong in the commit.

## Limits and next checks

This is a native smoke pass, not a claim of full animation-feel acceptance.
Complete held-key walk cycles, animated dialogue and live resizing were not
manually signed off in this run. Unit tests cover complete cycles and crop resize
geometry. Existing S7 input-repeat/repaint deferrals remain unchanged.

Linux and WSL were not executed here. The PHP metadata, animation and wire path
contain no macOS-specific behavior, but a compatible Renderer build and a real
graphical Linux/WSLg environment still need platform validation. Do not interpret
the macOS result as Linux/WSL acceptance.

One unrelated existing issue surfaced while writing movement tests:
`Vector2::equals()` compares a construction-time hash that does not track later
coordinate mutation. S8 checks actual before/after coordinates for successful
movement; it does not change that existing equality API. A separate global
equality audit is warranted before relying on it for mutable vectors.

No commit or push has been made for S8-A. Baseline heads at implementation start:
Engine `f498aab`, Renderer `6556e41`, S6 game `d1c1993`, Console `4c45f34`.
The original `last-legend` working-tree changes were preserved separately.

## Readability follow-up (2026-09-12)

User screenshots exposed unreadable base-blue colours and undersized text. The
Renderer task corrected the shared ANSI base palette and fits all text using
public GPUI font metrics. No map-specific heuristics, image tint, PHP colour
production changes or grid/crop changes were introduced. The earlier S8-A
binary hash and results above remain historical evidence, not the installed build.

The installed follow-up release SHA256 is
`20a38d04d44f7eb0dd3469dadabdc2b7cf567487bac14db34bd73608bfa6a584`.
All 68 Renderer tests pass in debug/release, plus Clippy, fmt and release build.
Four additional Engine colour-identity cases preserve standard/bright/indexed
blue and literal dark RGB. The full Engine run passes 1319 tests with one existing
skip and 4898 assertions; the focused colour/buffer subset passes 57 tests.

Engine verified the installed hash and inspected the old and new native builds
at the same 135x36 grid. New title, save-list, field labels/HUD, four-character
menu and completed Mother dialogue are visibly more legible. Kaelion remains
graphical during dialogue and disappears/restores correctly across the menu.
The authored sprite remains dark on the plain dark map background; this is not
claimed to solve image/background contrast or future tile-art composition.
Live resize and Linux/WSL were not tested in this follow-up. Exact measured native
font metrics were not retained, so the visual observation is not an exact em-size
measurement. Geometry/resize behavior has automated coverage.

The private muted test window was closed, the process exited 0, its error log is
empty, and pre/post terminal state matches exactly. Config and saves were private
copies under `/tmp/ichiloto-readability`; the original game diff remains unchanged.
Renderer implementation details and build receipts are maintained in that
repository's `docs/readability-validation.md` and `docs/evidence/readability`.
All source changes remain uncommitted.
