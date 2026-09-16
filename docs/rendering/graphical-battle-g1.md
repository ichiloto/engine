# G1 Graphical Canvas And Battle Foundation

Status: the graphical battle, native UI and cursor correction are accepted and
integrated into the normal local Game. This is the current contract and validation
record; superseded temporary-build instructions are archived, not maintained.
The local integration is not a published release.

## Ownership And Scope

PHP owns the logical canvas, resolved image rectangles, source pivots, battle
instance identities, targeting and feedback lifetime. Rust validates and draws
immutable intent. Combat resolution, RNG, scheduling, rewards and saves remain
the existing PHP systems. No paint acknowledgement or extra action delay is added.

Engine owns PHP and this contract. Renderer owns native drawing and the canonical
wire fixtures; Engine consumes byte-identical copies with hashes recorded below.
Game owns approved metadata/assets and
the legitimate acceptance encounter. Art owns all game images. Synthetic fixtures
are geometry tests, not approved game artwork. Private encounter/lore decisions
remain in the private Game documentation; they are not copied into this contract.

T1 is merged at `d0c4f0c311234556b44dd27f99ed6fa0c5841999`. Its Console/Camera
ownership overlap is closed. G1 uses the existing rendering client, input owner
and shutdown lifecycle; it does not introduce another Console buffer or transport.

## Negotiated Envelope

Add the v2 capability `graphical_canvas`. Request it in hello and require ready
acknowledgement before sending `canvas`, including an empty drawing list. v1
rejects this capability and field. Existing sprite x/y remain grid coordinates;
their meaning and `tile_batches` behaviour are unchanged. Cropped canvas images
also require the existing `sprite_source_rect` capability.

The v2 frame still has `frame`, `textLayers` and `sprites`. A present `canvas`
requires empty legacy text/sprite/tile collections; optional legacy fields may
remain omitted. Omitted `canvas` clears the previous canvas and resumes legacy
presentation. Explicit `canvas: null` is invalid.

| Canvas field | Contract |
| --- | --- |
| `width`, `height` | Required unsigned JSON integers in 1..16384, independent of the hello grid. |
| `images` | Up to 1024 images. |
| `indicators` | Up to 2048 indicators. |
| `textLayers` | Up to 64 local text layers, 32768 aggregate runs and 524288 aggregate Unicode scalars. |

Drawing lists may be omitted or empty; either clears that collection. Null or
non-array lists are invalid. A dimensioned canvas with no lists is a blank canvas;
an empty object is invalid. All structures reject unknown fields and wrong types.
The PHP outbound model emits all three lists and omits absent optional values.

## Images And Indicators

Image fields: `id`, `asset`, `destination`, signed i32 `layer`, optional
`sourceRect`, optional `opacity`. Opacity defaults to 1 and must be finite in
0..1; explicit null is invalid. A zero-opacity image does not imply removal,
knockout, targetability or any other gameplay state.

`destination` and indicator `bounds` are `{x,y,width,height}` in graphical units.
Accept finite JSON numbers with nonnegative origins and positive extents, wholly
inside the canvas. Reject nonfinite values and overflowing right/bottom sums.
Preserve fractional coordinates. Values such as `(969.25,265.5,143,181)` are
valid without cell snapping. These are resolved top-left rectangles, not pivots.

PHP resolves authored source pivots and aspect-preserving contain-fit placement
before submission. Source rectangles remain image pixels under the existing u32
crop contract. Rust does not trim transparent borders, infer feet, apply another
contain-fit calculation or measure ASCII art.

Indicator fields: `id`, `imageId`, `kind`, `bounds`, `strokeWidth`, `color`, i32
`layer`. `imageId` must identify an image in the same snapshot. `kind` is
`outline` or `underline`. Strokes are integer 1..16, no larger than either bound
dimension, drawn inside the bounds. Underline fills the inside bottom strip.
`color` is a required nonnull existing ColorSpec. Selection and acting state can
use different shapes on the same image; native code does not choose targets.

IDs are nonempty UTF-8 without control characters, at most 256 bytes and unique
within their collection. Repeated enemy definitions require different stable
battle-instance IDs. List reordering and participant removal must not renumber a
survivor. The eventual battle adapter must bind existing PHP instances/results;
the generic canvas model does not manufacture battle identities.

Assets are relative UTF-8 paths of at most 4096 bytes. Existing confinement,
traversal, symlink, PNG and source-image crop checks remain mandatory. The PHP
values validate structure; native asset preparation validates actual image data.

