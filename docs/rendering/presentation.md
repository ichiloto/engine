# Presentation frames (S4)

S4 adds explicitly invoked presentation through the existing renderer connection.
Ichiloto remains the PHP game engine. The normal Game runtime still does not
launch GPUI, select a renderer, or send graphical frames. No Player/GameObject
sprite integration, Last Legend integration, camera conversion, or gameplay
behavior is introduced here.

## Immutable Console boundary

After a complete composition, call `Console::snapshot()`. It returns a
`ConsoleFrameSnapshot` containing immutable `width`, `height`, and `rows`.
There are exactly height rows, each containing exactly width Unicode scalars.
Snapshot constructors also detach caller-owned array-element references; PHP
readonly properties alone would not prevent those aliases changing a frame.

The snapshot reads Console's existing canonical `rowToCells()` model, not a
stripped copy of its serialized terminal rows. Capture neither flushes terminal
output nor changes dirty spans, buffer contents, frame depth, dimensions, or cursor
state. Capture during `beginFrame()` nesting or complete-screen recomposition
throws, so presentation cannot observe a partially composed frame. Capture after
`syncDimensions()` reflects the new size without changing older snapshots.

### Terminal cells versus renderer cells

Protocol v1 places one Unicode scalar per renderer cell. It has no terminal-width,
ANSI-style, or grapheme model. Snapshot conversion therefore follows these rules:

| Canonical terminal cell | Snapshot cell |
| --- | --- |
| ANSI-styled scalar | Existing styling stripped; scalar retained |
| Wide-symbol anchor | Stabilized visible scalar, or fallback below |
| Wide-symbol continuation | One plain space |
| Empty/missing cell | One plain space |
| Multi-scalar grapheme after existing stabilization | One visible `?` |
| Control-containing symbol | One visible `?` |

For example, a cat anchored at column 18 remains at column 18; its continuation is
a space at 19, and a border originally at 20 remains at 20. A renderer row's
**scalar count**, not its terminal display width, must equal snapshot width.
The preserved wide glyph may visually extend into that reserved blank cell;
subsequent cells still have fixed coordinates.

Combining sequences such as `e` plus an accent, retained ZWJ compositions, and
explicit variation-selector graphemes use `?` if they remain multi-scalar after
existing terminal stabilization. Their reserved continuation cells remain spaces.
This is a compatibility fallback, not a modification to TerminalText's terminal
semantics. A terminal-stabilized composite reduced to one scalar is retained.

All Unicode Cc controls (including ESC, NUL, CR, LF, TAB, DEL and C1 controls) are
excluded. Literal NUL is sanitized before cell expansion so it cannot be confused
with the internal continuation marker. Invalid UTF-8 in a canonical row throws
with its row index rather than silently replacing a corrupt row with blanks.
The canonical terminal buffer retains its original styles and symbols.

## Presentation models

`Rendering\Presentation\PresentationFrame` is an immutable atomic replacement:
`number`, `text`, and `sprites`. Its constructor validates nonnegative integer
labels, UTF-8 scalar rows without controls, list structure, typed sprites, and
unique sprite IDs. Global v1 limits are 256 rows, 512 scalars per row, and 1024
sprites. Short/empty rows and `text:[]` are valid standalone frame payloads;
Console snapshots are deliberately stricter complete rectangular grids.

PHP supports labels through `PHP_INT_MAX`, a safe subset of the renderer's u64.
`toRendererMessage()` produces a `RendererMessageType::FRAME` envelope. Existing
S2 `RendererMessage` handles JSON/NDJSON encoding; no second framing code exists.
Each frame replaces all prior text and sprites, including removal via empty lists.

`PresentationSprite` contains `id`, `asset`, `x`, `y`, `width`, `height`, `anchor`,
and `layer`. It is manually supplied presentation data, not derived from an engine
object. `PresentationSpriteAnchor::BOTTOM_CENTER` is the only supported anchor.

- IDs must be nonempty UTF-8 without NUL and unique within each frame.
- Asset paths must be nonempty relative UTF-8 paths, use forward slashes, and
  contain no NUL, absolute/drive/URI prefix, backslash, or parent-traversal component.
