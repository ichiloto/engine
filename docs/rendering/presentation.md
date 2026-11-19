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

## Explicit camera transitions

Protocol v2 negotiates `camera_motion` independently of `field_motion`.
`PresentationViewport` may carry
`cameraMotion: {id: string, duration: number, easing: "linear" | "smoothstep"}`
alongside its required `worldId` and signed `worldOrigin`. The origin is the
logical target. IDs are nonempty, control-free UTF-8 strings of at most 256
bytes; duration is finite seconds greater than zero and at most 60.
`PresentationCameraMotion` and `CameraMotionEasing` own this PHP value contract.

The renderer starts a fresh ID from the origin currently drawn on its field
clock. Repeated IDs retain their world, target, duration and easing. A fresh ID
retargets an interrupted transition. Explicit camera motion takes precedence
over sprite-follow camera motion; sprites retain their own movement. Removing
the descriptor snaps to the declared origin. A first viewport, world replacement
or session reset starts at its declared origin.

`SceneFrameComposer` omits this optional descriptor for unsupported sessions and
reduced-motion settings, preserving the logical origin and selected content.
Follow removal and world-canvas selection preserve the independent descriptor.
Direct presenter submissions carrying it require the negotiated capability.
Retained uploads carry the viewport atomically with the presented frame.

Dialogue framing emits 0.2-second smoothstep corrections and a return transition
when its own lease closes. Explicit positioning, world installation and viewport
replacement clear stale automatic transitions. PHP owns targets and lifecycle;
native display interpolation does not move actors or change collision or saves.
Native integration and exact edge-guidance reprojection are tracked separately
in the existing rendering roadmap.

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

### Owner-scoped asset preparation

The `asset_holds` candidate separates resource intent from visible drawings.
`AssetHoldResource` describes a current asset-relative path, optional pixel crop
and exact base preparation (brightness, flips, rotation and bottom fade).
Placement, opacity and colour treatment remain drawing state. No authored hash
or duplicated image size is introduced.

The shared client's `assetHolds` registry combines weak owner scopes. Replacing
one scope cannot release another scope. Each changed preparation has a fresh
runtime lease id and monotonically increasing revision. A frozen frame borrows
the same immutable requests before its producer releases them; it does not
allocate duplicate wire leases. A retired request cannot be revived by replaying
an obsolete frame. The desired session set is limited to 1024 unique leases.

The retained sender diffs `asset_hold` values alongside other entities. Their
wire values contain neither a drawable id nor draw order. Resource-only traffic
keeps the last scene intact and proceeds even when scene drawing readiness is
held. Captured frames preserve resource intent; presentation and screen-handoff
owners retain old references through covered replacement and release them on
completion or shutdown. A new renderer session replays desired requests but
requires fresh residency feedback.

`AssetHoldScope` owns one presentation’s ongoing resource intent. It can replace
or atomically merge newly known resources, keeps existing role requests stable,
and releases them idempotently. A released scope refuses acquisition.
`AssetHoldPreparation` captures an immutable initial readiness snapshot inside
that scope. Its host supplies a
monotonic start and timeout. Polling performs no I/O, sleep or clock advancement.
It distinguishes waiting, ready, refused, unsupported, timed out, superseded and
cancelled. A completed readiness decision retains its resources until explicit
release; abandoning the preparation drops weak ownership.

Semantic action focus owns an `AssetHoldPrefetch`, driven by selection state rather than composition. It shares actual live scene requests and promotes matching IDs into required command scope. Competitors release before required admission. Refused speculation releases immediately and is retried only after a semantic change or explicit clear. Scene requests remain independent through cancellation, pause and command disposal. Cinematic return descriptors retain drawing size and canvas, so unchanged battlers reuse scene preparations.

A display-size resource adds paired typed `drawSize` and `canvas` values.
`drawSize` has finite positive width/height in logical units up to16384;
`canvas` declares incoming logical width/height integers1..16384 and `native` or
`contain` viewport fitting. It is independent of the retained outgoing canvas.
The Engine declares each authored cell using the same calibration as drawing,
without placing future travel poses at a resting anchor. Party/reserve resources
include each initial frontline place. Variable-width contact cells and nine-slice
UI retain their full-size crop meaning.

Native owns viewport/backing scale. It prepares ceil(drawSize * deviceScale)
per axis; if either reaches the master crop extent, it retains the original crop
and the GPU scales that image at the declared logical extent. No enlarged bitmap
is allocated. `held` means the current-scale image is resident; resize can prepare
a new copy. Masters are temporary worker staging under the same shared budget.

The battle command consumer prepares its first actually sampled image cohort and
known return battler resources before entry cues. Semantic focus prepares the
first normal or reduced-motion cohort of the selected action. Scene-hot basic
attacks retain their first normal and reduced-motion cohorts. This removes full
future effect timelines from scene, focus and required command residency.