## Local Text And Composition

Canvas text fields: `id`, `origin: {x,y}`, `grid`, `runs`, i32 `layer`. Origin
uses finite graphical units; grid uses existing positive integer Grid limits.
The complete local grid must fit inside the canvas. Styled runs retain their
existing required row/column/text/foreground/background shape and must fit that
local grid. Only text is quantized to these explicit local cells.

In a canvas, null foreground selects the existing default foreground and null
background is transparent. An explicit ColorSpec paints the cell, including a
space. Compatibility windows must explicitly supply an opaque background;
feedback may remain transparent. There is no new default-color tag. Legacy text
null-background semantics are unchanged. Font metrics use each local cell size.

Sort by ascending layer; equal-layer order is images, indicators, then text.
Within each collection preserve incoming order for equal layers. The arena is
an ordinary low-layer image, not a renderer-specific battle object.

Uniformly fit the complete canvas to the native viewport with the current
scale-at-most-one policy. Center and letterbox, clip children to the same surface
and clear the viewport background. Images, indicators and local text share that
transform. Resize never changes Console dimensions, logical canvas or combat.

## Replacement, Failure And Budgets

Each frame is a complete replacement. Prepare it fully before atomic adoption;
invalid frames must not leave a partially replaced display. Returning to a field
sends a complete legacy frame without canvas. A battle modal that keeps its arena
must submit that arena and participants again with its modal layer.

Retain the 4 MiB line limit, 1024 source-image / 64 MiB decoded-image cache and
per-frame decoded budget, 4096x4096 PNG dimension limit and 16 MiB encoded limit.
The prepared-region cache retains its 4096-region / 64 MiB bounds. No image is
decoded or cropped anew on every paint. Keep stdout protocol-only and diagnostics
on stderr. Resource counts apply to the whole accepted frame.

Failed PHP submission does not consume a sequence number or replace deduplication
state. Native rejection before frame adoption is not a paint acknowledgement or
rollback of combat effects. Configured battle metadata must be checked before
battle entry; stronger asset preflight guarantees must be implemented explicitly,
not claimed from asynchronous drawing. No battle completion depends on painting.

## PHP Boundary

`PresentationCanvas`, `CanvasImage`, `CanvasRectangle`, `CanvasIndicator` and
`CanvasTextLayer` live under `Rendering/Presentation/Canvas`. They are immutable,
detach array references and enforce frame bounds, references and aggregate costs.
`RendererPresentation::presentCanvas()` accepts no Console snapshot and shares
the existing transactional frame queue. Ordinary `present()` clears the canvas.

The production boundary is now `BattleScene` implementing `CanvasProviderInterface`.
`RendererRuntime` selects its canvas before field sprite/tile/Console composition.
The existing battle intro remains the legacy transition; subsequent battle frames
use the canvas. Returning to another scene sends an ordinary replacement frame.

## Project Metadata API

The optional `assets/Data/battle-presentation.php` returns a typed
`Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog`. It is loaded
from current project configuration when a graphical battle is configured, not
stored in `BattleConfig`, actor data or save payloads. Native Terminal never
loads this file or inspects the PNGs. Ordinary GPUI selection requires
`graphical_canvas` in addition to its existing capabilities only when the catalog
exists. Its optional `ui: BattleCanvasLayout` supplies the shared skin and canvas
geometry for every battle, independently of arena or combatant artwork. Without
an arena entry, the existing owned battlefield is drawn below the native HUD;
missing artwork does not revert the controls to terminal windows. Only a missing
catalog, or an encounter with neither an arena nor a shared UI, keeps the entire
battle on the legacy path. A malformed declared catalog fails explicitly.

Example structure (generic geometry and placeholder paths, not game artwork):

```php
<?php
declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\{BattleArenaDefinition, BattleCanvasLayout, BattlePresentationCatalog, BattlerArtwork, BattlerSlot};
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasImage, CanvasRectangle};
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

return new BattlePresentationCatalog(
  arenas: [
    'existing-troop-id' => new BattleArenaDefinition(
      width: 1350,
      height: 720,
      background: new CanvasImage('arena', 'Graphics/Battle/arena.png', new CanvasRectangle(0, 0, 1350, 720)),
      partySlots: [new BattlerSlot(969, 265, 143, 181)],
      enemySlots: [new BattlerSlot(375, 467, 173, 197)],
    ),
  ],
  actors: [
    'existing-actor-id' => new BattlerArtwork('Graphics/Battle/hero.png', 143, 181, 71.5, 181),
  ],
  enemies: [
    'Existing Enemy Catalog Key' => new BattlerArtwork(
      'Graphics/Battle/enemy.png', 197, 119, 98.5, 119,
      new SpriteSourceRect(5, 7, 197, 119),
    ),
  ],
  ui: new BattleCanvasLayout(1350, 720,
    skin: require __DIR__ . '/battle-ui.php',
    feedbackArea: new CanvasRectangle(0, 80, 1350, 452)),
);
```