- Coordinates and layers are signed 32-bit integers; off-grid coordinates are valid.
- Display width/height must each be 1-4096 logical pixels, not terminal cells.

PHP performs structural checks only. It neither resolves paths nor reads images.
The renderer owns canonical assetRoot containment, symlink checks, PNG decoding,
and image-memory limits. PNG content, not a filename extension, is authoritative.

Sprites are stably sorted by ascending layer. Equal layers retain author order,
so later sprites paint above earlier ones. All sprite layers paint above text.
For bottom_center, feet are `((x + 0.5) * cellWidth, (y + 1) * cellHeight)`;
the image top-left is feet minus `(width / 2, height)`. GPUI uses logical pixels
(macOS points), applying display scaling itself.

## Shared client and sequencing

Create one `RendererPresentation` per renderer session using the same client and
grid that were used for hello:

```php
$input = new RendererInputSource($client);
$presentation = new RendererPresentation($client, $grid);

// Explicitly invoked after existing Console composition has finished.
$queued = $presentation->present(Console::snapshot(), $manualSprites);
```

The presenter borrows the client. It never polls transport/input, consumes
ready/close/error events, shuts down the renderer, or calls Console drawing APIs.
The application continues to pump and consume the shared `RendererClient` and
owns explicit shutdown. Presentation and input must never create competing
consumers of the underlying transport's `pollEvents()`.

`present()` rejects snapshot dimensions that differ from the hello grid. V1 fixes
geometry for a session; there is no silent cropping, resizing, or automatic restart.
If an application explicitly starts a new renderer session, it must also create a
new presenter so the first complete frame is sent again.

Frames start at 1 and advance only after a successful client enqueue. Unchanged
normalized text and sprite state return false without sending. Structured strict
comparison avoids JSON serialization for duplicate detection and distinguishes
different numeric-looking string IDs. Equal-layer order remains significant;
reordering distinct layers alone is normalized away. Only the last successfully
queued envelope is retained; there is no additional presentation queue.

S2/S3 queue pressure and send failures propagate. Failed sends neither advance
the sequence nor replace remembered state, so callers can retry. Integer sequence
exhaustion fails explicitly instead of wrapping. Duplicate suppression concerns
queued state, **not a render acknowledgement**: v1 has no frame acknowledgement.
Asynchronous renderer errors remain application-visible; they do not retroactively
turn a successful enqueue into a displayed frame. Asset contents changing behind
an unchanged path are also outside structural duplicate detection.

Runtime cadence, coalescing, asynchronous rejection/retry policy, and asset-cache
invalidation remain later integration decisions. S4 does not silently drop frames
or connect the sequence to the game's frame count.

## Real frame smoke tool

Build the unchanged reference renderer with `cargo build --locked`, then run:

```sh
php tools/gpui-frame-smoke.php \
  --renderer=/absolute/path/to/gpui-renderer \
  --asset-root=/absolute/existing/fixture-assets \
  --duration=120 --change-after=30
```

The tool creates deterministic immutable fixture snapshots directly, independently
of Game, terminal ownership, and project files. Console-to-snapshot-to-presentation
integration is covered separately by automated tests. No terminal rendering
behavior is disabled or rerouted.

Inspect the 48x20 map labeled FRAME 1 and its column ruler. The immediate duplicate
attempt reports `FRAME duplicate suppressed`. At change-after seconds, FRAME 2
replaces the text, moves the fixture `@` from (23,7) to (8,7), and clears sprites.
The old marker and OLD FRAME text must disappear. Logs say **queued**, not rendered;
native visual inspection establishes actual display. Input and lifecycle events
continue to be reported separately while frames are displayed.

Enter in the launching terminal requests protocol shutdown; native Enter, Escape,
and q are only key data. Native close is separately surfaced before cleanup.
Default duration is 60 seconds (positive, at most 300); change-after defaults to
10 seconds (nonnegative, less than duration). Diagnostics remain on stderr.

Optionally add `--sprite=test-sprite.png` when that explicitly supplied fixture
exists under assetRoot. It displays at (8,4), size 32x48, bottom_center; FRAME 2
removes it. The required text-only proof does not depend on any game artwork.

See [S4 validation](s4-validation.md) for results and the next-phase handoff.
