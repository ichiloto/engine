# Layered tilemaps - authoritative plan

This plan replaces the engine's single-grid map model with authored map
layers, restores the HEREDOC authoring contract that the editor depends on,
and removes object-oriented map builders from game assets. Layered tilemaps
are an **engine capability**: every game built on Ichiloto gets this
functionality. Last Legend is the reference consumer and supplies the first
full migration; its game-owned vocabulary and layout policy live in its own
`docs/development/map-visual-language.md`.

The plan was decided by the author; the sections below record those decisions
and the implementation phases. Related engine docs: [maps.md](maps.md),
[rendering/tile-batches.md](rendering/tile-batches.md).

## Principles

1. **The terminal always represents the whole game.** Everything a player must
   know to play - geometry, collision, interactions, routes - is expressed in
   glyphs the terminal displays. GPUI and any future renderer derive from the
   text, never the other way around.
2. **Three channels, never overloaded:**
   - **Layer + glyph = identity.** What a cell *is* comes from its character
     and the layer it sits on. The same glyph may mean different things on
     different layers (`/` can be a roof slope on a buildings layer and a
     hanging blade on a fixtures layer) because each layer has its own
     vocabulary.
   - **Colour = attention.** Colour is spent sparingly, only on information
     that needs the player's eye: encounter grass, water, information points,
     event cues. Colour never carries tile identity - yellow terrain cannot
     tell sand from savannah, and it should never have to.
   - **Crop = fidelity.** Visual richness (checkered kitchen tiles, rugs,
     wall paintings, isometric facades) lives in per-layer atlas crop tables,
     keyed off authored glyphs. Fidelity is sugar on top; it costs the
     terminal nothing and the terminal costs it nothing.
3. **The map text is the terminal display.** Gameplay layers contain exactly
   what the terminal shows. No glyph in a gameplay layer may secretly mean
   something other than what it displays. Presentation-only detail goes in
   decoration layers, which the terminal never consults.
4. **RPG Maker is the model, adapted.** Flat 2D grids; passability belongs to
   the tile definition (the collision dictionary), not the map; resolution is
   topmost-tile-wins with a pass-through marker; the pseudo-isometric look is
   tileset art, not map geometry. Hand-drawn 3/4-view ASCII facades already
   encode the projection in text; GPUI crops render it.

## Why the current model must change

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

## Authoring format

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
validated before `.data.php` is evaluated. The Editor refuses a noncanonical
grid on load and rechecks existing grid sources before save, duplicate or
move, including unchanged grids. Refusal happens before file transactions and
leaves authored bytes untouched. Executable grid source and `string[]` grid
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

Resolution is **topmost-occupied-wins with pass-through**, the RPG Maker rule
translated to glyphs:

- For each cell, consult gameplay layers from the top down. Skip empty cells
  (spaces on non-base layers) and pass-through cells. The first remaining
  glyph decides the collision via its layer's dictionary. Layers below it are
  never consulted.
- `CollisionType::PASS_THROUGH` is the `☆` equivalent: "skip me, consult the
  layer below." An awning over encounter grass keeps its encounters; a bridge
  over water is walkable. Pass-through tiles are also the future candidates
  for rendering above the player (a batch above `WORLD 0`), as RPG Maker draws
  its star tiles - a later, purely presentational extension.
- The game's `collisions.php` stays backward-compatible: a flat array applies
  to all layers; optional sections keyed by layer name (`'terrain' => […]`,
  `'fixtures' => […]`) give a glyph different meanings per layer. Unknown
  glyphs still default to `SOLID`.
- Collision is always *derived* from the visible text through the dictionary -
  never stored beside it. There are no per-layer collision grids.

## GPUI presentation

- `tiles2d` becomes per-layer symbol tables (shared or per-layer atlases).
  Keys are bare glyphs, normalised exactly as `GraphicalTileDefinition` does
  today - no style-aware lookup.
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

## Phases

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
   following the Milestone 1–3 use cases.
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

## Open items needing the author's decision

1. **Legacy path retirement.** Once Last Legend is fully migrated, does the
   engine's single-`.map.php` support get removed, or does it remain for
   `epic-quest` and other example projects until they migrate as well?

## Decisions already made (do not relitigate)

- Layered tilemaps are engine functionality, available to every game built on
  Ichiloto. This plan lives in the engine's docs; game docs hold only each
  game's own vocabulary and layer conventions.
- Maps are HEREDOC/nowdoc character grids, one file per layer. No map builder
  objects in game assets, ever. `AsciiMap` is deleted.
- Layers live in `layers/` with integer-prefixed, renamable filenames.
- Collision: topmost-occupied-wins with `PASS_THROUGH`; per-glyph dictionaries
  per layer; no per-layer collision grids.
- Colour is an attention channel, never a tile-identity channel.
- Decoration layers exist, are terminal-invisible, and are structurally barred
  from gameplay.
- Migration covers everything, starting with the `AsciiMap` maps, then the
  Milestone 1–3 use cases.
