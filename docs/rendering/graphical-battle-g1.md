# G1 Graphical Canvas And Battle Foundation

This document describes the shared graphical canvas, battle presentation and
project metadata contracts. Graphical presentation never owns combat outcomes.

## Ownership And Scope

PHP owns the logical canvas, resolved image rectangles, source pivots, battle
instance identities, targeting and feedback lifetime. Rust validates and draws
immutable intent. Combat resolution, RNG, scheduling, rewards and saves remain
the existing PHP systems. No paint acknowledgement or extra action delay is added.

Engine owns PHP and this contract. Renderer owns native drawing and the canonical
wire fixtures; Engine consumes byte-identical copies with hashes recorded below.
Games own metadata and artwork. Synthetic fixtures exercise geometry and protocol
limits without defining a game's art or encounter design. G1 reuses the existing
rendering client, input owner and shutdown lifecycle.

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

The optional `assets/Data/Presentation/battle.php` returns a typed
`Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog`. It is loaded
from current project configuration when a graphical battle is configured, not
stored in `BattleConfig`, actor data or save payloads. Native Terminal never
loads this file or inspects the PNGs. Graphical battle surfaces require
negotiated `graphical_canvas` support; optional presentation metadata does not
make it a game-startup requirement. Its optional `ui: BattleCanvasLayout`
supplies the shared skin and canvas
geometry for every battle, independently of arena or combatant artwork. Without
an arena entry, the existing owned battlefield is drawn below the native HUD;
missing artwork does not revert the controls to terminal windows. Only a missing
catalog, or an encounter with neither an arena nor a shared UI, keeps the entire
battle on the legacy path. A malformed declared catalog is diagnosed and the
battle uses the terminal presentation.

Artwork changes throughout development, and the engine treats the image on
disk as the moment's truth. Character identity never depends on an image's
contents, checksum or a previous revision's dimensions. Supported format,
decoding, path safety and the generic resource limits above remain enforced;
recommended authoring sizes are not exact-image acceptance gates. Supplying
appropriately composed artwork is the developer's responsibility. Whole-image
battlers use `BattlerArtwork::getFromPng($assetRoot, $asset, $pivotX, $pivotY)`.
The optional pivots are normalized fractions, defaulting to centre-bottom.
The factory reads current dimensions; replacing a PNG preserves normalized pivot
intent and uses the entire image, including its feet and shadow. Only genuine
atlas regions should author a `SpriteSourceRect`. Legacy crops reconcile to
current bounds with diagnostics. Missing or invalid optional battler art uses
a local readable fallback without disabling other participants or the UI.
An unusable surface is diagnosed and falls back to terminal presentation;
presentation never decides whether combat happens.

For whole-file UI textures, use `CanvasNineSlice::fromPng($assetRoot, $path, ...)`
with any intended border cuts, density and destination minimums. It reads the
current source dimensions instead of repeating them in the catalog. Explicit
atlas regions continue to use the constructor with a `SpriteSourceRect`.
Results preparation resolves portrait/icon families as current whole images,
then contains them in their existing display slots; old source dimensions do
not reject replacements. Frame budgets still apply to the actual decoded files.

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
    'existing-actor-id' => BattlerArtwork::getFromPng(dirname(__DIR__, 2), 'Graphics/Battle/hero.png'),
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
missing arena/catalog is diagnosed before battle-entry effects, and combat
continues with the terminal presentation. Terminal play ignores this optional
presentation metadata.

Without a binding, arena lookup uses `Troop::definitionId`, falling back to its
historical catalog name only when no authored ID exists. Actor lookup uses `Character::actorId`.
Enemy lookup uses the current `EnemyStore` name key. These are definition lookup
keys, not presentation instance identities. Repeated enemy objects have separate
`combatant-<spl_object_id>` IDs held for this battle's lifetime; no RNG or save
identity is added. Targets and feedback refer to the actual PHP object.

### Troop Formations Within An Arena

`BattleArenaDefinition` optionally accepts `enemySlotsByTroop`, mapping troop
definition IDs to complete ordered lists of `BattlerSlot`. This keeps location
selection separate from enemy formation: the map/event still selects its arena,
then that arena resolves an override for the current troop. Historical troop
names are used only when the troop has no authored definition ID. For example,
an arena can retain its existing `enemySlots` and add:

```php
enemySlotsByTroop: [
  'troop.mixed-patrol' => [
    new BattlerSlot(350, 400, 160, 230),
    new BattlerSlot(530, 470, 120, 100),
  ],
],
```

Unmatched troops retain the arena's default slots. An override changes neither
the background, party slots, UI geometry nor feedback safe area. Resolution is
immutable and encounter-local; the same troop can use another arena's formation
elsewhere, and an encounter without an arena remains without one. Slot limits,
image bounds and participant coverage are checked by the existing graphical
preflight. An empty or incomplete selected override is not silently replaced
with the default formation. No save schema, encounter weights, combat identity
or renderer protocol changes are involved.

This is runtime metadata support. Editor TUI selection/editing of battle arenas
and these troop-slot mappings is not implemented. Future authoring must use
existing troop selectors and source-preserving transactions/round trips; it must
not flatten the authored PHP catalog. No Editor capability is claimed here.

### Battler Placement

