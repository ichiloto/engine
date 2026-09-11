# S4 validation and handoff record

**Status: S4 validated. No Game runtime or engine-object sprite integration was
added. S5 has not begun.**

Engine baseline: clean `develop` at
`6b76c290abfcb44ff77f10ab4bed12af47abdccf` (S3). Renderer reference: clean,
synchronized `develop` at `c9e87bb56756a7f3d4b18948949be4aa13ddbc79`.
Validation used Apple Silicon macOS, PHP 8.5.10, GPUI 0.2.2, and Rust 1.98.1.

## Automated acceptance

- Before changes: 896 tests passed, one existing skip, 3252 assertions (7.03 s).
- Final full suite: 981 passed, one existing skip, 3426 assertions (7.27 s).
- New S4 suites: 85 passed, 175 assertions (0.30 s).
- All 49 S2 transport tests and existing S3 input/client tests remain green.
- Full `composer analyse -- --debug --no-progress` passes. New snapshot and
  presentation classes also pass a targeted PHPStan level-6 run.
- Syntax checks pass for every added/changed PHP file; staged `git diff --check`
  passes, including new files.
- The renderer task rebuilt the unchanged reference with `cargo build --locked`.

Snapshot coverage includes empty/sparse/full-width grids, ANSI preservation in the
terminal buffer, exact scalar width, wide anchors and continuation spaces, columns
after emoji/CJK, composite/variation/combining fallback, C0/C1 controls, corrupt
UTF-8/non-string rows, active-frame/recomposition rejection, no output/state/cursor
mutation, resize behavior, and immutable copies including caller array references.

Presentation coverage includes typed sprite validation, portable path restrictions,
i32 coordinates/layers, dimension/count limits, duplicate IDs, stable layers,
immutable payloads, exact FRAME encoding, scalar row limits, duplicate suppression,
sequence exhaustion, failed-send retries without sequence/state advancement, fixed
grid rejection, and shared key/ready/close/error preservation without polling or
shutdown ownership. An integrated Console snapshot/presenter test verifies terminal
color changes alone do not enqueue another monochrome frame.

## Native preparation

The Engine task supplied `tools/gpui-frame-smoke.php`; the Renderer task operated
the native UI. The Engine task also inspected all four final screenshots and both
final stdout/stderr captures. No project/game data was loaded. The tool constructs
immutable fixture snapshots directly; Console conversion is covered separately by
automated tests, not claimed as a native game integration.

Native inspection used an ignored temporary app bundle containing the same binary
as the bare executable. Both SHA-256 values were:
`765aa1ebedd2aa6f04ae728984d6141698fd6910fd0e5256cd4ec1bd7e8570fd`.
The bundle supplies native inspection identity only, not a runtime dependency.
The build emitted only the previously known transitive Rust future-compatibility
warnings; neither final native run emitted renderer warnings or errors.

### Required text-only run

Used renderer fixture assets with no sprite argument, `--duration=240`, and
`--change-after=90`. The 48x20 grid used 16x24 logical-pixel cells. FRAME 1 visibly
showed the column ruler, aligned map walls, `@` at (23,7), and OLD FRAME text at
row 16. FRAME 2 moved `@` to (8,7), cleared the old marker, replaced OLD FRAME text,
and retained map alignment. Both states were captured and visually checked.

Exact stdout:

```text
FRAME number=1 queued
FRAME duplicate suppressed
RENDERER ready
INPUT key=up KeyCode=UP
INPUT key=W KeyCode=W
INPUT key=up KeyCode=UP
FRAME number=2 queued (full text replacement; sprites cleared)
INPUT key=q KeyCode=q
INPUT key=right KeyCode=RIGHT
```

The second Up event was not sent by the scripted native test; the full capture is
preserved rather than implying only four keys arrived. Requested Up/Shift-W were
before replacement and q/Right after. Neither q nor any other native key quit.
Enter in the launching PHP terminal requested protocol shutdown. PHP and renderer
exited 0, no close_requested was emitted, and native inventory confirmed shutdown.

The first status lines precede ready in the log because S2's synchronous start
has already completed the handshake before the queued ready event is consumed.
They do not imply a frame was sent before hello/ready. V1 has no per-frame ack;
the queued log plus native screenshots, not the log alone, establish acceptance.

### Optional sprite and native close

Used `--sprite=test-sprite.png --duration=180 --change-after=60`. FRAME 1 displayed
the independent calibration fixture at (8,4), with a 32x48 logical-pixel canvas:
feet (136,120), top-left (120,72), relative to the content area. The titlebar adds
32 pixels to the captured image's vertical coordinates. FRAME 2 removed the sprite
completely while replacing text and moving the marker. Both states were captured.

Exact stdout:

```text
FRAME number=1 queued
FRAME duplicate suppressed
RENDERER ready
INPUT key=up KeyCode=UP
INPUT key=W KeyCode=W
FRAME number=2 queued (full text replacement; sprites cleared)
INPUT key=q KeyCode=q
INPUT key=right KeyCode=RIGHT
RENDERER close_requested
```

The actual native close button was clicked after replacement and key receipt.
Close stayed outside input. PHP and renderer exited 0; native inventory confirmed
the app stopped. The accessibility observation immediately after the click timed
out because the app had already exited; captured close_requested, exit status,
and independent inventory confirmed successful cleanup, not a renderer failure.

Both final stderr captures contained exactly:

```text
Frame client handshake complete. Fixture-only presentation; no Game or gameplay integration.
Renderer exited with status 0; frame client cleanup complete.
```

There was no renderer diagnostic tail or protocol-parser corruption. Error-event
preservation/backpressure were tested deterministically, not claimed as native
fault injection. One preliminary 120/30 text-only run captured only FRAME 2 due to
screenshot latency; it exited cleanly but is not used as first-frame evidence.
Its additional unsolicited keys remain in the supplemental raw capture.

Final local artifacts are `/tmp/ichiloto-s4-native-text.{stdout,stderr}.log`,
`/tmp/ichiloto-s4-native-close.{stdout,stderr}.log`, and the four
`/tmp/ichiloto-s4-native-{text,sprite}-frame{1,2}.jpg` screenshots. These temporary
inspection artifacts are not source dependencies or committed game artwork.

## Scope and next-phase considerations

Console's only production addition is the safe snapshot method and immutable DTO.
TerminalText, terminal writes, dirty spans, composition lifecycle, cursor handling,
Input behavior, Game loop, Player/GameObject, camera, maps, battles, and saves are
unchanged. The separate scenario-music feature branch remains preserved.

Last Legend is unchanged, including the pre-existing user `config.php` edit
(SHA-256 `77a0b26533c8246062182748e46bed4bd651ae24bc1b43ebbebbeb1e6b8177f4`).
The renderer repository has no tracked changes and remains at the reference above.
No separate Linux environment was used; existing terminal and POSIX transport
tests passed on macOS, without modifying their production paths.

Future input and presentation must share one RendererClient. Create a presenter
per renderer session; grid geometry is fixed by hello. Runtime cadence/coalescing,
handling asynchronous frame rejection, and invalidating unchanged-path assets
remain later policy decisions. Graphical Player/GameObject providers, automatic
GPUI launch, project selection, and Last Legend integration were not started.
See [presentation design](presentation.md) for ownership and compatibility rules.