The shared UI requires an explicit skin and feedback safe area. An authored arena
inherits that skin and safe area unless it supplies its own; inherited geometry
is validated against the arena, never silently resized. `BattleArenaDefinition`
extends the shared layout while retaining its existing constructor API. Shared
skin PNGs and negotiated capabilities are checked before battle-entry effects,
including encounters without graphical arena metadata.

An optional `battleArena` key in a map's `encounters` block or a scripted
`start_battle` command selects an entry in the catalog's `arenas`. Direct callers
can pass the same key through `SceneManager::loadBattleScene`'s `extraSettings`.
Use this for location-specific backgrounds: the same troop can appear in several
settings. Arena entries may reuse one background PNG with different formations.
The binding belongs to this encounter, not global or inferred map state; map
encounter reconfiguration clears any previous binding. An explicit invalid or
missing arena/catalog fails before native battle-entry effects, never silently
falling back. Terminal play ignores this optional presentation metadata.

Without a binding, arena lookup uses `Troop::definitionId`, falling back to its
historical catalog name only when no authored ID exists. Actor lookup uses `Character::actorId`.
Enemy lookup uses the current `EnemyStore` name key. These are definition lookup
keys, not presentation instance identities. Repeated enemy objects have separate
`combatant-<spl_object_id>` IDs held for this battle's lifetime; no RNG or save
identity is added. Targets and feedback refer to the actual PHP object.

`BattlerArtwork` width/height and pivot are source pixels relative to its crop,
or the whole PNG when no crop is supplied. Dimensions must match that selected
source exactly. `BattlerSlot(x, y, width, height)` gives the graphical pivot
destination and maximum contain dimensions. PHP uniformly scales the selected
source to those limits, then subtracts the scaled pivot to resolve the destination
rectangle. No snapping to terminal cells occurs. Art-supplied shadows belong to
the image and receive no extra runtime shadow.

Supply party slots for the configured active formation and enemy slots in the
troop's actual member order. The existing Party reserve fallback remains live:
all roster images and their possible party-slot placements are preflighted, but
only the actual frontline is drawn. Enemy removal never renumbers a surviving
instance's authored slot. All configured participants must have art; individual
ASCII fallback within an explicitly graphical arena is prohibited. Missing files, escaping symlinks, non-PNG headers,
oversized PNGs, mismatched dimensions/crops, invalid placement and source budgets
fail before battle-entry effects. This PHP preflight reads headers without PNG
decoding. Native full decoding and atomic image preparation remain asynchronous;
header preflight does not claim to validate compressed pixel data or await paint.

## Unskinned Compatibility UI

`BattleCanvasUiAdapter` reads only visible `BattleCommandWindow`,
`BattleCommandContextWindow`, `BattleCharacterNameWindow`,
`BattleCharacterStatusWindow`, `BattleMessageWindow` and `BattleResultWindow`
footprints from the canonical Console snapshot. Explicit named Console overlays
retain their ownership and order. Pause state supplies its existing label.
Unowned base cells, battlefield borders and terminal battler glyphs are not
admitted. `BattleFieldWindow` selects the graphical path before constructing
terminal battlers, selection art, damage placement or deferred G2/G3 animation
art. The existing resolver, input, resource costs and timing remain unchanged.

Only this compatibility adapter uses a local 135x36 grid, centered in the canvas,
with explicitly configured integer cell metrics (default 10x20). The grid must
fit the canvas. The four command/status windows occupy its bottom six rows
(default y=600..720); the action banner is the existing x=2, y=1, width=131,
height=3 window (default y=20..80). Result windows retain their measured centered
footprints. Authored safe areas must cover those real footprints. Window cells
have an explicit opaque dark background; cells outside windows are absent.
Arena and battler coordinates are independent of this temporary grid.

