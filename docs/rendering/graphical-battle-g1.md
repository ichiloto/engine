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
`sourceRect`, optional `opacity` and `brightness`. Opacity defaults to 1 and must be finite in
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
`color` is a required nonnull existing ColorSpec. Native code renders submitted
indicators; it does not choose targets or add an acting marker.

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
Terminal entry retains the legacy intro. Graphical entry prepares the battle
canvas under a shared retained handoff; subsequent battle frames use the canvas.
Returning to another scene sends an ordinary replacement frame.

### Graphical Entry Handoff

Local wiring added on 2 October 2026 after the missing approved transition
was identified. `SceneManager` owns entry traversal and combat gating through
`ScreenTransitionSession`. `RendererRuntime` retains the actual outgoing
composition and atomically installs the prepared incoming composition beneath
full cover. `BattleScene` can supply its prepared canvas in `BattleStartState`;
that state no longer plays the old ASCII intro or clears between text frames
in GPUI. This is an explicit removal of the old graphical text intro, not a
removal of terminal entry or an artwork substitution.

The approved treatment retains the real outgoing field, gathers for 100 ms,
covers diagonally over 260 ms, holds fully opaque for at least 80 ms and
reveals the prepared battle over 380 ms. The shared transition owner must
emit complete cover before the hidden handoff, retain it through delayed
readiness and skipped time boundaries, and release its layer/input ownership
exactly once. Off and reduced motion add no animation delay. Battle clocks
and commands begin only after entry completes. Cancellation, focus changes,
resize and failed preparation must not leave a curtain or input lock behind.

Battle entry uses the independent `ui.transitions.battle` boolean and the
Battle Transitions setting. It can be enabled while ordinary doorway
`ui.transitions.style` remains `none`. Omission preserves existing projects'
shared style gate; reduced motion still skips animation. The authored catalogue
continues to select the treatment, independently of that enablement policy.
At the hidden field-to-battle boundary, `Console::recomposeFrame` atomically
replaces scene-owned text. Untiled field glyphs must not leak into a graphical
battle that draws no terminal background. Game-owned overlays survive the
replacement. Headless entry tests cover text/graphical fields with transitions
on, off and reduced motion; these do not establish native pixel acceptance.

Optional `Data/Presentation/transitions.php` returns `ScreenTransitionCatalog`,
whose stable treatment ids and optional battle selection are project-owned.
`ScreenTransitionTreatment` stores declarative phase timings, numeric tweens,
linear/smoothstep easing, shared composite operations and an opaque cover brush.
The engine inserts its unmaskable safe cover at the hidden handoff. Authored
appearance cannot choose combat outcomes or weaken that cover. Traversal uses
integer nanoseconds to avoid floating-point clock drift at phase boundaries.

`ScreenTransitionCatalog::load()` refuses wrong types and escaping catalogue
paths. Constructors refuse invalid descriptors or unknown battle selections.
`validateAssets(assetRoot)` validates every treatment for Editor use through
the shared PNG path/header and source-budget preflight. Runtime checks only the
selected treatment. Invalid optional treatment artwork is diagnosed and uses
a direct cut, not a revived GPUI text intro. PHP preflight is not native PNG
decode or visual acceptance. GUI graphical authoring remains in the existing
Editor plan; the terminal-only TUI does not gain a graphical editing workflow.

The retained frame is an immutable composition, not a raster screenshot or
gameplay snapshot. It preserves world/text/canvas/sprite identities and camera
state without re-running outgoing scene composition. An already submitted
native field-motion segment can settle beneath the sweep; the current contract
does not claim to freeze a native interpolated screenshot. Resize keeps the
logical canvas and owned phase; focus loss pauses elapsed transition time.

Headless tests exercise two distinct authored treatments, finite traversal,
skipped-time cover, delayed readiness, preparation failure, Off/reduced motion,
focus pause, cancellation and retained atomic replacement. Native visual,
full-viewport raster cost, repeated real encounters and platform acceptance
remain pending. This local entry wiring does not complete G2 or its remaining
production pose/effect bindings.

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