`BattleCommandResourceStream` follows the production playback sampler, including
composed stages, reduced-motion holds, flight timing and actual result effects.
`AssetHoldWindow` retains the current cohort and one incoming cohort. A ready
lookahead remains speculative until its command boundary is entered. Outgoing
resources release only after the incoming cohort is ready; exact requests shared
with the scene, paused frame or other owners remain live under those owners.

Image commands use feedback-only readiness with no deadline. While an incoming
cohort waits, the existing scene readiness gate keeps the last resident picture
and sends resource-only updates. The command clock and cues stop at the safe
boundary. Time spent waiting never becomes catch-up time. Explicit refusal,
unsupported feedback, supersession and cancellation use the renderer error path;
the image command cannot continue through a timeout or foreground fallback.
Current readiness is re-read after a changed-file attempt. Playback's entered
frame aligns elapsed time before dispatching its cues, so the first result
picture and its resource decision share one boundary.

Actual impact/result paths commit outcomes before collecting newly known return
roles and result effects. Inspection samples neither dispatch cues nor resolve
combat. Warm large updates keep their existing elapsed-time behavior. Pause uses
its owned frozen frame; cancellation, completion and disposal release the
command window. Terminal and unsubscribed hosts retain their existing paths;
return-only preparation keeps its finite legacy deadline.

Battle entry has a separate readiness policy. Before the scene becomes drawable,
`BattleScene` declares the arena, all participant and reserve role resources,
first basic-attack cohorts, the theme's contact-effect layers, HUD, pause, results
portraits for the current party, and registered control artwork. The owning
skin/catalogue APIs supply paths before optional presentation preparation can
discard unavailable bindings. The scene's initial `AssetHoldPreparation` has a
null deadline. Slow preparation retains the covered outgoing frame; it neither
composes an incoming battle canvas nor starts combat. This removes the earlier
entry deadline and foreground-on-failure path. An explicit missing, corrupt or
refused resource or renderer failure uses the renderer-session error route and
releases only that battle's scope. Readiness is re-read after initial entry,
including a changed-file attempt. Terminal and unsubscribed renderers keep their
established path.

Exact master-pixel bounds and lower-edge facts use `PngAssetFactsCache`. A host may
inject a dedicated absolute storage directory through `PngAssetBounds::setFactsCache`;
the default is the platform user-cache directory under Ichiloto/asset-facts/v1.
Bounded non-executable JSON records key the canonical source path, crop, schema and
current content revision. They store generated facts rather than authored identity,
physical occupancy or pivots. Fresh processes reuse them without decoding PNGs;
missing/stale/corrupt/unwritable records regenerate the same exact facts with a
diagnostic when storage fails. Atomic writes, symlink refusal, a128KiB record bound,
2048records and64MiB total bound keep this disposable store separate from project
assets, settings and saves. Source files remain read-only. The native renderer
owns display-size copies, device scale, decoded/GPU lifetime and memory admission.

After combat and the current tick’s reaction feedback commit, the command merges
actual outcome resources into that same live scope. Deferred reaction beats also
trigger a merge at their real presentation boundary. This samples current and
first-return poses through the existing projector, retaining initial resources
and once-only combat/cue timing. Resource refusal is diagnosed after gameplay
resolution. No second readiness wait changes authored timing.

Base transforms absent from the declared set still use foreground preparation.
A declared hold guarantees its requested guarded crop/base or current-scale
copy before readiness. It does not promise a decoded master or total GPU
residency. Native owns decoded staging, display copies, foreground preparation,
conversion buffers and evictable cache through one complete image ledger.
`AssetHoldCapacity` reads aggregate bytes/count and four disjoint owners: held,
foreground, staging and cache. Only staging reports an additional byte sublimit;
that limit is never added to current usage. Totals must equal checked owner sums.
Truthful usage above a newly lowered limit is valid observation; new admission
still has to fit. The separate-store `AssetHoldStoreCapacity` model is removed.
The candidate fixture is version5; its32MiB example is synthetic. A production
numeric ceiling and paired native working-set qualification remain open; the
shared-channel fixture/proposal await the reconciled revision7 seal.

PHP validates paths, current headers/crops, per-image generic limits and aggregate
source/region counts for every session. For negotiated `asset_holds`, aggregate
live image bytes belong to the native allocator; this removes the duplicate
PHP64MiB aggregate check for that capability. Unsubscribed sessions retain the
legacy source/region byte limits and measured diagnostics. Formation clearance
and action travel planning use the shared battler projector's current body
geometry without composing a full frame, effects or HUD. Route planning batches
current visible-body PNG facts for the active frontline and troop. It preserves
logical ground anchors, target-facing travel checks and phase-aware pose
clearance. This removes full-frame allocation admission from route planning;
canvas drawing retains its negotiated or legacy allocation policy.