Focused/queued target instances have an outline and target-name label. The
existing forward/back presentation calls identify the acting instance with a
distinct underline without adding movement or delays. Party KO is dimmed with a
KO label. Defeated enemies remain through their existing popup hold, then are
removed. `BattleFieldWindow` retains recipient, sequence and existing formatted
result lines; graphical placement uses the recipient's image bounds, not ASCII
placement or damage-string parsing. Clearing the existing popup also clears its
canvas feedback. Static poses and these text adapters are G1, not G2/G3 or a
completed graphical UI.

Target names and all current result lines for one participant form one ordered
text block. `BattleFeedbackPlacement` positions that block around the image,
avoiding actual opaque UI cells rather than assuming a fixed safe-area margin.
This includes the top party slot taking damage or healing while the action banner
is visible. If a deliberate full-screen modal covers the whole canvas, its normal
occlusion authority remains; feedback is not moved above the modal's layer.

## Native Battle UI

An optional project-owned `BattleUiSkin` uses read-only snapshots of existing
command, context, name, status and message windows. It projects current values,
affordability, paging and selection without choosing commands or calculating
combat. Hidden windows disappear in the next complete canvas. Terminal windows
and unskinned G1 retain their existing paths. For a UI-only battle, the existing
field window has its own canvas text layer at layer 0; skinned controls remain
above it and explicit modals/results above those. Legacy footer cells are not
readmitted over the native controls. Fully illustrated battles still suppress
terminal battler drawing before it reaches Console.

Nine-slice panels preserve corners. Gauges clip their full-width fill rather
than squeezing it. Party names and resource rows share baselines; ATB remains
conditional. HP/MP values are right-aligned in columns sized for both current and
maximum values, so losing a digit does not move the gauges. Invalid resource
maxima show a dash rather than a fabricated percentage. Multiline messages use
their actual viewport; snapshots retain full source text.

Result formatters expose typed visual roles, sequence, monotonic show time and
existing hold duration. PHP projects grouped rise/fade during timer-pumped
redraws without another wait, gameplay clock, RNG draw or save field. Names and
KO labels remain stationary. Reduced motion suppresses travel. Placement uses
the recipient's upper body with a small side gap, avoiding other battlers,
labels, complete animation bounds and opaque UI. The original formatter retains
ownership of text, order, aggregation and clearing.

Two optional v2 capabilities extend graphical canvas drawing:

- `canvas_clip_opacity`: image/text `clipRect` in absolute canvas units and text
  `opacity`. Clipping does not alter original geometry or source crops.
- `canvas_glyph_effects`: text `glyphEffects` with typed outline/shadow colors
  and bounded widths, offsets, blur and opacity. Expanded paint bounds must fit
  the canvas even when clipped or transparent; the local text grid is unchanged.

Both require `graphical_canvas`; source rectangles retain their separate
capability. Unsupported effects fail before enqueue, not through offset-label
or baked-number fallbacks. Renderer owns font contours and the shared bounded
display-raster pool. Andrew approved exact direct dependencies
`cosmic-text 0.14.2` and `swash 0.2.10`, retaining GPUI 0.2.2 and system fonts.
System-font variation remains explicit; the cache budget is not a whole-process
memory ceiling.

`BattleUiSkin::targetCursor` accepts `BattleTargetCursor` metadata for above,
left and right inward-pointing textures, placement, size and gap. PHP uses the
menu oscillation policy and respects reduced motion. The complete motion
envelope is reserved. An opaque action banner can move a cursor to a clear side;
a modal covering all placements temporarily hides it. Invalid off-canvas
formations still fail preflight. Old skins keep their appearance. Cursor images
add no protocol extension or extra text layers.

Active-actor, selected-target and queued-target ownership remain distinct.
Andrew accepted the active actor underline for this testing slice only. The
finished presentation must use an above-head actor cursor and animated target
cursors; additional bounce/spin polish is future scoped work. Reference videos
and screenshots are not assets to copy into the game.

## Current Integration

The accepted build is integrated into the normal local Game, not a separate
playtest runtime. Its production `assets/Data/battle-presentation.php` references
`assets/Data/battle-ui.php`; production must never load test-fixture metadata.
Use the existing `ichiloto play --renderer=gpui` entry point from the normal
Game checkout. Native Terminal remains available through the same command with
`--renderer=terminal`. No temporary game copy or renderer path override is needed.
The shared native controls apply to every authored encounter. Approved PNG
arena/combatant coverage remains separate; other fields retain their existing
ASCII artwork until their art and placement metadata are ready. Recurring troops
can appear in different locations, so authored `battleArena` bindings select
their setting rather than assuming one background per troop. Art production runs in
parallel with Engine and story work, not after them.

