# Styled presentation

The optional graphical Game runtime uses retained protocol v2. Terminal-only play
remains the normal default. Native v1 and stateless v2 full-frame output are removed;
old immutable frame encoders remain for reference/tooling, not native submission.
PHP owns gameplay, bindings, camera, composition, animation and timing. The
renderer receives presentation data, never battle or dialogue instructions.

## Immutable data and cells

`PresentationColor` has validated immutable `ansi16(0..15)`, `ansi256(0..255)`
and `rgb(0..255, 0..255, 0..255)` factories. `PresentationTextRun` contains row,
column, scalar text and nullable typed foreground/background. Both colour fields
are always serialized, including null. Runs reject invalid UTF-8, controls and
negative coordinates; snapshots additionally check grid fit.

`PresentationTextLayer` contains a nonempty UTF-8 ID of at most 256 bytes, a
signed i32 layer and a list of typed runs. Screen text is bounded to 64 layers,
32768 runs and 524288 scalars; sprites have a separate 1024-item limit.
`StyledPresentationFrame` retains the old complete-frame representation for
reference comparisons. Its `textLayers`/`sprites` envelope is no longer accepted
by the native renderer. Arrays detach caller-owned element references.

`Console::presentationSnapshot()` reads the canonical terminal cells after
composition. It returns immutable dimensions and text layers without flushing,
changing dirty spans or altering terminal output. Like `Console::snapshot()`,
it rejects capture during incomplete Console frames/recomposition.

Snapshots and retained row projection use the same `TerminalText::rendererScalar()` normalization:
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
extended colour sequences fail projection rather than inventing a colour. Failed
incremental projection does not consume pending Console row changes.

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

Anonymous Console writes form the screen-space `world` layer at 0. Named scopes
use `Console::withLayer($id, $draw, $priority)` and record only authored cells.
Each row is grouped into horizontally contiguous equal-colour runs, not one run
per cell. Missing named cells are transparent; explicitly written spaces are
opaque and must not be trimmed. A null background paints the renderer's default
`#111820`; it does not make an authored cell transparent. Null foreground uses
`#D9E1E8`.

Existing underlay bookkeeping remains authoritative. Excluding `player`
restores the recorded world beneath the terminal Player while including its PNG.
Later anonymous writes invalidate named provenance at the touched cells even
when glyphs match. Clear/resize/recomposition reset stale provenance; failed
recomposition restores both cells and layer order. Repeated incremental writes
retain one entry per layer/cell rather than an unbounded frame history.

`PresentationLayerPolicy` defines automatic Game composition:

| Content | Numeric layer |
| --- | --- |
| Retained map layers | -100 + authored order 0..99 |
| Non-map screen-space world text | 0 |
| World graphical sprites | Authored 0..999 |
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

## Incremental Console API

```php
$changes = Console::getRetainedPresentationChanges(
    excludedLayers: $graphicalSpriteIds,
    reset: $resendAllText,
    excludedWorldLayers: $retainedMapLayerIds,
);
```

`ConsolePresentationChanges` contains `width`, `height`, `reset`, `layers`,
`removedIds` and nullable `order`. Each changed layer is
`{id, layer, rows:[{row, runs}]}` using immutable `PresentationTextRun` values.
Rows replace complete sparse layer rows; an empty run list removes that row.
Metadata-only updates can have no rows. `removedIds` explicitly removes layers.
`order` is the complete layer order only when changed or resetting; null keeps
the previous order. IDs and numeric priorities remain separate.

The first read, requested reset or changed dimensions returns complete current
text once. Later reads project dirty rows and preserve unchanged rows without a
full snapshot or full-grid cell scan. Exclusion changes invalidate the affected
layer footprints. A successful read consumes the cursor; a caller whose delivery
fails must request a reset. Runtime owns this recovery for normal Game use.

`excludedLayers` hides only those named contributions and reveals their underlay.
`excludedWorldLayers` hides a map contribution and all earlier per-cell underlays,
preserving later dynamic and UI writes. It requires no full-grid replacement mask.

`setRetainedWorldPresentation(true)` takes effect only while layer tracking is
enabled and terminal output is disabled. It omits synthetic base blanks so they
cannot cover retained map artwork. Explicit base spaces and styled spaces remain
opaque; named UI and overlay spaces are unchanged. Non-map base text remains
screen-space, not part of the retained world upload.

In that mode, `removeWorldCellContributions(column, row, width: 1)` clips the
requested region and removes explicit base and named contributions below UI 1000.
It expands intersecting wide glyphs to their whole footprints, preserves named UI
and overlays, and writes no replacement blank. Outside that mode it is a no-op.
For owner-specific cleanup, prefer `removeLayer(id, repaint: false)`; the cell
operation can also remove another overlapping world owner, like map restoration.

`recomposeFrame()` has a sparse branch when tracking is enabled and terminal output
is disabled. It builds authored rows with lazy blank defaults, marks changed old/new
row footprints and rolls back failed compositions. Explicit `getBuffer()` and
snapshot calls still materialize complete logical rows. The T1 terminal dirty-span,
output and ordinary recomposition behavior is unchanged.

## Presenting and waiting

`RendererPresentation::present()` accepts `ConsolePresentationChanges` or either
Console snapshot type. All three use retained v2 output. New/changed layer metadata
uses `put`, changed existing rows use `textRows`, and removed IDs use `remove`.
Foreground-only and background-only changes enqueue updates; unchanged frames do
not. Sprites and canvas elements are retained by ID as well. Failed delivery,
rejection, resize and acknowledgement timeout request a complete reset, not a
stateless frame. See [generations and atomic staging](presentation.md).

`Timers::setFrameTick($update, $present)` registers two phases around the optional
draw callback supplied to `Timers::wait()`: update, draw, present, bounded sleep.
The final draw callback is presented without adding another simulation tick.
Existing single-callback callers remain supported. Game's public
`tickWhileBlocked()` still performs both phases together. Blocked updates pump
lifecycle, timers, audio and notifications but never recursively update scenes.

ActionExecutionState's shared pause, AnimationPlayer, SummonCutscenePlayer,
ScreenTransition, BattleStartState, Modal and SelectModal now use this boundary.
Typewriter/selection content is drawn after background work and before presentation.
Authored durations/FPS and PHP action decisions remain authoritative.

## Input and geometry

V2 key events use the existing `RendererClient -> RendererInputSource ->
InputManager` route and `KeyCode::tryFrom()`. No renderer-specific action mappings
are added. Tests exercise lower/uppercase C/M/T, Tab, Shift+Tab and F5 through
binding/key queries, not parsing alone. Meaningful native actions depend on the
current game context and its existing bindings.

The logical grid remains fixed for graphical sessions. Cell pixels change display
scale, not layout dimensions. Terminal-only dynamic resize remains available.
Native resize resynchronizes retained presentation without changing gameplay
geometry. Retaining presentation does not add renderer-driven animation,
gameplay bindings or save fields.