For whole-file UI textures, use `CanvasNineSlice::getFromPng($assetRoot, $path, ...)`
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
    'arena.example' => new BattleArenaDefinition(
      name: 'Example clearing',
      background: new CanvasImage('arena', 'Graphics/Battle/arena.png', new CanvasRectangle(0, 0, 1350, 720)),
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
    feedbackArea: new CanvasRectangle(0, 80, 1350, 452),
    partySlots: [new BattlerSlot(969, 265, 143, 181)]),
  defaultArena: 'arena.example',
);
```

`BattleCanvasLayout` owns canvas dimensions, the compatibility UI grid, party
slots and feedback safe area. Its skin is optional; a skinned layout requires
an explicit feedback safe area. `BattleArenaDefinition` is a scene only: a
display `name`, `background`, and optional `skin`. It no longer extends the
layout or owns enemy/party positions. A scene skin changes treatment without
changing shared geometry. Preflight checks its background against the shared
canvas, validates PNGs and capabilities before battle-entry effects, and refuses
unusable optional graphics without cancelling combat.

An optional `battleArena` key in a map's `encounters` block or a scripted
`start_battle` command selects an entry in the catalog's `arenas`. Direct callers
can pass the same key through `SceneManager::loadBattleScene`'s `extraSettings`.
Use this for location-specific backgrounds: the same troop can appear in several
settings. Formation does not change when the background changes.
Mixed random tables can select a scene on an individual map-owned choice:

```php
'encounters' => [
  'troops' => [
    'Cave Creatures' => 5,
    'Lake Creature' => ['weight' => 1, 'battleArena' => 'arena.lake'],
  ],
  'battleArena' => 'arena.cave',
  'rate' => 22,
],
```

`Field\EncounterEntry::getFromValue()` reads numeric legacy weights and structured
rows; `getBattleSettings($mapSettings)` selects entry settings over common map
settings. Unreadable/nonpositive weights are skipped as before. Optional invalid
arena data is retained for graphical diagnostics, never used to discard a
playable encounter. The table's weights, random selection and troop identities
are unchanged. The same troop may use a different scene in another map row,
scripted battle or battle test. Source-preserving structured-row edits and arena
pickers are Editor-owned; they must retain unowned row fields and not rewrite
the row to an integer when changing its weight or name.
The binding belongs to this encounter, not global or inferred map state; map
encounter reconfiguration clears any previous binding. An explicit invalid or
missing arena/catalog is diagnosed before battle-entry effects, and combat
continues with the terminal presentation. Terminal play ignores this optional
presentation metadata.

Without a binding, `BattlePresentationCatalog::getArenaFor($battleConfig)` uses
only the catalog's explicit `defaultArena`. No default means no illustrated
scene. An explicitly invalid key (including null) is refused, never substituted.
Troop-ID and troop-name arena fallback are removed.
`getArenaChoices(): array<string,string>` lists scene key => display name in
authored order. Tools pass a selected key in `loadBattleScene` extra settings:
`['battleArena' => $key]`. Actor lookup uses `Character::actorId`.
Enemy lookup uses the current `EnemyStore` name key. These are definition lookup
keys, not presentation instance identities. Repeated enemy objects have separate
`combatant-<spl_object_id>` IDs held for this battle's lifetime; no RNG or save
identity is added. Targets and feedback refer to the actual PHP object.

### Troop-Owned Graphical Formation

Arena-side `enemySlots` and `enemySlotsByTroop` are removed, not retained as
compatibility overrides. Each troop enemy in `Data/troops.php` owns its
graphical placement beside its unchanged terminal position:

```php
['enemy' => 'Existing Enemy Catalog Key', 'position' => [15, 7],
 'graphicalPlacement' => [
   'x' => 375, 'y' => 467, 'width' => 173, 'height' => 197,
 ]],
