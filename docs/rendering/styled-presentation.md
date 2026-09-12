# Styled presentation (S7-E)

The optional graphical Game runtime defaults to protocol v2. Terminal-only play
remains the normal default, and explicit v1 sessions retain the S2-S6 APIs.
PHP owns gameplay, bindings, camera, composition, animation and timing. The
renderer receives presentation data, never battle or dialogue instructions.

## Immutable data and cells

`PresentationColor` has validated immutable `ansi16(0..15)`, `ansi256(0..255)`
and `rgb(0..255, 0..255, 0..255)` factories. `PresentationTextRun` contains row,
column, scalar text and nullable typed foreground/background. Both colour fields
are always serialized, including null. Runs reject invalid UTF-8, controls and
negative coordinates; snapshots additionally check grid fit.

`PresentationTextLayer` contains a nonempty UTF-8 ID of at most 256 bytes, a
signed i32 layer and a list of typed runs. `StyledPresentationFrame` is separate
from the v1 `PresentationFrame`. It validates unique text-layer IDs, at most 64
layers, 32768 runs, 524288 text scalars and 1024 sprites, then creates a v2 FRAME
message with `textLayers` and `sprites`. Arrays detach caller-owned element
references. Empty lists remove previous content in the atomic replacement.

`Console::presentationSnapshot()` reads the canonical terminal cells after
composition. It returns immutable dimensions and text layers without flushing,
changing dirty spans or altering terminal output. Like `Console::snapshot()`,
it rejects capture during incomplete Console frames/recomposition.

Both versions use the same `TerminalText::rendererScalar()` normalization:
one scalar per logical cell, a wide anchor in its existing cell, continuation
cells represented by spaces, and `?` for incompatible multi-scalar graphemes or
controls after existing stabilization. This is not a second width model. Wide
continuations carry the anchor's colours, preserving coloured blank coverage.

## Existing colour authoring

Keep using `Color` enum sequences and Symfony formatter markup. The focused
`SgrColorParser` extracts colour from a canonical cell's SGR prefix; it is not a
terminal emulator and never sends ANSI to v2.

| SGR | Structured intent |
| --- | --- |
| 30..37 / 90..97 | ANSI16 foreground 0..7 / 8..15 |
| 1 plus 30..37 | Legacy bright ANSI16 foreground 8..15 |
| 22 | Reset intensity; standard foreground becomes non-bright |
| 40..47 / 100..107 | ANSI16 background 0..7 / 8..15 |
| 38;5;n / 48;5;n | ANSI256 foreground/background |
| 38;2;r;g;b / 48;2;r;g;b | RGB foreground/background |
| 39 / 49 | Default foreground/background, serialized null |
| 0 | Reset foreground, background and intensity |

Unsupported non-colour attributes (blink, underline, italic, reverse, strike,
etc.) are ignored only at graphical extraction. For example, WHITE_BLINK keeps
its white foreground, but GPUI does not blink. Malformed/truncated/out-of-range
extended colour sequences fail snapshot generation rather than inventing a colour.

The canonical SGR accumulator also fixes an existing reset bug: a zero RGB
component or compound `0;31` is not an unconditional reset of the whole prefix;
partial 22/39/49 resets must not discard unrelated background/foreground intent.
Terminal output otherwise keeps its existing formatting and authoring path.

ANSI base colours are palette identities, not fixed RGB values. GPUI's shared
dark-terminal palette resolves ANSI16 and ANSI256 indices 0..15 for both
foregrounds and backgrounds. Its readability follow-up leaves the default
foreground/background, explicit RGB and ANSI256 indices 16..255 unchanged.
PHP must not brighten colours to compensate for a renderer theme, and image
pixels are not recoloured by the text palette. This same boundary applies to
dialogue and HUD text over future graphical maps, not just ASCII world text.

## Sparse provenance and ordering

Anonymous Console cells form an opaque complete `world` layer at 0. Named scopes
use `Console::withLayer($id, $draw, $priority)` and record only authored cells.
Each row is grouped into horizontally contiguous equal-colour runs, not one run
per cell. Missing named cells are transparent; explicitly written spaces are
opaque and must not be trimmed. A null background paints the renderer's default
`#111820`; it does not make an authored cell transparent. Null foreground uses
`#D9E1E8`.

Existing S6 underlay bookkeeping remains authoritative. Excluding `player`
restores the recorded world beneath the terminal Player while including its PNG.
Later anonymous writes invalidate named provenance at the touched cells even
when glyphs match. Clear/resize/recomposition reset stale provenance; failed
recomposition restores both cells and layer order. Repeated incremental writes
retain one entry per layer/cell rather than an unbounded frame history.

`PresentationLayerPolicy` defines automatic Game composition:

| Content | Numeric layer |
| --- | --- |
| World text | 0 |
| World graphical sprites | Authored 0..999 (Last Legend Player: 100) |
| Ordinary UI | 1000 |
| FIELD_HUD and Player interaction prompt | 1000 + existing priority 10 |
| MODAL | 1000 + existing priority 20 |
| Notifications | 2000 |
| Screen-transition cover | 3000 |

UIManager derives priorities from `LayeredPresentationInterface`; it does not
invent another modal precedence system. Direct Modal, SelectModal and TextBoxModal
rendering uses the same object-based layer ID, so nesting through UIManager is
safe. NotificationManager wraps its existing render boundary. The field action
prompt is separate from the excluded Player layer.

The runtime rejects world sprites outside 0..999 instead of hiding UI beneath
unexpected sprite priorities. Generic `PresentationSprite` remains unrestricted
within signed i32. Lower numeric layers paint first; equal-layer text precedes
sprites, with stable order within each type. These are PHP-authored values,
not gameplay meaning interpreted by Rust.

## Presenting and waiting

`RendererPresentation::present()` accepts either snapshot type and emits the
matching version through the same client. It compares layer IDs, numeric order,
ordered run positions/text/colours, protocol and sprites. Foreground-only and
background-only changes enqueue frames. Unchanged frames do not. Failed sends
do not advance the sequence or remembered successful state, so retry is safe.
Queue success is not display acknowledgement; neither protocol acknowledges frames.

`Timers::setFrameTick($update, $present)` registers two phases around the optional
draw callback supplied to `Timers::wait()`: update, draw, present, bounded sleep.
The final draw callback is presented without adding another simulation tick.
Existing single-callback callers remain supported. Game's public
`tickWhileBlocked()` still performs both phases together. Blocked updates pump
lifecycle, timers, audio and notifications but never recursively update scenes.

ActionExecutionState's shared pause, AnimationPlayer, SummonCutscenePlayer,
ScreenTransition, BattleStartState, Modal and SelectModal now use this boundary.
Typewriter/selection content is drawn after background work and before presentation.
Authored durations/FPS and PHP action decisions remain authoritative. The
[validation audit](s7-e-validation.md#production-sleep-audit) lists every retained
and replaced production sleep.

## Input and geometry

V2 key events use the existing `RendererClient -> RendererInputSource ->
InputManager` route and `KeyCode::tryFrom()`. No renderer-specific action mappings
are added. Tests exercise lower/uppercase C/M/T, Tab, Shift+Tab and F5 through
binding/key queries, not parsing alone. Meaningful native actions depend on the
current game context and its existing bindings.

The logical grid remains fixed for graphical sessions. Cell pixels change display
scale, not layout dimensions. Terminal-only dynamic resize and v1 plain text
remain available. This phase adds no tile maps, graphical NPCs/battlers, renderer
animation, new game bindings or save fields.
