# Layered tilemaps - authoritative plan

This plan replaces the engine's single-grid map model with authored map
layers, restores the HEREDOC authoring contract that the editor depends on,
and removes object-oriented map builders from game assets. Layered tilemaps
are an **engine capability**: every game built on Ichiloto gets this
functionality. Last Legend is the reference consumer and supplies the first
full migration; its game-owned vocabulary and layout policy live in its own
`docs/development/map-visual-language.md`.

This document owns the product contract and implementation roadmap. The
[graphical correction roadmap](#graphical-correction-roadmap) supersedes the
older requirement to author graphical markers and crops through the TUI,
and the use of terminal characters as graphical tile identities;
the original phases remain as implementation history, not a requirement to
restore that workflow. The glyph-keyed tile crops (`tiles2d`) described below
are retired; [graphical field](graphical-field.md) owns the replacement. Related engine docs: [maps.md](maps.md),
[rendering/tile-batches.md](rendering/tile-batches.md),
[integration roadmap](rendering/integration-roadmap.md).

## Principles

1. **The terminal always represents the whole game.** Everything a player must
   know to play - geometry, collision, interactions, routes - is expressed in
   glyphs the terminal displays. Graphical representation must never drive or
   change the terminal experience. Richer renderers share logical gameplay
   identity but need not inherit terminal cell proportions or drawing scale.
2. **Shared physical space, independent presentations:**
   - **Collision = physical footprint.** Shared collision specifies occupied
     ground and passage. It informs physical size and placement in both
     presentations. An art resize must not enlarge that footprint.
   - **Glyph = terminal presentation.** A character is not graphical identity.
     The same `#` can represent walls shown graphically as wood, stone, plastic,
     different colours or patterns. Neither material nor a visual connection
     variant requires a new character or a differently named glyph layer.
   - **Colour = attention.** Colour is spent sparingly, only on information
     that needs the player's eye: encounter grass, water, information points,
     event cues. Colour never carries tile identity - yellow terrain cannot
     tell sand from savannah, and it should never have to.
   - **Graphical resources = appearance.** Visual materials, families and
     placements have identities independent of terminal characters. They share
     world coordinates and physical occupancy, not a glyph-to-image contract.
     Artwork ground contact fits the shared footprint; height and deliberate
     overhang are distinct from occupied floor. Collision alone cannot identify
     material or object boundaries, so graphical placements remain explicit.
     Named tile families and connecting brushes belong in the graphical GUI.
3. **The map text is the terminal display.** Gameplay layers contain exactly
   what the terminal shows. No glyph in a gameplay layer may secretly mean
   something other than what it displays. Presentation-only detail goes in
   decoration layers, which the terminal never consults.
4. **RPG Maker is the model, adapted.** Shared physical footprints and passage
   remain independent of graphical tile choices; the pseudo-isometric look is
   tileset art, not map geometry. Graphical scale and composition must not
   force a rewrite of ASCII facades or terminal editing conventions. Passage
   and draw priority are separate: pass-through does not imply above-player
   drawing.
5. **Editors serve their presentation.** The TUI editor is only for the
   Terminal; established terminal map and event editing remains available as
   before. GPUI and other richer renderers have their own GUI editor with
   RPG Maker-like visual tile authoring, not a full RPG Maker clone. Shared
   gameplay identity, source-preserving services, validation, transactions and
   undo remain mandatory. The graphical-marker/crop workflow is to be removed
   from the TUI without deleting authored graphics or narrowing terminal tools.

## Original single-grid limitations

These describe the starting model that motivated the original phases, not a
claim that the delivered layered-map foundations are still absent.

- **One grid carries three jobs.** `Camera::worldSpace`, the collision map and
  the `tiles2d` crop lookup all derive from the same character array, so a
  glyph must mean one thing per map, globally. In Last Legend this is why `x`
  (windows *and* ridges) could never be mapped to a crop, and why the blade in
  Kaelion's home sits on the room floor instead of hanging on the wall: the
  wall cell's glyph was already spoken for.
- **Object-oriented map builders broke the editor contract.** Maps were
  designed as HEREDOC strings precisely so the editor could round-trip them.
  The editor canonicalises `.map.php` to a nowdoc the moment one cell is
  painted (`ProjectMap::save()` → `buildMapPayload()`), destroying any builder
  code and its colourising passes without warning - demonstrated live on
  Last Legend's `waymeet-field-post`, whose painted cells also fell out of the
  map's colour scheme because the colouring lived in the destroyed code.
- **A contract asymmetry exists.** The engine accepts `string|string[]` from
  `.map.php` (`MapManager::parseMapLayer()`); the editor accepts `string`
  only (`ProjectMap::fromDirectory()`).
- **The capacity for layers is already shipped and unused.** The renderer
  protocol supports 64 tile batches at arbitrary z (`PresentationTileBatch`);
  the engine emits exactly one, hardcoded `'terrain'` at −100. The event layer
  already proves the authored-grid-per-layer idiom.

## Existing authoring format

The format below describes the delivered layered-map implementation. Its
glyph-keyed collision dictionary and crop lookup are legacy compatibility
inputs, not the target identity contract. The correction must introduce
independent graphical resources/placements over shared collision, with explicit,
source-preserving migration and unchanged resolved passage and terminal output.
Do not silently reinterpret existing maps or create a second collision authority.

Each map folder gains a `layers/` subdirectory. Each layer is one file:

```
<order>.<layer-name>.map.php     - gameplay layer (nowdoc character grid)
<order>.<layer-name>.deco.php    - decoration layer (nowdoc character grid)
```

`<order>` is a two-digit integer prefix that determines stacking (bottom to
top); `<layer-name>` names the layer and may be changed later without
disturbing order. Example:

```
temple-of-the-listening-stone/
  temple-of-the-listening-stone.data.php
  temple-of-the-listening-stone.event.php
  layers/
    01.terrain.map.php
    02.floors.deco.php
    03.buildings.map.php
    04.fixtures.map.php
```

- Discovery is by convention: glob `layers/*.{map,deco}.php`, sort on the
  integer prefix. `.data.php` needs no layer declaration; the editor's
  surgical `.data.php` rewrite path is untouched.
- Every layer file returns one literal nowdoc grid string, same dimensions as
  the base layer. The conventional delimiter is `ICHILOTO_MAP`, but any valid
  nowdoc label is accepted. No classes, function calls or generated grids.
- `.event.php` stays at the map root and follows the same literal-nowdoc
  source contract. `.data.php` remains the executable map-data member.

The Engine and Editor share a static grid-source parser. It accepts a single
nowdoc return with optional surrounding comments and whitespace, and reads the
grid without evaluating its PHP source. Both `.map.php` and `.event.php` are
validated before `.data.php` is evaluated. The Editor lists a noncanonical
map as read-only with its source diagnostic, while other maps remain editable
and validation reports each invalid map. It rechecks existing grid sources
before save, duplicate or move, including unchanged grids. Refusal happens
before file transactions and leaves authored bytes untouched. Executable grid source and `string[]` grid
values have been removed from the contract; `.data.php` retains its separate
source-preserving editing rules.
- Layer names and the standard stack for a given game (e.g. Last Legend's
  terrain → buildings → fixtures) are game-owned conventions; the engine
  imposes only the file format and ordering. Maps declare only the layers
  they use; a simple map may be `01.terrain.map.php` alone.

### Gameplay layers (`.map.php`)

- Composed **topmost-wins** into the terminal display: for each cell, the
  highest layer with a non-space glyph supplies the character. On layers above
  the base, a plain space means "empty, show through." The composed grid is
  what `Camera::worldSpace` holds; the terminal renders exactly this.
- Feed collision (below) and per-layer crop tables (below).

### Decoration layers (`.deco.php`)

Presentation-only, with three hard properties:

1. **The terminal never sees them.** They contribute nothing to the composed
   grid and nothing to collision.
2. **Their only output is crops.** Each has its own `tiles2d` symbol table and
   emits its own GPUI batch at its stacking position. Their glyph namespace is
   completely free - any character, reused anywhere - because nothing
   gameplay-bearing can key off it.
3. **They are structurally barred from gameplay.** Validation rejects a
   decoration layer's name in the collision dictionary and rejects any deco
   glyph without a crop mapping. If a decoration ever becomes interactive, it
   moves to a gameplay layer or an event; gameplay is never bolted onto a
   decoration in place.

This is how a kitchen gets checkered tiling, a living room a rug over plain
tile, and a hallway wooden boards - all within one house - while the terminal
shows clean blank floors and the colour budget stays untouched.

## Collision

An explicit [physical occupancy](maps.md#physical-occupancy) declaration is the
sole static passage authority for runtime, previews and reachability in both
presentations. Its cells are final collision types, independent of terminal
glyphs and graphical placements. Malformed declarations refuse instead of
falling back; only an absent declaration selects the compatibility rules below.
Ordinary loads and saves never migrate a map.

Legacy resolution is **topmost-occupied-wins with pass-through**, the RPG Maker
rule translated to glyphs:

- For each cell, consult gameplay layers from the top down. Skip empty cells
  (spaces on non-base layers) and pass-through cells. The first remaining
  glyph decides the collision via its layer's dictionary. Layers below it are
  never consulted.
- `CollisionType::PASS_THROUGH` is the `☆` equivalent: "skip me, consult the
  layer below." An awning over encounter grass keeps its encounters; a bridge
  over water is walkable. This is passage resolution only. Above-player
  drawing needs separate graphical draw priority; it must not be inferred
  from `PASS_THROUGH` or change the resolved collision.
- The game's `collisions.php` stays backward-compatible: a flat array applies
  to all layers; optional sections keyed by layer name (`'terrain' => […]`,
  `'fixtures' => […]`) give a glyph different meanings per layer. Unknown
  glyphs still default to `SOLID`.
- On an undeclared map, collision is derived from visible text through the
  dictionary. Explicit migration captures that resolved result in one shared
  physical grid without changing current passage or either presentation; it
  does not introduce per-layer collision authorities.

## GPUI presentation

- `tiles2d` becomes per-layer symbol tables (shared or per-layer atlases).
  Keys are bare glyphs, normalised exactly as `GraphicalTileDefinition` does
  today - no style-aware lookup. Optional per-layer `cells` entries override
  artwork at explicit logical map positions without modifying terminal glyphs
  or collision. Repeated furniture symbols can therefore select separate
  image slices without proliferating gameplay layers. Text replacement remains
  confined to the artwork's owning layer; decoration never erases a different
  fixture or actor. The format and bounds are documented in
  [tile batches](rendering/tile-batches.md#cell-specific-artwork).
- `GraphicalTileCollector` emits one `PresentationTileBatch` per layer
  (gameplay and decoration), z-ordered by the layer prefix, all below
  `WORLD 0` (e.g. terrain −100, upward in steps). The protocol needs no
  changes; the renderer binary needs no changes. Verified against the
  renderer source (`gpui-renderer`, `src/state.rs` `prepare_v2`): the v2
  paint plan stable-sorts tile batches, text layers and sprites together by
  their `i32` layer, so multiple batches at distinct layers, and later even
  above-player pass-through batches, paint correctly as shipped.
- This resolves glyph-reuse ambiguity by construction: in Last Legend, `x` on
  the buildings layer is a window crop, `x` on terrain is a ridge crop, and
  neither is a guess.
- Wall-mounted objects render as crops over the wall tile: a fixtures layer's
  `/` (blade) or `i` (mounted notice) crops draw above the buildings layer's
  wall crop. In the terminal the same cells read `====/====` and `====i====` -
  visibly different by glyph, no colour spent. Interaction stays on the
  traversable approach tile in front, per the established event grammar.
- The single-column-glyph limitation for atlas mapping is unchanged; wide
  glyphs and emoji stay unmapped in GPUI, as today.

## Original implementation phases

The phase descriptions below retain the original delivery scope. Delivered
foundations include canonical grid-source refusal, layered composition and
collision, per-layer and cell-specific artwork, and the Editor's transactional
layer canvas, selectors and crop form, as described in [maps.md](maps.md#editing-and-migration).
That delivery does not establish completion of every reference-map migration
or the new graphical GUI. Phase 2's decoration canvas, terminal-preview split
and crop inspector describe the older TUI direction, now superseded by the
correction roadmap. Source safety, terminal gameplay/event editing and useful
terminal facade stamps remain; graphical-marker/crop authoring leaves the TUI.

### Phase 0 - Restore the authoring contract

1. Flatten Last Legend's remaining `AsciiMap` maps deliberately, in one pass -
   not by editing them in the editor. Evaluate each builder once, capture the
   rendered string, write it back as a literal nowdoc, and verify the output
   byte-identical against the evaluated builder so no colour runs are lost.
   Maps: `garden-of-roads/temple-of-the-listening-stone`,
   `garden-of-roads/lanternrest-wayhouse`, and the three
   `bsa/licensing-facility` maps. Repair `waymeet-field-post`'s colour runs
   (already flattened by an editor edit) in the same pass. Other
   generator-style maps (e.g. `happyville/municipal-office`) flatten here too:
   everything converts.
2. Delete Last Legend's `assets/Maps/Support/AsciiMap.php` once no map
   references it. It is gone by the end of this work; none of its grammar
   survives as map source.
3. Add validation: every `.map.php` / `.event.php` (and later every layer
   file) must be a literal nowdoc - no classes, no calls.
4. Close the editor gap: extend the `MapSourceRefusal` mechanism to the grid
   files, so the editor refuses to save over a grid whose source is not
   already canonical instead of silently flattening it.
5. Unify the contract: canonical nowdoc string on both sides; engine and
   editor agree on exactly one accepted shape.

### Phase 1 - Engine: layered map model

1. `MapManager` discovers and parses `layers/`, validates dimensions across
   all layers plus the event layer, composes gameplay layers topmost-wins into
   `worldSpace`, and retains per-layer grids.
2. `generateCollisionMap()` implements topmost-occupied-wins with
   `PASS_THROUGH`; `collisions.php` gains optional per-layer sections.
3. Per-layer `tiles2d` tables; `GraphicalTileCollector` emits one batch per
   layer; decoration layers are parsed, never composed, and feed batches only.
4. Backward compatibility: a map without `layers/` behaves exactly as today.
   The legacy single-`.map.php` path remains until migration completes
   (retirement timing is an open item below).

### Phase 2 - Editor: generalised layer canvas

1. Replace the hardcoded tile/event layer pair with a dynamic layer list from
   `layers/`; canvas modes cycle through the map's layers plus the event
   layer, with per-layer visibility toggles and dimming.
2. A terminal-preview mode hides decoration layers, showing exactly what a
   terminal player sees.
3. Saves write each layer to its own nowdoc file under the existing rule: an
   untouched layer's file is never written; the transactional write covers the
   whole set. Validation checks all layers share dimensions.
4. Layer create/rename/remove manipulates files in `layers/` (rename never
   disturbs the order prefix).
5. Multi-row facade stamps: a game's building catalogue (Last Legend's
   `buildings.txt`) becomes stampable brushes placed onto a buildings layer;
   the catalogue remains the single source of shapes.
6. Minimal `tiles2d` awareness: surface it read-only in the inspector; warn
   when a painted glyph has a crop mapping on that layer.

### Phase 3 - Reference migration (Last Legend)

Everything converts; the terminal game must play identically throughout.

1. Order: the flattened `AsciiMap` maps first, then the remaining maps
   following the Milestone 1-3 use cases.
2. Per map: split terrain / buildings / fixtures (and decoration layers where
   the art calls for them), move wall-mounted objects (the Kaelion home blade,
   mounted notices) onto the fixtures layer, and free the floor cells they
   were compromising.
3. Every migration is guarded the S8-B way: a fingerprint test asserting the
   composed render and every cell's collision result are identical before and
   after the split. Identical collision means no save-manifest bump; any
   deliberate geometry change goes through the established save-migration
   process separately.
4. Extend the Garden atlas per-layer (grass and water stay; `x` finally maps
   on both layers with distinct crops).
5. End state: every Last Legend map has `layers/` with at least
   `01.terrain.map.php`; no root `<name>.map.php` remains; `AsciiMap` is gone.

### Phase 4 - Documentation and tests

1. Update the engine's [maps.md](maps.md) and
   [rendering/tile-batches.md](rendering/tile-batches.md) with the layer
   format, collision resolution and per-layer batches.
2. Game-side: rewrite the glyph table in Last Legend's
   `map-visual-language.md` per-layer; codify the three-channel rule and the
   colour budget (colour is spent on attention, never identity); amend the
   "never classify cells by colour" rule to its precise form - *inferred*
   colour is never meaning, and identity never rides on colour at all. Update
   `graphical-terrain.md`.
3. Test coverage: engine (layer discovery and parsing, dimension mismatches,
   collision precedence including pass-through, multi-batch collection,
   composed camera output); editor (per-layer round-trip integrity extending
   `LastLegendMapIntegrityTest`, the grid-source refusal, terminal preview);
   game (per-map migration fingerprints, save compatibility).

## Graphical correction roadmap

The [graphical field plan](graphical-field.md) supersedes the scale, pivot and
tile-family choices in Stages 1 to 3 below: the terminal grid is unchanged, the
graphical field draws each terminal cell as one 48 x 48 pixel RPG Maker tile,
and tilesets, autotiles and character
sheets follow RPG Maker's conventions. Those replaced choices remain history;
Stage 1's independent physical-occupancy requirement below still applies.
Stage 4 is superseded as it says.

All six stages below retain the implementation requirements and their history.
Their shared runtime and source-preserving authoring mechanisms are implemented;
the October 12 G4 record qualifies representative macOS GUI/runtime checks and
the relevant full suites, with explicit limits rather than exhaustive platform
or artwork acceptance. G4 closes October 12 after the approved three-cell Shop
floor correction in Game `5d27b4a`: the existing Editor writes only the graphical
floor, leaving layout, collision, events, save compatibility and unrelated stock
edits unchanged. Its affected coverage/save tests pass 390 / 694,332. The existing
G4/private Game plans retain author-owned placements and exact testing limits;
this completion is not exhaustive map dressing or other-platform acceptance.
Extend the existing Engine/Editor contracts; do not introduce another gameplay
model, a parallel map runtime or a speculative full editor suite.

### Stage 1 - Independent graphical ground scale

Give graphical maps their own ground-cell scale and camera projection, separate
from terminal font cells and glyph aspect ratio. Map coordinates, collision,
routes, interactions, event positions and saves retain their existing logical
meaning. Rendering consumes that state; it must not resize terminal geometry or
change movement timing to make artwork fit. Define the shared graphical scale
contract and its validation rather than applying per-map drawing workarounds.
Define shared physical occupancy independently of presentation identity before
building new authoring tools. Terminal glyphs and graphical resource placements
must both use that space without choosing one another. Preserve existing maps
through a tested compatibility adapter for glyph-keyed inputs, and an explicit
source-preserving migration. A purely graphical edit must leave glyphs and
resolved collision unchanged; a purely terminal appearance edit must leave
graphical placements and intended physical occupancy unchanged.

The shared `occupancy` declaration, runtime/preview/reachability consumption,
explicit source-preserving GUI conversion and geometry/history preservation are
implemented. The [map contract](maps.md#physical-occupancy)
defines absence-only compatibility and malformed-data refusal. No production map
is silently converted. The GUI Collision tool now uses shared physical-cell
read, paint and fill services, the existing brush shapes, a constrained collision
picker and an editor-only overlay. Source-preserving save/history and stale
revision or cancelled-gesture refusal have automated coverage. Reusable piece
recipes and explicit Collision-tool footprint stamps now use the same source,
history and physical-cell authority. Recipes never apply during glyph/art
placement; mixed types and null cells are supported without inferred defaults.
Preview and stamping validate the whole recipe against the actual map geometry
and the displayed map/recipe revision. October 12 macOS native checks qualify
collision painting and a mixed open/solid/counter footprint, including null-cell
preservation, reviewed old-save landings, grouped Undo and whole-stamp refusal at
the map edge. The disposable source-preserving clone was removed after the
receipt was retained. These bounded checks do not establish every brush gesture
or other-platform acceptance; see the G4 integration-roadmap evidence.

### Stage 2 - Sprite pivot, ground footprint and depth

Use a centre sprite drawing pivot independent of the collision ground footprint.
Keep the drawing pivot, ground placement and ground-Y depth-sort key explicit
and separate. Replacing art, changing its dimensions or animating a pose must
not move the gameplay position, alter collision or change event reach. Sort
overlapping actors and environment pieces by ground Y within their explicit
draw-priority bands, with stable ties. Do not use image height, crop origin or
terminal row dimensions as an implicit collision footprint or depth key.

The explicit pivot/footprint and stable ground-Y contracts are implemented.
October 12 native runtime captures qualify two actors exchanging front/behind
canopy rows, repeatable equal-row overlaps and doorway foreground/clear states.
These six discrete observer states do not claim smooth motion, all artwork or
same-band sign/actor overlap; physical walking acceptance is separately recorded
in the existing G4 checklist. No production Renderer change was needed.

### Stage 3 - Connected modular environment kits

Support connected wall, floor, exterior and cave kits through reusable tile
families: interiors, edges, corners, ends and transitions must compose without
isolated patches or one-off room-sized art. Art and family membership are
replaceable project resources; the Engine owns generic connection/composition
rules, not Last Legend paths or visual style. Preserve existing artwork and
bindings while assembling and checking the first representative kits.

RPG Maker tile/autotile composition and explicit piece recipes provide this
shared mechanism. CPU coverage includes all 47 floor-blob variants; the bounded
October 12 GUI/runtime cave proof qualifies a selected cardinal rock/floor
family, not every concave shape or every production material.

### Stage 4 - GUI authoring

Superseded. The [graphical field plan](graphical-field.md) replaced named
tile families with RPG Maker MZ tilesets and tileset pieces, and the GUI
Editor plan (`gui-editor/docs/plan.md`) owns the graphical frontend, the shared
Editor session, the `ichiloto edit` TUI/GUI choice and the remaining GUI
authoring gaps. The graphical-marker and crop workflow is removed from the
TUI; shared artwork services and authored data remain.

### Stage 5 - Home proof, then wider conversion

Use Kaelion's Home as the first end-to-end proof of scale, sprite placement,
connected kits and GUI authoring. Preserve existing art, map/event identities,
event behaviour and saves. Compare terminal composed output and collision
before and after; a presentation correction must leave them unchanged. Any
intentional gameplay geometry change is separate work through the established
save-migration process, not an incidental graphical conversion.

Only after the Home proof passes, extend the same reusable contracts to wider
map conversion, including exterior and cave examples. Retain existing phase
history and migration safeguards; do not discard assets, events, save data or
unfinished work to simplify the proof.

The Home proof and representative wider interior, exterior and cave conversions
have the bounded native and preservation evidence in the G4 checklist. Game's
current coverage ledger separates playable maps from disconnected prototypes
Andrew explicitly keeps untouched. Material backing is not complete map
dressing, and authored placements remain separate from mechanism acceptance.

### Stage 6 - Full tests and visual proofs

Run the full relevant Engine, Editor and reference-game suites, covering source
refusal and round trips, transactions/undo, scale isolation, pivot/footprint
independence, ground-Y ordering, family connections and passage/draw-priority
independence. Verify terminal editing regressions, composed output, collision,
events and save compatibility, including preservation of graphical metadata
after a terminal-only edit. Use replaceable synthetic assets rather than
freezing mutable production artwork.

Provide graphical GUI and runtime visual proofs for Home before wider
conversion, then representative connected interior, exterior and cave maps.
Check moving actors against walls, corners, entrances and overlapping objects;
include terminal proofs that editing and play remain unchanged. Verify music
and effects are muted before any native playtest. Report executed suites,
skips and observed platforms accurately: headless checks, art previews and
macOS observations do not establish Linux/WSLg or Windows validation.

The recorded complete suites pass: Engine 8,614 tests / 459,759 assertions,
Editor 2,499 / 107,376 and Game 2,002 / 1,817,759, retaining Game's existing
battle-simulations exclusion and the exact candidate refs/receipts in the G4
checklist. Later focused runs and native pixel checks do not relabel those
dated full-suite results. Native save-loading, all brush/input gestures,
InnStay payment/recovery, frame-perfect smoothness and other-platform testing
are not claimed. Unchanged testing limits are not new implementation scope.

### Stage 7 - Terrain kinds for classic world maps

Target: world maps that read like classic JRPG and RPG Maker world maps, built
the way those maps are built, with a terminal language that maps onto them one
to one. Andrew, 23 October 2026: "The trick is establishing a visual language
for terminal that can translate into these tiles. So we need pieces that span
the same tilesizes... we have the target being RPGMaker and Classic JRPG
overworld maps... so how can we represent that consistently in the terminal?"

How the target is built: one 48 px grid; layer one paints terrain kinds as A2
autotiles (ground kinds such as grass, desert, snow, dirt, and terrain features
such as forests, conifers, hills, mountains, snowy peaks, rocky ground, marsh),
and the autotile derives edges, ridges and overlaps from same-kind neighbours;
layer two places B objects (towns, castles, towers, shrines, bridges, cave
mouths) as stamps of whole cells. A cave or mine is a cave tile on the edge cell
of a mountain range.

The Ichiloto contract:

1. One terminal cell is one graphical tile, as already established, so every
   piece spans the same cells in both presentations.
2. A terrain kind is a shared, Editor-authored tileset concept with a stable id:
   its terminal glyph and colour, its graphical autotile, its passability, its
   encounter terrain, and the ground kind it sits on when it is a feature (trees
   on grass or on snow). The kind is the identity; the glyph and the autotile are
   its two independent presentations. Painting a kind writes both, through the
   existing piece mechanism. Neither the glyph nor the colour becomes a graphical
   tile identity.
3. Passability and encounters come from the kind, so a cell's collision and its
   art come from one authored decision and cannot disagree. The passage
   agreement validator confirms it for every map.
4. Objects are stamps whose terminal pattern covers exactly the cells their art
   covers, with a marked entrance cell; the passage agreement validator checks
   coverage.
5. The terminal glyph names the kind by shape, not by drawing edges: the
   terminal never renders transitions, the graphical autotile does.
6. Existing glyph-keyed maps convert through an explicit, source-preserving
   migration with reachability and save checks, never by silent
   reinterpretation.

Last Legend's kind table and conversion are recorded in its overworld decision.
Art supplies complete World A2 and B sheets in the game's own style, in the
RPG Maker layout and sizes; the Engine owns only the generic kind contract.

#### Implemented contract

A tileset declares `kinds` beside its `pieces`, keyed by kind id (ids share
one namespace with piece ids, since editors place both by id):

```php
'kinds' => [
  'grassland' => ['name' => 'Grassland', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>',
    'art' => ['layer' => 'ground', 'tile' => 2816], 'passage' => 'walk', 'encounters' => 'grassland',
    'atlas' => 'ground', 'keeps' => ['substrate']],
  'woodland' => ['name' => 'Woodland', 'layer' => 'terrain', 'glyph' => '<fg=green>♣</>',
    'ground' => 'grassland', 'art' => ['awaiting' => true], 'passage' => 'walk',
    'encounters' => 'woodland', 'atlas' => 'vegetation'],
],
```

- `glyph` is one visible one-cell character in its terminal colour; the colour
  is required because painting writes it. `art` is one autotile (A1 to A4) and
  the tile layer it is painted on, or `['awaiting' => true]`, the explicit
  state of a kind whose autotile is still to be drawn. A kind awaiting art
  draws no tiles at all, its ground's and kept layers included, so its glyph
  shows in the graphical field until the autotile is bound; validation lists
  it (`terrain.awaiting-art`, and per tileset in the Editor) instead of
  reporting a missing presentation.
- `passage` is `walk` or `solid` on foot. Vehicle passage is not authored yet;
  it will be a separate per-vehicle entry, leaving `passage` the passage on
  foot. `encounters` names an encounter terrain, shared by the kinds whose
  troops it shares (two kinds of trees can both meet `woodland` troops); absent means none,
  and a solid kind has none. `atlas` is the map-screen role. `ground` names
  the ground kind a feature stands on, on the same gameplay layer and painted
  on a tile layer below the feature's; `keeps` lists tile layers left beneath.
- Each kind compiles to a one-cell `TilesetPiece` (its glyph; its autotile and
  its ground's), so the existing piece mechanism paints it, binds glyph and
  tiles, moves them together and undoes them.
- Refused: unknown keys, a glyph that is not one coloured cell, an id shared
  with a piece, a plain or missing-sheet tile, a feature standing on a feature
  or on another layer, two kinds drawing one autotile, two kinds looking the
  same in both presentations, and kinds sharing a glyph on one layer that play
  differently (the terminal names a kind by its glyph's shape, never colour;
  Last Legend's brown mountains and white snowy peaks share `^` because both
  are solid highland). A tileset's `appearance` entry for a kind's autotile
  that contradicts its passage is refused; the kind classifies its own
  autotile (walk shows ground; solid shows water on A1 and a barrier elsewhere),
  so passage agreement holds by construction.

#### Decision: kinds feed the existing collision authority

Kinds never become a third passage authority beside declared occupancy and the
glyph dictionary. Decided 23 October 2026 from the existing architecture:

1. A map opts in explicitly with `'terrainKinds' => true` in its data file.
   A map without it is never reinterpreted, so authoring a tileset's kinds
   changes no existing map (Last Legend's undeclared overworld keeps its
   `:` dry scrub solid while the tileset's desert kind is walkable).
2. On an opted-in map without declared occupancy, the kinds feed the glyph
   dictionary: each kind layer gets a section holding its kinds' glyphs and
   the collision their passage and encounters give (`SOLID`, `ENCOUNTER` or
   `NONE`), resolved by the same topmost-wins, pass-through walk as every
   glyph. A named section already overrides the flat dictionary for its
   layer, so the kind decides its glyph there. A `collisions.php` section for
   that layer may repeat a kind's glyph only with the same collision;
   otherwise the map refuses to load, naming both.
3. On an opted-in map with declared occupancy, occupancy stays the only
   passage source. Painting a kind writes its collision into the occupancy
   cells where its layer is the top gameplay layer, in the same undo step, so
   collision and art still come from one decision.
4. Encounter terrain and atlas roles come from the same kinds through the same
   glyph walk. Whether a step counts stays the cell's collision (an encounter
   tile); its terrain chooses among troops limited to `terrains`.
5. `MapCollisionResolver::resolveMap` refuses (as a programming error) to
   resolve an opted-in map without its kinds, or an undeclared map with them,
   and the runtime, reachability, save relocation, cutscene previews and the
   Editor all pass them. An opted-in map whose tileset cannot be read is
   refused rather than played with unknown passage.

Glyph lookup now reads a glyph's own dictionary entry before its ASCII
transliteration, so entries such as `♣`, `·`, `▲` and `┃` are read as authored
instead of as the empty transliteration they all shared. Every existing Last
Legend and Epic Quest map resolves to the same collision as before.

#### Editors and conversion

- TUI: `K` on the canvas opens the kind picker for the layer being edited,
  showing glyph, passage, encounters, ground and awaiting art, and opening on
  the kind under the cursor. A chosen kind paints like an item piece (`Enter`
  paints and anchors, `Enter` again fills to the cursor, drag fills).
- GUI: the tile palette's Kinds tab lists the same kinds; a drag paints the
  area through `kinds.paint`, right-click picks up the kind under the pointer.
- Painting a kind makes the cell's terrain the kind's alone: it clears the
  tile layers the kind's gameplay layer owns, except kept layers, then writes
  its glyph in its colour and its tiles, source-preserving, as one undo step.
- Kinds are authored in Database > Tilesets (Terrain Kinds), with the ground
  chosen among the tileset's ground kinds.
- `MapTerrainKindRule` reports cells whose glyph and tiles show different
  kinds (`terrain.disagreement`) and cells of kinds awaiting art, as warnings.
- Conversion: `ichiloto map:convert-kinds <map> --kind ';=grassland' ...`
  (session `kinds.previewConversion` / `kinds.convert`) lists the map's
  glyphs, previews each mapping's cell count and the collision it changes,
  refuses to leave a glyph that already names a kind unmapped, then opts the
  map in and paints every mapped cell through the Editor's validated change
  set: reachability and every map rule are checked, and saves standing where
  the map becomes solid move only with `--relocate-saves`, through the
  existing save-relocation manifest step. `--check` writes nothing.

Remaining GUI capability, recorded here rather than claimed: choosing a kind's
autotile by clicking the A1/A2 sheet in the GUI tileset view (today the GUI and
TUI Database rows take the tile identity), and a GUI conversion dialog over the
same session requests the Console uses.

Peaks and hill crests stand over the mountain and hill kinds as stacked
pieces ([graphical field](graphical-field.md), Stacks): World B to E stamps of
whole objects that overlap in depth order on a tileset stack, decoration only,
so the kind stays the passage authority. Last Legend's overworld and Garden of
Roads are painted in kinds with its approved World A2 and relief art; its
overworld decision records the conversion.

## Open compatibility item

1. **Legacy path retirement.** Once Last Legend is fully migrated, does the
   engine's single-`.map.php` support get removed, or does it remain for
   `epic-quest` and other example projects until they migrate as well?

## Retained product invariants

- Layered tilemaps are engine functionality, available to every game built on
  Ichiloto. This plan lives in the engine's docs; game docs hold only each
  game's own vocabulary and layer conventions.
- Terminal grids are literal nowdocs, one file per layer. No executable map
  builder objects in game assets. `AsciiMap` remains deleted.
- Existing layers retain integer-prefixed, renamable filenames in `layers/`.
- Legacy collision resolves topmost occupied glyphs with `PASS_THROUGH` and
  per-layer glyph dictionaries. Preserve its resolved occupancy through migration
  to shared physical data; do not perpetuate glyph identity in graphical resources.
- Neither colour nor a terminal character is a graphical tile identity.
- Decoration layers exist, are terminal-invisible, and are structurally barred
  from gameplay.
- Graphical authoring belongs in renderer-specific GUI editors; the TUI remains
  a terminal editor. Shared identity and safe source round trips remain intact.
- The original layer migration covers the `AsciiMap` maps and Milestone 1-3
  use cases. Graphical correction proceeds through the Home proof before wider
  conversion; it does not restart or undo delivered layer migrations.