Whole-image `BattlerArtwork` dimensions come from the current PNG and pivots
scale with replacement dimensions. Explicit atlas regions retain source-pixel
geometry relative to their crop. `BattlerSlot(x, y, width, height)` gives the
graphical pivot
destination and maximum contain dimensions. PHP uniformly scales the selected
source to those limits, then subtracts the scaled pivot to resolve the destination
rectangle. No snapping to terminal cells occurs. Art-supplied shadows belong to
the image and receive no extra runtime shadow.

Supply party slots for the configured active formation and enemy slots in the
troop's actual member order. The existing Party reserve fallback remains live:
all roster images and their possible party-slot placements are preflighted, but
only the actual frontline is drawn. Enemy removal never renumbers a surviving
instance's authored slot. Unavailable participant images use a local text
fallback; other participants
retain their art and stable slots. Invalid formation geometry or exceeded frame
budgets diagnose a surface-level fallback before battle-entry effects. Combat
continues. Stale legacy crop bounds are reconciled, not treated as identities.
This PHP preflight reads headers without PNG
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
display-raster pool. Native text uses
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
Every submenu action enters target confirmation before it is queued, including
self-only skills, magic and items. Self-only actions highlight only the caster;
group actions highlight the eligible group. Cancel returns to the same submenu
option without spending resources or applying effects. Traditional and active-time
battles share this PHP selection flow in both graphical and terminal renderers.
Direct top-level Guard and Escape commands retain their existing behavior.
The active actor currently uses an underline. Above-head actor indicators and
additional target-cursor motion remain presentation follow-ups, independent of
input ownership and battle outcomes.

## Current Integration

Production `assets/Data/Presentation/battle.php` references runtime metadata,
never test fixtures. Use `ichiloto play --renderer=gpui` or the same entry point
with `--renderer=terminal`. Native controls are independent from optional
arena/combatant coverage. Encounter-specific `battleArena` bindings distinguish
locations where the same troop appears. Packaging, platform qualification and
artwork provenance remain separate from the runtime contract.

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

Synthetic regressions cover arena selection/reset, stable identities, layout
and reserve formations, recipient-side feedback, resource budgets, terminal
isolation and exactly-once outcomes. Those checks do not establish native
visual performance or every gameplay/platform combination.

Untimed `TurnResolutionState` status-tick feedback still needs owner-lifetime
follow-up without adding a gameplay delay. Animated battlers, graphical summon
effects and distribution remain separate work.

## Battle Results integration

`BattleResult::rewards` now carries detached award
facts through `BattleRewards`/`BattleProgression`. The shared EXP award path
captures before/after levels, thresholds, stats and actual newly learned skills.
Battle resolution captures rolled drops and inventory retention separately;
presentation cannot grant rewards. The existing full per-member EXP policy,
including reserves, is unchanged.

`BattleResultsPlayback` owns a delta-driven Primary -> per-character Level Up ->
Learned Ability -> explicitly supplied Special Reward sequence. Confirm finishes
the current reveal; a later input advances. Overflow is manually paged, never
silently truncated. Reduced motion keeps facts and order. Terminal uses the same
snapshot/stage model; graphical Results retain the final real battlefield and
omit battle command/target/feedback overlays. Resume does not catch up elapsed
presentation time from a blocked scene. A new battle discards the prior Results
session. All configured PNGs receive pre-entry path/crop validation; resource
budgets apply to concurrently displayed families, not the whole catalog.

The existing `BattlePresentationCatalog` has optional `results` skin metadata,
separate menu/bust portrait families and category icons. Missing portraits use
neutral initials. Primary uses menu art; Level Up and
Learned Ability use the separate bust role. Whole-image contain preserves
proportions and transparency. No rarity or demo outcome is invented by the
presenter. Optional asset failures remain local to their fallback.

Current native treatment uses existing integer text grids and per-child alpha,
not browser font families, proportional shaping, CSS filters or isolated group
blending. Page transitions are a short input-locked hold then incoming reveal,
not a claim of browser crossfade equivalence. Event-only input has an 80 ms repeat
guard but cannot prove physical release when repeats are slower; the queued
controller-ready input slice owns that missing native transition contract.

The action retains `Continue` throughout its locked exit; `Complete` is the
reveal action. The Complete-to-Continue handover fades the button assembly out
over 120 ms, holds an empty 60 ms beat, then fades the new assembly in over
140 ms. Natural and explicit fast-completion share the timeline. Confirm is
ignored until the incoming action is fully visible. Reduced motion switches
directly; terminal hides the hint during the lock. Labels remain independently
centred and buttons use steady focus without oscillating list cursors.

Shared `CanvasImagePreflight` budgets full decoded sources and guarded regions.
Canonical paths deduplicate sources; crop identity deduplicates regions.
Opacity/clipping do not remove referenced source cost. Budget the actual
battle/HUD plus at most four Primary portraits or one event bust, not the
catalogue union. Future crossfades must account for both stages.

Per-snapshot source and region limits remain 64 MiB independently, with 1024
sources and 4096 regions. Cache eviction removes cache ownership, not live
queued/displayed snapshot references; these are not total process-memory limits.
Header/preflight results are cached by path and modification time so unchanged
images are not reopened every frame and replacements are observed. Native
decoding remains the final pixel-validation boundary.

Coverage must include replacement art, missing unused and visible assets,
sliding portrait pages, budget overflow, reordering, normal/reduced motion,
input locks and unchanged reward outcomes. Game-specific playtest receipts and
art admission are not part of this public contract.