The canonical macOS package contains the already validated optimized executable
SHA-256 `8f946ccac39d1f6aa50e1edb4712300197ad6bfcea361b221dac060f3a52bbc5`.
Its normal application identity and Engine installation manifest are preserved.
Generated binaries are ignored, not committed. This is a local integration,
not a published release or completed cross-platform distribution.

Game retains asset hashes and provenance in its existing admission receipts.
The ten UI textures and three cursor textures were approved with the disclosed
unavailable-tool and missing-explicit-licence-metadata exceptions; no licence
grant is invented. Portraits are not admitted by this scope. Original art masters
remain separate from generated runtime copies.

## Validation And Limits

Shared frozen fixture directories under `tests/Fixtures/Renderer/` are copied
byte-identically from Renderer and verified by their `SHA256SUMS`:

- `graphical-canvas/`: 139 cases; manifest
  `1cc3acd92570105e13eced9d4fc9d81dcea004dc666d8331aca5ad2897635790`.
- `canvas-clip-opacity/`: 75 cases.
- `canvas-glyph-effects/`: 90 cases; manifest
  `4d37e341ced397fba4fb37d180ab3ae6804d59722d612c9cf1e4294fa266e524`.

Only harness asset roots are substituted. PHP compares its outbound models and
negotiation failures; strict inbound JSON/filesystem rejection remains the
Renderer boundary, not a duplicate Engine decoder. Change frozen corpora only
through coordinated re-freezing.

Latest full Engine verification, including encounter-specific arena selection:
PHP 8.4 and 8.5 each passed 2097 tests / 10827 assertions, with one existing skip.
Focused presentation/event-continuation checks passed 103 / 756, including arena
selection and reset between maps, invalid-key rejection, configuration round trips,
and terminal isolation. The earlier shared-UI presentation/HUD checks passed
43 / 302 for skin inheritance, layer ownership and pre-entry failures.
Full-source PHPStan passed. Renderer recorded 106 optimized tests and 32 separate
actual-size/density admission cases; those are not gameplay FPS measurements.

Game's bounded suite passed 293 tests / 561205 assertions, excluding battle
simulations. Production-binding checks passed 11 / 392 after integration, plus
3 / 41 for the accepted bedside correction. The all-battle shared-UI change
passes 22 Game presentation tests / 599 assertions, including all 11 authored
troops, preserved PNGs for the illustrated encounter and the existing field art
below native controls elsewhere. Earlier isolated-runtime execution
initially failed two source-unskinned catalogue assertions (9 passed); the
appropriate runtime filter passed 5 / 231. Those historical failures were not
presented as successful full-suite coverage.

Native macOS acceptance covered the ordinary saved Service Road Vermin encounter:
HUD/menu navigation, rat-to-bat targeting, turn progression, outlined
`CRITICAL / 40 / KO` feedback, victory (50 EXP / 200 G), field/dialogue return and
normal shutdown. The correction check showed Cure `+32` beside Kaelion's upper
body, HP 108 -> 140 and Liora MP 18 -> 15, right-aligned values and an oscillating
target cursor with banner-side fallback. No save, stats or RNG manipulation was
used. These observations cover one encounter, not every action/status outcome.
Terminal fallback/random parity has automated coverage, not a new interactive
terminal playthrough.

Superseded runtime copies and handoff variants were removed after acceptance.
Small save/config backups and relevant historical evidence are archived locally;
Git history retains superseded tracked documentation. This document is the
current contract and integration record, not the old temporary launch guides.

The broad Last Legend suite was not completed because of the unrelated
180,000-battle simulation baseline. Linux/WSLg and native Windows remain untested.
The dark-player-on-dark-field issue is an art/background concern. Untimed
`TurnResolutionState` status-tick feedback still needs owner-lifetime follow-up;
do not invent a gameplay delay. G2/G3 and distribution remain separate backlog.

The earlier output-only slice made no renderer protocol or game-content changes.
This UI slice adds the explicit optional capabilities above but no gameplay
content; the result-placement/cursor correction adds neither another protocol
extension nor gameplay changes. Making that UI project-wide adds no renderer
protocol, native binary or gameplay-content changes; its new coverage is automated,
not a fresh interactive playthrough of every encounter. Automated native launches must verify music and
SFX are muted; Andrew's applied mute must be preserved.