Battler pose calibration, source rectangles, pivots and logical ground geometry
are independent of the viewport. The shared projector retains the whole pose
for travel, captures and named attachment points, then clips its painted view
through the existing full-source image/rotation-zero/parent-clip contract.
Formation previews carry that same clip and pivot through uniform menu scaling.
This removes viewport rejection and resting-art fallback for otherwise valid
poses that overhang, and removes viewport clamping that moved their authored
anchors. Unsupported, missing and corrupt artwork retains its diagnostics.
Ground elevation also preserves the logical body position; a wholly submerged
body is hidden, removing the artificial one-pixel strip. Ground contact has its
own painting visibility: runtime and previews retain an on-screen ground shadow
or surface ripple when the body lies entirely outside the viewport. This removes
body-visibility gating of ground-contact artwork and composites. Contact geometry
uses the same whole logical artwork and moving ground anchor; defeat opacity and
travel's resting contact shape remain shared. Base formation validation and
renderer resource/admission rules remain in force.

Feedback remains bounded to1024 status entries and each refusal message to1024 UTF-8 bytes. Coalesced source attempts may
skip un-emitted ended/pending history; a newer attempt is accepted directly and
late older results remain ignored. Legacy ordered transitions within the same
bounds remain readable.

Every desired resource set must fit a complete reset:4096 operations and4 MiB of
canonical typed-operation JSON. Admission counts the aggregate across owners and
frozen-frame borrowers before changing ownership or consuming revisions. The
low-level retained sender enforces the same boundary before changing desired
state or queuing bytes. A metadata-heavy set that exceeds it is refused with
`asset_hold transaction_limit`, preserving earlier resources. Existing individual
path rules stay unchanged. Canonical byte counts follow the shared wire schema,
including binary64 fraction lengths; they do not change locale or PHP settings.

Native pairing, readiness before first use of newly resolved resources and player verification
remain open in the [integration roadmap](integration-roadmap.md#asset-lifetimes-and-command-readiness).

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

Delivery is also bounded by whole frames. While the renderer has not acknowledged
`MAX_FRAMES_IN_FLIGHT` completely queued frames, a present call queues nothing:
changes stay desired state, and the first call after an acknowledgement sends one
frame holding only their net difference from the last delivered frame. Superseded
intermediate frames are never queued, so the frame answering a key press waits
behind at most that many frames, however slow the renderer is. Responsiveness takes
priority over showing every sampled animation step. A reset after rejection,
resize, timeout or a new session is never held.

The producer's named limits are independent of the transport's larger capacity:

| `RetainedPresentation` limit | Value |
| --- | --- |
| `CHUNK_BYTES`, target operation bytes per packet | 32 KiB |
| `SEND_BUDGET_BYTES`, target queued bytes per present call | 1 MiB |
| `MAX_SEND_PACKETS`, packet and zero-wait I/O-pass limits per call | 32 each |
| `MAX_PENDING_WRITE_BYTES`, pending-byte high-water mark | 1 MiB |
| `MAX_IN_FLIGHT`, unacknowledged-packet window | 32 |
| `MAX_FRAMES_IN_FLIGHT`, unacknowledged complete frames | 2 |
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
ordering. Orders are sparse ranks, relative within one order space (each canvas
kind, text layers, and sprites with field canvases together). A surviving element
keeps its rank when another is inserted, removed or moved, so only the changed
element is sent. New elements and changed metadata use `put`; removals use `remove`.
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


### Pixel-valued composite source views

Composite image operations and image-alpha masks may select `sourcePixels: {x, y, width, height}`. These finite pixel-valued rectangles preserve integer atlas-cell boundaries and allow fractional destination clipping. Origins are nonnegative, extents are positive, and admission checks the rectangle against the current PNG dimensions. `source` and `sourcePixels` are mutually exclusive. Legacy normalized `source` retains its existing meaning.

`canvas_source_pixels` is an optional protocol2 drawing capability requiring `graphical_canvas` and `canvas_compositing`. The Engine emits pixel-valued tint masks inside a supporting host’s existing `PresentationSession` scope. Older hosts retain normalized tint emission; the presenter records one precision diagnostic per live session when normalized crops are used. They retain their existing crop precision, which cannot guarantee atlas-cell isolation. An explicitly pixel-valued descriptor is rejected before sending to an unsupported renderer, including masks nested inside displacement grids.

Sampling clamps bilinear taps to `[floor(x), ceil(x + width) - 1]` and the corresponding vertical interval in pixel space; flips mirror within the selected interval. Native and Editor samplers own this operation. A prepared source view may use the current whole decoded image or an already-held guarded region with an explicit logical origin and stride. Whole-source and cold-work admission bounds remain independently enforced. The shared synthetic fixture is `tests/Fixtures/Renderer/pixel-sources-wire-fixture.json`; paired native/Editor backing-equivalence qualification remains required before normal integration.
