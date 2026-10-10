# Retained presentation

Native GPUI uses protocol v2 session-owned presentation state. PHP sends changed
elements, text rows and camera state, not a complete screen on every frame.
PHP still owns gameplay, timing, input bindings and the camera. See
[runtime startup](runtime.md), [styled text](styled-presentation.md) and
[retained world layers](tile-batches.md).

**Removed behavior:** native protocol v1 presentation, stateless v2 full-screen
replacement and direct `tileBatches` presenter output are removed. There is no
new retained-mode capability flag or parallel native full-resend path.
`ConsoleFrameSnapshot`, `ConsolePresentationSnapshot`, `PresentationFrame` and
`StyledPresentationFrame` remain useful immutable reference/tooling models. Their
old wire encoders are not accepted native presentation packets. Passing either
Console snapshot to `RendererPresentation` converts it to retained styled text;
it does not select the old wire format.

## Immutable Console boundary

For reference/tooling capture after a complete composition, `Console::snapshot()` returns a
`ConsoleFrameSnapshot` containing immutable `width`, `height`, and `rows`.
There are exactly height rows, each containing exactly width Unicode scalars.
Snapshot constructors also detach caller-owned array-element references; PHP
readonly properties alone would not prevent those aliases changing a frame.

The snapshot reads Console's existing canonical cell model, not a
stripped copy of its serialized terminal rows. Capture neither flushes terminal
output nor changes dirty spans, buffer contents, frame depth, dimensions, or cursor
state. Capture during `beginFrame()` nesting or complete-screen recomposition
throws, so presentation cannot observe a partially composed frame. Capture after
`syncDimensions()` reflects the new size without changing older snapshots.

### Terminal cells versus renderer cells

Screen-space text places one Unicode scalar per renderer cell. Snapshot and
incremental row conversion use the same normalization rules:

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

The reference-only `Rendering\Presentation\PresentationFrame` is an immutable atomic replacement:
`number`, `text`, and `sprites`. Its constructor validates nonnegative integer
labels, UTF-8 scalar rows without controls, list structure, typed sprites, and
unique sprite IDs. Global v1 limits are 256 rows, 512 scalars per row, and 1024
sprites. Short/empty rows and `text:[]` are valid standalone frame payloads;
Console snapshots are deliberately stricter complete rectangular grids.

PHP supports labels through `PHP_INT_MAX`, a safe subset of the renderer's u64.
`toRendererMessage()` produces a `RendererMessageType::FRAME` envelope. Existing
`RendererMessage` handles JSON/NDJSON encoding; no second framing code exists.
These replacement semantics apply to the reference model, not native sessions.

`PresentationSprite` contains `id`, `asset`, `x`, `y`, `width`, `height`, `anchor`,
and `layer`. It holds screen-space presentation data, whether manually supplied
or projected from an engine provider. It is not gameplay state.
`PresentationSpriteAnchor::BOTTOM_CENTER` is the only supported anchor.

- IDs must be nonempty UTF-8 without NUL and unique within each frame.
- Asset paths must be nonempty relative UTF-8 paths, use forward slashes, and
  contain no NUL, absolute/drive/URI prefix, backslash, or parent-traversal component.
- Coordinates and layers are signed 32-bit integers; off-grid coordinates are valid.
- Display width/height must each be 1-4096 logical pixels, not terminal cells.

The sprite value performs structural checks. Asset-aware providers additionally
use shared Engine validation and fallback diagnostics. The renderer independently
enforces canonical assetRoot containment, symlink checks, PNG decoding and
image-memory limits. PNG content, not a filename extension, is authoritative.

Sprites are stably sorted by ascending layer. Equal layers retain author order,
so later sprites paint above earlier ones. Text and sprites share numeric layer
ordering; text precedes sprites at an equal layer.
For bottom_center, feet are `((x + 0.5) * cellWidth, (y + 1) * cellHeight)`;
the image top-left is feet minus `(width / 2, height)`. GPUI uses logical pixels
(macOS points), applying display scaling itself.

## Shared client and sequencing

Create one `RendererPresentation` per renderer session using the same client and
grid that were used for hello:

```php
$input = new RendererInputSource($client);
// The shared service callback pumps and dispatches feedback, leaving keys for input.
$presentation = new RendererPresentation($client, $grid, $serviceTransport);

// After Console composition; configure tracking before the first draw.
$changes = Console::getRetainedPresentationChanges($excludedSpriteLayerIds);
$queued = $presentation->present($changes, $sprites, viewport: $viewport, world: $world);
```

The presenter borrows the client and never owns gameplay, Console drawing or
shutdown. Large uploads service the shared transport between chunks, through
Runtime's lifecycle callback when attached. They do not create another event
consumer. `present()` rejects dimensions differing from the fixed hello grid.
Standalone callers must likewise dispatch ACKs to `acknowledge()`, handle
rejection/resize and preserve gameplay keys; merely pumping bytes is not enough.

### Nonblocking upload and coalescing

`present()` and `presentCanvas()` return true only when new retained-update bytes
were queued in that call, including intermediate staged packets. False can mean
unchanged content **or** a pending upload deferred by backpressure/ACK credit.
Neither result proves the new scene is visible.

One `RetainedUpload` fixes the transaction being sent across calls. Changes arriving
meanwhile update the sender's desired state, not an ever-growing packet list.
The in-flight transaction finishes atomically; a following transaction reconciles
its baseline with the latest desired text, sprites, canvas, world and viewport.
Intermediate desired versions are coalesced. Continuous changes therefore do not
restart the cold upload forever, and no partial candidate replaces the old scene.

The producer's named limits are independent of the transport's larger capacity:

| `RetainedPresentation` limit | Value |
| --- | --- |
| `CHUNK_BYTES`, target operation bytes per packet | 32 KiB |
| `SEND_BUDGET_BYTES`, target queued bytes per present call | 1 MiB |
| `MAX_SEND_PACKETS`, packet and zero-wait I/O-pass limits per call | 32 each |
| `MAX_PENDING_WRITE_BYTES`, pending-byte high-water mark | 1 MiB |
| `MAX_IN_FLIGHT`, unacknowledged-packet window | 32 |
| `MAX_MESSAGE_BYTES` / `ENVELOPE_RESERVE_BYTES` | 4 MiB / 4 KiB |

Operations are indivisible, so a call may cross its byte target by one packet.
A record larger than the pending high-water mark is admitted only to an empty
queue and must still fit the transport line limit. `trySend(false)` yields with
its operation cursor and generation unchanged. Bounded zero-wait servicing can
continue while writes or ACKs advance; it does not sleep or wait synchronously
for a slow reader. First-use world compilation/chunk preparation is separate from
these I/O budgets, not a guaranteed wall-clock frame-time bound.

`RendererPresentation::hasPendingUpload()` (also on `RetainedPresentation`)
reports staged, coalesced or reset work. Continue ordinary present calls, supplying
current desired state and incremental Console changes, even when that content
has not changed. Runtime already does this at its normal presentation boundary.
Generic tools should service shared input/feedback and resume on later iterations
until pending work clears, with their own deadline/cancellation; do not busy-spin
or inspect private sender state. It may take an extra call to reconcile coalesced
changes that ultimately canceled each other.

An empty upload does not mean the pipe is drained or the final frame acknowledged.
Continue pumping after `hasPendingUpload()` becomes false; `getPendingWriteBytes()`
and `frame_ack` with `presented:true` answer those separate questions. Benchmarks
must complete the cold transaction before measuring warm unchanged/scroll calls.
Once upload/recovery is complete, unchanged desired state queues nothing.

### Retained operations and generations

`RetainedPresentation` stores desired text, sprite, canvas and world state per
session. Stable IDs identify elements; explicit `order` preserves equal-layer
ordering. New elements and changed metadata use `put`; removals use `remove`.
An existing screen text layer sends `textRows` containing only changed rows.
Each listed row replaces that layer's entire sparse row; `runs:[]` removes it.
Unlisted rows and elements remain. Sprites and canvas elements are compared by
value and sent only when changed. Canvas/field transitions remove the previous
surface's elements explicitly.

The native frame envelope is:

```json
{"protocol":2,"type":"frame","frame":1,"baseGeneration":0,"generation":1,"reset":true,"present":true,"operations":[{"op":"put","kind":"text","id":"world","value":{"id":"world","layer":0,"order":0,"runs":[]}}],"viewport":null}
```