```

These are `BattlerSlot` pivot destination and maximum contain dimensions in
shared canvas units, not terminal cells or copied source-image dimensions.
Exactly four numeric finite keys are accepted: positions nonnegative,
dimensions positive, each at most 16384. `Troop::fromArray($data, $source)`
diagnoses invalid optional data with its troop/enemy source path, preserves the
combatants and terminal positions, and records a refusal for graphical
preflight. Absence is allowed for terminal-only content but not inferred from
terminal coordinates. Canvas bounds and complete participant coverage are
checked during graphical preparation.

`Troop::getGraphicalSlot($enemy): ?BattlerSlot` associates the authored slot
with the actual instantiated Enemy object. Sorting, removing a defeated enemy,
or having repeated enemies of one type cannot reassign a survivor's placement.
The association survives ordinary serialization without adding a save schema.
Party placement belongs to the shared layout and follows the active frontline.
Any valid troop formation can be prepared against any valid scene; neither
selection nor geometry depends on troop names.

Claude owns Editor work: source-preserving graphicalPlacement round trips
through existing troop editing transactions/undo; arena reference pickers from
getArenaChoices(); validate battleArena keys in map encounters and scripted
battle commands. Refuse unsupported PHP edits before writing. Do not add a
mandatory graphical authoring workflow to the TUI or flatten authored catalogs.
Runtime support does not claim this authoring work is complete.

### Ownership Audit And Game Migration (2026-10-02)

The correction moves enemy formation from scenes to troops, and party/UI/safe
area geometry to the shared layout. It removes the troop-keyed arena fallback
and Last Legend's duplicate practicum arena, which differed only by enemy
formation. Last Legend declares `arena.eastern-service-road` as its default;
previously unbound battles now deliberately use that scene, not a troop-name
inference. Terminal positions, outcomes and map geometry are untouched.

One conflicting troop required Andrew's decision: `Bat x 2` had Eastern
Service Road slots `(375,467,173,97)/(497,233,197,119)`, Central Apthian Road
slots `(210,265,205,144)/(500,455,205,144)`, and Controlled Yard slots
`(260,300,275,190)/(540,450,275,190)`. The coordinator presented before/after
composites in Game's
`Graphics/Comparison/BattleFormations/BatPair.before-after.png`. The proposed
shared formation was Controlled Yard, preserving the licensing assessment.
Andrew approved that recommendation on 2026-10-02: "Yes that recommendation is
approved." The two troop entries now own those Controlled Yard slots in every
arena; their terminal positions remain `[15, 7]` and `[15, 20]`. No scene-specific
override or inferred substitute is retained, and graphical checks are unchanged.
Other existing active formations agree across their authored scenes. Loch Ness
and Great Wolf, previously without a graphical formation, have explicit
proposed slots `(440,484,230,215)`; these are new coverage, not a claim to
preserve a previously drawn scene.

The audit also removed duplicated Results portrait paths from Last Legend's
battle catalog: it consumes menu portrait and dialogue bust role bindings from
their existing owners. It removed runtime action-animation display-name
inference: numeric `animationId` stays authoritative, and explicit semantic
`roles` in animation records own project defaults. Missing/ambiguous roles are
diagnosed without name substitutes. Legacy name candidates remain only for
Claude-owned Editor migration diagnostics, not runtime selection.

Mechanisms retained as sound:
- EnemyStore keys identify actual enemy definitions, not scene selection;
  actor artwork and pose sets use stable actor IDs.
- Combatant-instance identities own target, movement and feedback state;
  no display-name or mutable roster-index lookup chooses enemy placement.
- Party reserve/frontline selection belongs to Party; the layout supplies
  presentation slots, never participants or combat outcomes.
- Command/effect playheads and exactly-once impact cues are PHP-owned;
  native drawing does not drive gameplay or choose effects.
- Base-art/terminal degradation diagnoses unavailable optional resources;
  it does not silently supply a different formation or treat invalid data as
  valid artwork. Mutable PNG dimensions are read from current assets.

Pre-approval verification on 2026-10-02 (PHP 8.5.10, macOS headless): Engine full suite
3695 passed, 1 existing skip, 56921 assertions; PHPStan has no errors. Game
standard CI after Claude recorded the authored Town Center edits and the fresh
renderer-entry regression has 1228 passed and 10 failed (778260 assertions).
The earlier full Game run including
battle simulations had 1253 passed and 12 failed (778534 assertions); all 28
simulation tests passed. Eight remaining failures require the unresolved Bat x 2
formation; two concern Waymeet's changed Rhea/Brann positions and await Andrew's
intent confirmation. Claude's Town Center history-test changes resolved the
other two failures without reverting map art or weakening assertions. Those
history-test-only amendments were verified with standard CI; the long simulation
group was not repeated afterward. No native playtest was launched; Linux/WSLg
and other native platforms are untested in this pass.

After Andrew approved Controlled Yard's shared Bat x 2 formation, the graphical
battle suite passed all 77 tests (33357 assertions), including the existing
real-scene encounter matrix and an explicit check of the approved slots in all
seven arenas and the default scene. Game `composer test:ci` then passed 1272
tests with 2 failures (806381 assertions). All eight formation-related failures
are resolved without weakening checks. The two remaining failures are the
existing Waymeet NPC/save-position intent checks, outside this approval. These
results are PHP 8.5.10 headless on macOS; battle simulations and native playtests
were not rerun for this approval, and no remote action was performed.

Andrew's 05:28 local playtest exposed a partial live migration: at
2026-10-02T03:28:00Z the Game catalog still passed the retired arena `width`
argument to the new scene-only constructor. The logged refusal correctly kept
combat playable, but that does not make the regression acceptable. Engine and
Game contracts are now reconciled; no legacy constructor or troop-keyed override
was reintroduced to hide the mismatch. A fresh protocol-2 runtime regression
loads the current Game catalog, configures the actual Service Road Vermin troop
with the declared default and all seven explicit scenes, and replays transmitted
retained frames. It requires background, battler images and skinned command/vital
panels, with no terminal battler layers and no battle-state mutation (80
assertions). This is headless entry/submission evidence, not native pixel review.
Existing running PHP sessions retain loaded classes; contract changes require a
fresh game process. Future breaking migrations must keep Engine and Game callers
consistent together before exposing them in the shared playtest checkout.

The shared Bat x 2 formation decision is resolved. Completion still requires
green full Engine/Game suites after the remaining authored map expectations
are reconciled. The correction is
preserved locally but not yet committed or published; unrelated G2/UI/art work
remains intact. Editor work and native cross-platform acceptance remain separate.

### Battler Placement

Whole-image `BattlerArtwork` dimensions come from the current PNG and pivots
scale with replacement dimensions. Explicit atlas regions retain source-pixel
geometry relative to their crop. `BattlerSlot(x, y, width, height, displayScale: 1.0)` gives the
graphical pivot destination and movement/legacy contain dimensions. The catalog's
optional `BattleScale` owns a shared reference actor, reference body height and
identity-keyed actor/enemy proportions. `BattlerScale` calibrates that body unit
against normalized source frame height or width; a pose may supply `scaleSpan`
when its source density/composition differs. Current image or animation-cell
dimensions determine uniform pixel scale, not the formation box or pose silhouette.
Every registered battler, including an identity registered only through pose
artwork, requires a profile in reference mode; competing legacy `displayWidth`
values are rejected. The October 4 coverage correction rejects uncalibrated
pose-only registrations and competing pose-only widths, rather than silently
letting them bypass the shared scale. Calibrated pose-only artwork remains usable
without a base registration. Catalogs without the reference contract retain
legacy width/contain behavior. Last Legend uses Kaelion's standing reference for
every registered actor and creature, including base-only degradation and previews.
Source anatomy and pivots remain Art-owned; opaque extents include weapons,
robes and shadows and are never an automatic anatomical measurement.
An optional slot-owned `displayScale` applies uniform perspective depth once,
after body calibration or legacy fitting, about the same ground pivot. It follows
the current slot occupant for base artwork and every still/animated pose. It
does not redefine actor anatomy or change movement space, terminal geometry,
combat or saves. `placeAtScale` accepts the pre-slot pixel scale; previews expose
the depth-adjusted `bodySpan`. Optional numeric `displayScale` in authored troop
placements defaults to 1.0; party slots use the same typed contract.
No snapping to terminal cells occurs. Art-supplied shadows belong to the image and receive no extra runtime
shadow. See the [pose scale contract](../effect-animation.md#local-battle-runtime-october-2026).

Missing pixels are not a tiny image with a huge body scale: shared placement
retains only the name anchor, while valid pose-only artwork uses its own
calibration or legacy contain fit. Canvas-edge name footprints remain bounded
without changing the authored ground anchor; selected/queued missing-art
members use text feedback, not image cursors anchored to absent pixels.
Optional explicit `battlerArea`, `enemyArea` and `partyArea` regions support
shared read-only current-pixel clearance diagnostics
for formation previews, validation and runtime preparation. The regions are
not inferred from terminal coordinates, a canvas half or `feedbackArea`.
Diagnostics report overlap, out-of-region art and unavailable cursor space;
side regions may be anywhere on the canvas, including mirrored formations.
they never move or shrink authored battlers. Optional PNG inspection support
is reported when unavailable and is not a new runtime decoding requirement.

Supply shared-layout party slots for the configured active formation and each
troop enemy's graphicalPlacement. The battle-owned active roster is drawn;
automatic reserve fallback is removed by default. Per-battle
`reservePolicy => replace_after_wipeout` is an explicit opt-in applied after
the final KO presentation, never by drawing or asset reads. Enemy removal never renumbers a surviving
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

Focused/queued target instances have an outline and target-name label. Andrew
removed the acting underline on October 4: the shared command advance/action/
return presentation identifies the actor without an additional marker or
"Acting" fallback label. The obsolete `acting` battle-skin texture role is
removed; themes declare only panel, quiet, track, hp, mp, atb, selector, target
and queued textures. Target selection and acting identity remain independent.
Without a usable KO pose,
party KO uses dimmed standing artwork without a KO label. The shared G2 role path
instead displays a registered KO pose at normal opacity and its authored ground
anchor, and restores the current resting pose after revival. Missing non-idle
bindings are diagnosed rather than treated as finished art. Defeated enemies
remain through their existing popup hold, then perform the bounded shared defeat
treatment described below before removal. `BattleFieldWindow` retains recipient, sequence and existing formatted
result lines; graphical placement uses the recipient's image bounds, not ASCII
placement or damage-string parsing. Clearing the existing popup also clears its
canvas feedback. Static poses and these text adapters are G1, not G2/G3 or a
completed graphical UI.

### Battlefield Conditions And Enemy Defeat

This October 7 slice is independent of G3 and does not close it. Runtime gauge
headers are **Time** in both presentations; internal ATB fields and texture roles
are unchanged. No legal/trademark claim is made.

`HasStates`/`HasStatStages` own live gameplay. `BattlerConditions` projects their
current identities, names and descriptions for the existing Info action: the
active actor during command/submenu selection and focused recipients during
target selection. No HUD state icons are introduced. Condition effects are field
layers through the same `BattleEffectPlayback`, `GraphicalBattleEffects` and
`TerminalBattleEffects` paths as commands; details and authoring are in
[effect-animation.md](../effect-animation.md#battlefield-condition-effects).

`BattleCommandRunner` registers only newly defeated enemies from actual result
vitals, including simultaneous targets and lethal self/poison results.
`BattleCommandPlayback` owns their delta-driven clock, begins treatment at the
existing feedback/return boundary, and holds completion until every treatment
has cleared. It does not change HP, combat outcome, state duration or rewards.
Pause freezes it even after the effect session has ended. Cancellation/disposal
abandons visuals and pending cues; revival removes that enemy from the hold.
Result holds, counter sequencing and battle cleanup remain the existing owners.
Large updates cross the bounded lifetime without looping or replaying cues.

Project/theme `config.php` may set `ui.battle.defeat`:

```php
['pulses' => 3, 'pulseSeconds' => 0.12, 'fadeSeconds' => 0.25,
 'color' => [255, 64, 64], 'audio' => true]