`put`/`remove` kinds are `world`, `text`, `sprite`, `canvas`, `canvas_image`,
`canvas_indicator`, `canvas_text` and `canvas_composite`. Row operations are
`textRows`, `worldRows` and `worldTiles`. A `put` replaces that ID's value;
putting a world replaces its metadata and clears its previous rows/tiles.
World rows are uploaded again as part of the same staged transaction.

Generation describes accepted retained state, independently of game ticks and
the `frame` display label. A non-reset update must name the accepted generation
as `baseGeneration` and advance it. A reset ignores a mismatched base but must
still advance beyond the receiver's current generation. The PHP sender increments
generation once per queued packet. A new native session starts from generation 0.

Uploads target 32 KiB operation chunks; a single operation is not split. The
4 MiB NDJSON line limit still applies, reserving `ENVELOPE_RESERVE_BYTES` for the
envelope during chunk preparation. Intermediate
chunks use `present:false`; the last uses `present:true` and atomically replaces
visible state. Until then the old scene remains visible. Viewport changes belong
only to the final chunk. Retained updates allow at most 4096 operations per packet,
64 MiB source state and 128 MiB combined staged/visible source state.

Queue success is not display success. `frame_ack` identifies accepted generation,
frame and whether it was presented. `frame_rejected` carries a diagnostic and
requires resynchronization. The sender retains desired state after a failed send;
a subsequent delivery attempt uses a complete reset, rather than remembering
only the last successful full snapshot. Runtime also resets the Console change
cursor after failure.
An actual send exception still propagates; only temporary capacity pressure is
a nonthrowing deferral.

`invalidate()` abandons unsent staged/coalesced work, preserves current desired
state and requests a reset. Already queued bytes keep their framing and order.
`invalidate(expectedGeneration: $expected)` rebases to the greater of the local
and receiver generations, so the next queued reset advances beyond either.
Runtime uses the highest reported expectation and invalidates once per feedback
drain. `invalidate(newSession:true)` instead starts the new session at generation
0, with its first packet at 1; it does not erase desired presentation.

Runtime forwards ACK progress to Presenter. Partial pipe writes as well as newer
ACKs refresh the monotonic progress deadline. If pending delivery makes neither
kind of progress for `ACK_TIMEOUT_SECONDS` (2.0 seconds), the next present requests
a reset even when content is unchanged, still subject to capacity. A repeated or
pre-reset ACK does not extend the deadline. This catches a dropped final packet
without needing a later rejection and avoids repeatedly filling a blocked pipe.
See [transport feedback](process-transport.md#retained-frame-feedback).

Changes to image bytes behind an unchanged asset path are not detected by
structural duplicate comparison. Asset replacement
does not alter gameplay identity or save data.

### Internal world source budget

The world [bounds and atomicity contract](tile-batches.md#bounds-and-atomicity)
is separate from wire/queue limits. `PresentationWorld::getFromLayers()` checks
its 64 MiB `MAX_SOURCE_BYTES` charge before constructing owner-cell wire arrays,
then includes tile candidates as they are added. The estimate charges 8192 bytes
per layer, 64 bytes plus glyph/owner-ID byte lengths per owner cell, and 16 bytes
per tile candidate. `estimatedSourceBytes` is diagnostic compiled data, not
additional author-maintained map or asset metadata.

These are internal retained-source accounting limits, not total PHP heap, JSON
wire length, decoded PNG memory or proof of native rendering. A map can meet the
1,048,576-cell footprint ceiling yet exceed the source budget. Native validation
independently enforces complete scene and combined staging/visible limits; image
decoding and prepared caches retain their own bounds.

`MapManager` diagnoses a failed world build and, when tile definitions were
present, first retries a glyph-only world. If that is also unavailable, Camera
continues through Console's screen-space retained text rather than suppressing
the field. This preserves gameplay and does not resurrect native V1/stateless
frames. See [runtime field fallback](runtime.md#scene-ownership-and-composition) and the
[world compiler](../../src/Rendering/Presentation/PresentationWorld.php).

## Real frame smoke tool

With a compatible retained renderer build, run:

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
updates changed rows, moves the fixture `@` from (23,7) to (8,7), and removes sprites.
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

The smoke tool is a manual diagnostic, not a statement of platform validation.