```

These are defaults; omission needs no assets. Validation requires 1..8 pulses,
0.05..0.5 seconds per pulse, 0.05..2 seconds fade and three integer RGB components
in 0..255. Normal motion alternates a masked color pulse for each half-period,
then fades the current image. Reduced motion uses one plain fade for the same
bounded duration, with no pulse. Without compositing, fade/clear remain and the
missing pulse capability is diagnosed. Terminal uses the configured pulse color,
then a steady dim/disappear equivalent because terminal cells have no alpha.
Missing battler artwork retains the readable name until clear, not finished art.

The former impact-time `ENEMY_COLLAPSE` sound is removed/moved: impact now uses
ordinary `ENEMY_DAMAGE`, and each cleared enemy emits `ENEMY_COLLAPSE` exactly
once through the existing battle audio owner, never through rendering. A failed
audio sink is not retried. `audio => false` omits this cue; unbound
`audio.sounds.enemy_collapse` remains silent through the existing AudioManager.
No sound or art is generated/admitted by Engine. KO-role feedback remains typed
inspection data, but its label is removed from both graphical and terminal
painting; ordinary damage/healing feedback is retained.

Headless tests establish these contracts only. Game owns art/audio bindings;
Editor/GUI own selectors and safe source-preserving configuration round trips.
Native pixels/audio, production admission, other platforms and integration
acceptance remain separate owner work.

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

`canvas_image_tone` is an additional optional protocol-2 capability requiring
`graphical_canvas`. Its image `brightness` is finite in 0..1, defaults to 1 when
omitted, and multiplies source RGB without changing alpha. Explicit null is
invalid. It affects prepared presentation pixels, not the source file, image
identity, geometry, crop, clip or opacity. Non-default brightness is rejected
before enqueue when the capability was not negotiated. This lets shared UI
de-emphasize inactive artwork without making characters translucent.

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

Terminal results keep a fixed panel and paginate its measured content viewport.
Party Progress names are left aligned and their level/EXP values are right aligned,
using the same right edge as Level Up stats. Labels and values remain separate
facts until presentation: terminal measures grapheme display widths, while Canvas
keeps its own scalar text grid. Oversized identities or values continue on additional
lines/pages instead of overlapping, clipping or widening the panel.

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
Header/preflight success, failure and diagnostics are cached by path, filesystem
facts and the current 24-byte PNG header (at most 1024 entries). Each probe checks
safe root containment, readability and resource limits before reading that
bounded header. Parsed dimensions are reused only while those facts agree;
rapid in-place edits refresh immediately even if inode, encoded size and
second-resolution mtime/ctime collide. Atomic replacement, deletion and repair
use the same boundary. Every crop is checked against current dimensions.

This removes the former stat-only freshness assumption. A probe opens the PNG
for its header, not a full-file hash or PHP image decode; repeated consumers
share the parsed result and diagnostic de-duplication. There is no TTL or
authored hash/dimension requirement. Native decoding remains the final
compressed-pixel-validation boundary: an unchanged valid header with corrupt
IDAT data still requires decoder validation.

Coverage must include replacement art, missing unused and visible assets,
sliding portrait pages, budget overflow, reordering, normal/reduced motion,
input locks and unchanged reward outcomes. Game-specific playtest receipts and
art admission are not part of this public contract.
