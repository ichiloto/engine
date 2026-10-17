# Maps, regions, and the map screen

A map lives in its own directory under `assets/Maps`. Its data and event files
are named after the directory; its visible geometry can use ordered layers:

```
assets/Maps/village/harbour/
  harbour.data.php
  harbour.event.php
  layers/
    01.ground.map.php
    02.floor.deco.php
    03.structures.map.php
    04.objects.map.php
```

The directory path is the map's **id**: `village/harbour`. That is what
`destinationMap` names, what save files record, and what the map screen uses.
Layer names and the choice of layers belong to the game, not the Engine.
A simple layered map needs only one gameplay layer.

Maps without a `layers/` directory continue to load `<name>.map.php` beside
their data and event files. This legacy format remains supported. An existing
but empty or invalid `layers/` directory is an error, not a request to fall back
to the legacy grid.

## Grid source format

Every `.map.php`, `.deco.php` and `.event.php` file returns one literal nowdoc string.
For example:

```php
<?php

return <<<'TOWN_MAP'
####
#  #
####
TOWN_MAP;
```

A map cell is one terminal character. Coordinates in map data, events,
cutscenes and saves count those cells, and the player moves one cell per step.
A graphical renderer keeps this grid: it draws each cell in the terminal's own
tall shape (see [graphical field](graphical-field.md)).

The delimiter may be any valid nowdoc label. Comments and whitespace outside
the return are allowed; executable statements, builders, calls, interpolated
heredocs, arrays and additional returns are not. The Engine parses all grid
files without executing them, before it evaluates `.data.php`. The Editor uses
the same parser. An invalid map remains listed with a source diagnostic but is
read-only; other maps still open and validate. A repaired map becomes editable
after reopening the project. The Editor rechecks the source before saving,
duplicating or moving a map, so an external edit cannot be silently rewritten.
An unchanged canonical grid keeps its source bytes on save. A failed Game map
transfer leaves the current map and player position intact.

Executable PHP and `string[]` grid values are no longer supported. Map metadata
in `.data.php` and the project's `collisions.php` remain executable PHP data
sources; they are not grid files.

### Event markers

An event is placed by painting its marker character on the `.event.php` layer
and defined under that marker in `.data.php`. The event occupies exactly the
cells showing its marker, in any shape: a line, an L, or separate placements
such as a town exit on the east edge and another on the south side, which
are one event with one identity, conditions and completion. Cells between
two placements are not part of it, so standing there triggers nothing and an
unavailable event's `whenBlocked` gate only stops steps onto its own cells.
A cue shows on each separate placement. Cinematics that name a marker as a
subject use its first cell in reading order, the top-left of a rectangle.

## Active-map landmarks and discovery

Andrew selected a GPUI corner atlas with compact and larger sizes, and an opened
scrollable map with focused location details. His 17 October review rejected the
forced whole-map local fit and regional connection diagram. Local views now use
physical map geometry and a separate map camera. GPUI local maps offer two zoom levels,
both farther out than gameplay; global maps fit the whole geography at the
zoomed-out level and allow a closer, scrollable level; these are independent of the compact/larger HUD
sizes. The opened map pans in both axes. Terminal retains its coordinate HUD;
opened maps use blank walkable ground, `-`, `|`, `+` physical boundaries, `v`
entrance/exit chevrons locally and single-block place markers globally. `@`
marks the player. Decorative field glyphs and miniature Terminal art are absent.
GPUI keeps simplified map artwork and dedicated place-category pictograms.

`landmarks` in a map's `.data.php` is a list of at most 256 declarations:

```php
'landmarks' => [
    [
        'id' => 'harbour-gate',
        'kind' => 'exit',
        'anchor' => ['event' => 'G'],
        'name' => ['message' => 'maps.harbour.gate', 'default' => 'Harbour Gate'],
        'approachDistance' => 3,
    ],
    [
        'id' => 'old-tower',
        'kind' => 'building',
        'anchor' => ['object' => 'tower-sign'],
        'name' => ['message' => 'maps.harbour.tower', 'default' => 'Old Tower'],
        'revealConditions' => [['type' => 'event', 'name' => 'heard_of_tower']],
    ],
],
```

The map-scoped `id` is a stable lowercase identifier of 1–64 characters. Kinds
are `passage`, `entrance`, `exit`, `building` and `landmark`. An anchor references
one existing local event marker or stable world-object id. It never duplicates
coordinates or names a destination spawn. Old legacy map transfers may be given
a stable `id` and referenced with `['trigger' => 'back-door']`. Moving the owning
event/object moves the landmark; deleting an anchor requires reference repair.
Each disconnected event placement gets a marker centred on one of its own cells.
One event/legacy transfer can anchor one landmark. Route kinds must reference a
transfer; a transfer landmark must retain a route kind. A separate building may
use its sign object as its anchor.

All ordinary transfer events and legacy map triggers receive anonymous passage
markers automatically, including gated routes. Explicit landmark metadata
replaces that marker. Destination names are never inferred. Route markers are
visible immediately; names and non-route landmarks are learned by approach or
authored play revelation. `approachDistance` defaults to 3 physical Manhattan
cells and accepts 0–64 or `null` to disable approach discovery. This distance is
independent of the renderer, viewport and zoom.

`secret => true` hides the marker and ignores approach discovery. Only explicit
play revelation can reveal it; a secret declaration must omit `approachDistance`.
Use
a nonempty `revealConditions` list of up to 32
shared world conditions, or `GameState::revealLandmark($mapId, $landmarkId)`.
Revelations latch in saved `knownLandmarks[mapId][landmarkId]`, survive map visits
and save/reload, and never unlock the route. Transfer access conditions remain
separate and may show a known route as locked. Old saves begin without individual
landmark knowledge; map visitation alone does not reveal names. Presentation
reads knowledge and never causes a discovery.

Runtime uses `MapLandmarkCatalog::readMap($resolvedMap, $tiles)`. Editor validation
uses `readDeclarations($entries, new MapLandmarkAnchors($events, $objects, $triggers),
$rowWidths)` on unsaved source. Each table maps a source identity to
`new MapLandmarkAnchor($cellArea, $isTransfer, $accessConditions)`; `rowWidths`
contains each real row's cell count, preserving ragged maps. Both use the same
validation and automatic-route generation. Public catalog constants define the
id pattern, limits and approach default. `getNameMessageKey($mapId, $landmarkId)`
supplies a bounded map-scoped translation key using the map identity's SHA-256;
authors may supply another valid localized message id. Saved landmark ids remain
immutable during ordinary editing. Existing world-condition flags provide
authored revelations; no new script command is introduced in this slice.

Map scope and physical parent references live in map data, independently of
its filename, tileset and free-text region:

```php
'atlas' => ['scope' => 'global'], // on an actual overworld map
// On a local map, e.g. an interior inside a town:
'atlas' => ['parent' => ['map' => 'harbour', 'landmark' => 'home-entrance']],
```

`MapAtlasDefinition::readMap` validates scope (`local` by default) and the optional
parent. Global maps cannot have parents. `resolveGlobal($mapId, $readMap,
$categories)` accepts a resolver returning `data` and `tiles`, including unsaved
Editor models, and follows at most 32 parent maps. It refuses cycles, missing
maps and missing parent landmarks. The landmark may be a route or a place; its
existing physical area supplies the representative position. The last parent
entry is the marker on the world map. No spawn points or duplicate coordinates
are used. `readGlobalMaps` enumerates explicitly global sources for browsing.
A local area without a proven world entry can still browse those maps; it has
no invented player position and says that its current location is not marked.
Physically global maps open only their global view.

The semantic `map_view` action (unclaimed V/v by default) switches Local/World.
The opened map always shows its primary action bar, using the shared required
control treatment even when the theme hides optional hints. Corner maps carry no
location-name header, legend or hint strip.
Directional actions scroll without moving the player. Page actions cycle through
visible landmark identities and centre the chosen marker; names appear only for
the focused marker. Ordinary scrolling clears focus. Info pages long names;
`map_recenter` (Home) returns to the player. GPUI `map_zoom` (Z/z) switches exactly
two map scales. These actions use shared bindings and hints. Map drawing never
causes a discovery or a transfer, installs events, or starts background music.

A landmark's optional `category` describes a place independently of route kind.
Generic categories are `inn`, `shop`, `home`, `organization`; shared defaults draw
bed, bag, house and columned-building pixel pictograms in one batched layer.
Unknown ordinary routes keep generic chevrons until learned. Projects may add
categories in `Data/map-categories.php`:

```php
return ['schema' => 'ichiloto.map-categories/1', 'categories' => [
    'guild' => ['base' => 'organization',
        'name' => ['message' => 'map_categories.guild.name', 'default' => 'Guild']],
]];
```

`MapCategoryCatalog` validates at most 128 project categories, supplies generic
bases, localized names, stable name keys and round trips. Generic IDs cannot be
replaced. Pass the catalog as the optional last argument to landmark `readMap`
and `readDeclarations` to validate project category references. Missing category
stays absent. Optional theme icon roles `map.category.<id>` replace individual
category pictograms. Local route chevrons and global squares are scope-aware;
a settlement entrance keeps its entrance kind.

The optional `atlas` treatment in `Data/Presentation/menus.php` reuses the shared
palette, replaceable `quiet`/`panel` frames and optional semantic icon bindings.
Its keys are `compactWidth` (256), `compactHeight` (192), `largeWidth` (400),
`largeHeight` (300), `margin` (16), `markerSize` (10) and `frameRole` (`quiet`).
Dimensions accept 96–768 logical pixels; larger dimensions cannot be smaller
than compact dimensions. Margin accepts 0–64 and markers 4–24. The two corner sizes
are maximum extents: the whole map preserves its aspect and fills a one-pixel
palette rim. Its frame hugs the map, with no menu padding or reserved text rows;
menu typography and spacing apply only to the opened view. Opened maps scroll at a fixed
map scale; changing map size or zoom never changes field zoom.
Optional icon roles are `map.player`, `map.passage`, `map.entrance`, `map.exit`,
`map.building` and `map.landmark`. Missing art uses shared geometric marker
treatments. `MapAtlasPresentation::listIconRoles($categories)` supplies these roles
and every generic/project category role to theme authoring; `PLAYER_ICON_ROLE`,
`MapLandmarkKind::getIconRole()` and `MapCategoryCatalog::getIconRole()` are the
shared identities consumed by the composer. Projects supply data and artwork;
the Engine owns drawing and input.

GPUI uses the same installed retained world as a static overview source. The
corner atlas replaces the coordinate HUD only after successful composition and
while the field owns presentation; dialogue, menus, scene suspension and the HUD
setting keep their existing ownership. Terminal keeps coordinates. The semantic
`map_size` action (F3 by default) cycles compact/larger; Config exposes Corner Map
Size and persists `ui.hud.map_size` in player settings. Opened mode is temporary.
The opened atlas retains its selected source world without selecting a field
viewport or field actors; ordinary menu canvases release that dependency.

The optional negotiated `map_overview` world-composite operation reuses prepared
map tiles and up to 128 map-owned still groups / 1024 child images. It freezes
animated resources at a resting pose and preserves physical anchors, crops,
pivots, layers and lift. Raster output is at most 1024 by 1024, with 16 MiB of
live overview output/key storage, two-times sampling and 256 Mi bounded cold
work. A frame requests one overview. The additional negotiated
`map_overview_viewport` capability admits a `source` rectangle in physical map
cells. Source geometry must be finite, positive and inside the actual world.
Panning/zoom changes its bounded cached raster; marker movement reuses it. `canvas_composite_indicators` separates live
player/landmark markers from this cached raster. Map revision and current decoded
image identity invalidate it; movement only changes indicators. Generic glyph-only
maps use their presentation colours. Neither collision nor location meaning is
inferred from graphics. Existing composite limits remain unchanged.

The earlier installed macOS checks covered HUD ownership, discovery and safe
save/reload/revisit behavior; Andrew rejected both map presentations afterwards.
The scroll/zoom/local-world/category revision passed 134 focused Engine tests
with 1480 assertions and 12 focused Renderer overview/viewport/wire tests. Game's
two CPU modes passed 2 tests / 63 assertions in 12.46 seconds, including mandatory
opened controls. The later scope-legend/helper correction passed 26 focused
symbol/presentation tests (overlapping suites; counts are not additive).
The final installed macOS GPUI run on Renderer `a5c1e41` completed 2598 frames /
434 presentation changes in 81.895 seconds, exit 0. Both scales, local/world
switching, global-only display, focused details, discovery and save/revisit checks
passed. The action bar, zoomed local view and full-world geography/legend were
visually inspected. Music, effects and voice stayed muted; audio backends were
absent, user files stayed unchanged and temporary storage was removed.
Terminal's revised native appearance still needs Andrew's review; its captured
functional/symbol checks passed.
Walk animation is accepted by Andrew. Editor/GUI authoring and Game metadata
are coordinated with their existing owners; final dependency integration follows
the Engine commit. No publication is authorized by
this implementation. Linux/WSLg native presentation has not been verified.

## Layer order and composition

Layer filenames have the form `NN.name.map.php` for gameplay or
`NN.name.deco.php` for decoration. The two-digit order is in `00` through `99`,
and names start with a letter followed by letters, digits, underscores or
hyphens. Names and order numbers must each be unique within a map. Files are
discovered from `layers/` and sorted by their numeric prefix; there is no
second layer list in `.data.php`.

All layers and the root event grid must have the same number of rows and the
same number of logical symbols on each corresponding row. Existing ragged
maps are supported: one row may be shorter than another, but that row must have
the same width on every layer. Do not pad a migration just to make it rectangular.
Colour markup is not a cell; wide glyphs retain their existing logical-cell and
terminal-display behavior.

The lowest gameplay layer is the base. Higher gameplay layers replace it only
where they contain a non-space symbol. A space on an upper layer, including a
styled space, is empty and shows the lower layer. The topmost occupied cell
supplies the complete styled symbol. This composed grid is `Camera::worldSpace`
and is exactly what the terminal renders.

Decoration is never composed into that grid and never contributes collision.
A decoration layer named in the collision dictionary refuses the map with a
diagnostic. Interactive objects must be gameplay glyphs or events, never hidden
decoration. Decoration has no graphical presentation of its own; a graphical
renderer draws a map from its tile layers (see Graphics). The glyph-keyed tile
crops (`tiles2d`) are retired: a map data file that still has `tiles2d` loads
with a warning and shows its terminal glyphs.

## Graphics

A graphical renderer draws a map from its own tile layers, independent of
its terminal glyphs. The map names an RPG Maker style tileset in its data
file and keeps one tile identity per terminal cell in `graphics/`.

Tilesets are grouped by the kind of setting they draw, as RPG Maker groups
its own: one for overworld maps, one for exteriors (towns, fields, roads),
one for interiors (homes, inns, offices) and one for dungeons (caves, ruins,
crypts). That setting is the map's kind: every map has one, chosen when the
map is created, and every map of a kind names the same tileset, so a tileset
never belongs to one map or one place. The tiles a map can use change only
when its kind does. The Editor asks a new map's kind, shows it as the
Inspector's Kind, and warns about a map without one in a project that has
tilesets; `ichiloto generate:map` takes it as `--kind`.

A tileset's sheets are sorted as RPG Maker sorts them: A1 to A5 for
structure (water, ground, building exteriors, walls, plain floors), B for the
main objects, and C to E for more. A tile's identity is its place on its
sheet, so new art goes into free cells and existing art never moves.

```
assets/Data/Tilesets/interior.php      # name, sheets A1 to E, above, tables
assets/Maps/village/harbour/
  harbour.data.php                      # 'tileset' => 'interior'
  graphics/
    01.floor.tiles.php
    02.furniture.tiles.php
```

```php
<?php

return <<<'TILES'
2816 2816 2816 2816   0   0
2816 2816 2816 2816  42  43
TILES;
```

Each tile layer is a literal nowdoc with one row per map row and one
whitespace-separated RPG Maker tile identity per cell (`0` is empty); it is
never executed. A field cell is one whole RPG Maker tile. An autotile (such as
floor 2816) is composed for its own cell from its neighbours, so an area is
painted in every cell; any other tile, such as the table above, is drawn whole
in its cell. An entry naming half a tile (`42L`) is refused, with a message
asking for whole tiles. The map data may shift a whole tile layer by half a
cell across or down
(`'tileLayers' => ['lounge' => ['offset' => [0, -0.5]]]`) so art can sit between
the cells its terminal footprint allows, and may name the gameplay layer a tile
layer belongs to (`'floor' => ['movesWith' => 'buildings']`): editors move its
tiles with that layer's glyphs, and the graphical field hides only that layer's
glyphs under its tiles. Without a setting, a tile layer belongs to the gameplay
layer whose tileset pieces write it, when exactly one does.
Graphics never change
geometry, collision, events or saves, and unusable graphics or sheets are
reported while the map shows its terminal glyphs. Autotile shapes are stored in the identities, as RPG Maker
stores them; `AutotileShape::resolveLayer` chooses them from neighbours for
authoring tools. See [graphical field](graphical-field.md) for sheets, draw
bands and animation.

## Physical occupancy

A map may explicitly declare `occupancy` in its `.data.php`. It contains
zero-based row lists of resolved `CollisionType` cases, with exactly the same
row lengths as the logical map, including ragged rows:

```php
'occupancy' => [
    [CollisionType::NONE, CollisionType::COUNTER, CollisionType::SOLID],
    [CollisionType::NONE, CollisionType::NONE, CollisionType::SOLID],
],
```

This declaration is the sole static passage source for both renderers,
reachability and shared field previews. Terminal appearance and graphical
resources do not choose physical cells. Integer cells, mismatched dimensions
and unresolved `PASS_THROUGH` cases are refused; a malformed declaration never
falls back to glyph-derived passage.

Only an absent `occupancy` key selects the compatibility rules below. Ordinary
loads and saves never migrate a map. The GUI's explicit **Separate collision**
action captures the current resolved cells through the shared Editor session,
without changing either presentation or current passage. It is source-preserving,
undoable and saved through the existing transaction. Later glyph/layer appearance
edits no longer change collision on a converted map. Resize and row/column
insertion carry its physical cells with geometry and make newly added cells
`SOLID`; maps without the declaration remain undeclared. Physical painting and
object-footprint authoring remain separate GUI capabilities, not implied by this
conversion control.

## Collision dictionaries

The game's `assets/Maps/collisions.php` can combine a flat glyph dictionary with
optional sections keyed by layer name:

```php
<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;

return [
    ' ' => CollisionType::NONE,
    '#' => CollisionType::SOLID,
    ';' => CollisionType::ENCOUNTER,
    'structures' => [
        '=' => CollisionType::NONE,          // A bridge overrides the ground.
        '^' => CollisionType::PASS_THROUGH,  // An awning inherits the ground.
    ],
];
```

A named section overrides the flat dictionary for that layer; otherwise the
flat entry applies. Unknown glyphs remain solid. Resolution walks gameplay
layers from top to bottom, skipping upper spaces and `PASS_THROUGH` symbols.
The first remaining glyph supplies the collision result. If no layer supplies
a result, the cell is solid; `PASS_THROUGH` is never a final collision value.
Decoration is excluded entirely. For an undeclared map, collision comes from
authored symbols and the dictionary, never from colour or graphics.

`CollisionType::COUNTER` marks a counter: solid to movement, but the player
talks across one cell. A directly faced NPC is still reachable. Otherwise the
faced cell must be `COUNTER` and the NPC must stand immediately behind it in
the same cardinal heading. Floor, ordinary walls, the map's edge and a second
counter cell without an NPC end the reach. An intervening NPC keeps priority;
the player cannot reach a farther NPC through it (`InteractionReach`).

Multi-cell-counter reach has been removed: the historical any-depth rule is
superseded by Andrew's 2026-10-07 one-cell instruction. Counters are authored
one gameplay row deep; wider art or an overhanging front face must not introduce
another physical counter row. A counter is a gameplay type like any other:
give the counter's glyph `COUNTER` in the flat dictionary or in the section of
the layer it is drawn on, without changing how it looks. Graphical tile identity
and visual offsets do not change collision or talk reach.

## Reachability

Maps, rooms and NPCs change throughout production, so nothing pins where
content stands. What must hold is that the player is never blocked:
`Ichiloto\Engine\Field\Reachability\ProjectReachability::analyze($assetRoot)`
reads every map as the field does (`MapSourceReader` and the collision
dictionary) and floods it from every place the project brings the player onto
it, starting from where the game starts:

- the system starting position, and scripted `transfer` commands in common
  events and cinematics, which can run from anywhere;
- transfer events, edge triggers and sleep events, once the player can reach
  the cell that triggers them;
- scripted transfers in an NPC's lines, once the player can speak to it directly
  or across one counter cell,
  and in an event's data, once the event can fire.

An arrival counts only once the player can reach its source, and the spread
repeats until nothing new is reached, so a door from a map nobody can reach
leads nowhere.

The flood follows the field's movement rules: one cell at a time in the four
headings, onto any cell whose collision is not solid and that no NPC stands on.
Fixed NPCs block their cell; wanderers and NPCs that only appear under
conditions do not, since story gates are assumed open. Stepping onto a transfer
or edge trigger leaves the map, so those cells are reached but not walked
through. Each map's report lists:

- an arrival outside the map, or on a solid or occupied cell (the player can
  step off a blocked cell, but arrives overlapping it);
- a map nothing the player can reach brings them onto yet, which is information
  rather than a blocker (`ReachabilityProblemKind::isBlocking()` is false): a
  map kept for content still to come strands nobody;
- an event or edge trigger with no reachable cell, which therefore never fires;
- a talkable NPC no reachable cell reaches, beside it or across one counter cell by the
  same rule the field talks by, whose lines nobody can read;
- an arrival naming a map the project does not have, and a map the field
  cannot read.

`MapReachability` runs the same analysis on one map's collision grid, events
and NPCs, for tests with synthetic maps. Its bounded candidate cells use
`InteractionReach::MAX_COUNTER_CELLS` and `findTalkCell`, the same authority as
runtime interaction and the action prompt. The Editor's `ReachabilityValidator`
delegates to `ProjectReachability`; authoring validation therefore uses the same
one-cell rule, including whether an NPC's scripted transfer can run. This does
not rewrite existing layouts or make graphical counter art a collision source.

## Editing and migration

The Editor cycles through gameplay, decoration and event layers with independent
visibility and dimming. Terminal preview shows the composed gameplay grid only.
Vim, mouse, colour, selection and clipboard operations use the active layer.
Event colours are authoring aids only; runtime event markers are read without
colour tags.

Saves transact the whole changed file set. Untouched layer files are not written,
and unchanged rows preserve their original bytes. Layer create, rename and remove
participate in undo. Renaming preserves the numeric order without flattening
the data file. Because a shared collision dictionary may use the old name, the Editor asks for confirmation if a rename changes resolved
collision; it does not rewrite that dictionary automatically. The first explicit
layer creation on a legacy map converts its grid.

A game may supply a building catalogue for multi-row facade brushes. The Editor
reads the catalogue as the shape source rather than keeping copied definitions.
The game's layer conventions still determine where those brushes belong.

When splitting an existing map, compare its complete styled composed grid and
every cell's resolved collision before and after. A geometry-preserving split
does not by itself require a save-content version change. Deliberate changes to
walls, approaches or safe arrival cells need separate save-compatibility review
and migrations where old positions become unsafe. Do not hide such changes by
updating the equivalence baseline.

Inserting blank rows or columns grows a map and moves everything at or beyond
the insertion line by the inserted count: every terminal, event and tile layer,
NPC positions and wander areas (an area straddling the line stretches), spawn
points and transfers into the map from any file, `startingPositions`, and
coordinates in scripts and cutscenes that run on the map. A regional `station`
and tile-layer `offset` values are not map cells and never move. The Editor's
insert command performs this as one transaction and reports every coordinate
it cannot rewrite from source (a PHP expression, or a script started from
several maps) for a hand edit. Saves follow through a declarative `mapShifts`
content migration; see [Versioned save compatibility](save-compatibility.md#declared-map-shifts).

### Regular NPC sprites

Map `npcs` entries may add optional `sprites2d` alongside the existing terminal
`sprite` and directional `sprites`. It names an RPG Maker character sheet, the
same contract as the Player: see [field character sheets](rendering/sprite-sheets.md).
An NPC occupies exactly one field cell. Omit `sprites2d` for terminal-only
NPCs; an explicit empty array, null, or malformed definition is invalid and
produces a diagnostic with terminal fallback, without removing the NPC.

```php
'npcs' => [[
  'id' => 'village-guide',
  'name' => 'Guide',
  'sprite' => '@',
  'x' => 7, 'y' => 4,
  'sprites2d' => ['sheet' => 'Graphics/Characters/People.png', 'index' => 3, 'layer' => 100],
]],
```

Sheets are project-asset-root-relative PNG paths, not character identities.
Frame size is read from the image; world layers are 0..999. Shared limits
require readable root-contained PNGs of at most 16 MiB and 4096 pixels per
dimension. These are resource limits, not recommended artwork sizes. Missing
files, invalid headers, unsafe paths, and sheets that do not divide into RPG
Maker's layout retain terminal art with a warning. Replacing a valid file at the
same path requires no metadata update; preflight retries changed files.
Full PNG decoding remains the native renderer's responsibility: a valid header
with corrupt IDAT data is not detected by this PHP preflight, and recovery from
that native decode failure is not supplied by the NPC sidecar.

NPCs implement `GraphicalSpriteProviderInterface`.
`NpcManager::getGraphicalSpriteProviders()` returns exactly the visible,
cinematically unsuppressed ordinary NPCs; terminal-only providers return a null
graphical definition. Each terminal fallback uses its provider's named Console
layer, so graphical replacement masks only that NPC. Presentation IDs are
map-scoped and use the existing stable NPC `id`; legacy id-less entries receive
separate entry-based presentation IDs without changing script or save identity.
Visibility, interaction, collision, conversation writes and movement stay owned
by the existing NPC logic. Cinematic leases suppress ordinary NPC art until
released, including while the staged actor is hidden.

The scene advances `NpcManager::advanceGraphicalAnimation($seconds)` alongside
Player/staged-actor animation, including event routes, and uses
`stopGraphicalAnimation()` on presentation lifecycle boundaries. Successful
steps use the shared `CharacterWalkAnimation` (RPG Maker's 1, 2, 1, 0 stride);
facing, blocked movement, restored staging, and map replacement return to the
standing frame. Reduced motion retains route outcomes and shows the standing
frame for each direction. The regular-NPC
Editor picker and safe source-preserving authoring workflow are coordinated
separately; runtime support alone does not complete Editor authoring.

### Map-owned interactive fixtures

A fixed interaction can keep its stable NPC `id`, dialogue and position while
the gameplay layer supplies its appearance. Set that NPC's `sprite` explicitly
to the empty string (`''`), and omit directional `sprites`. An omitted sprite
still defaults to `@`; a space is not an empty sprite and would paint over the
map. The empty sprite writes no cells, leaving both the terminal map glyph and
its graphical crop visible without a second drawing above them.

Keep the interaction anchored on the visible fixture cell, with a reachable
approach cell beside it. The NPC still participates in interaction, conditions
and occupancy. Its conditions control the interaction, not the authored map
glyph; use a regular NPC sprite for an object whose appearance must move or
disappear with the NPC. Map-owned fixtures should remain fixed.

## Regions

`.data.php` gives a map its `name` and its `region`:

```php
return [
  'name' => 'Town Center',
  'region' => 'Happyville',
  // ...
];
```

Maps sharing a region are the same place as far as the player is concerned,
and the map screen draws them together.

## The map screen

The `map` action (M by default) opens the entire active map with the player's
physical position. `map_view` switches to the connected places around it. The
Terminal connectivity view replaces the old large name boxes with one cell per
location. Only the focused place is named in the detail panel:

```
       ■────┐
            ■────┐
            │    ↓
            ■    ■

  Town Center — You are here
```

Nothing about this is authored twice. `Field\RegionMap` reads every map's
`name`, `region`, transfer events and legacy transfers. Its source-owned routes
reuse the landmark catalog and saved discovery policy. `getKnownConnections()`
returns directed connections and optional known names without mutating discovery,
access or saved state. Secret routes require their own explicit revelation even
when the destination has already been visited. Ordinary locked routes stay visible.

The view opens with the current place focused. Directional actions move the cursor
spatially; page actions step through all visible locations, and the retained Home
key focuses the current place. Focus highlights its connections. Arrows show known
travel direction; known reciprocal transfers share a line. Reverse travel is not
inferred from an unvisited map. Lines route around unrelated
blocks. Back or the map action returns to exploration. The panels fit the logical
screen, including an 80x24 terminal. Oversized diagrams follow the focused block;
manual pan-first navigation and always-visible place-name boxes are removed.

### Where a place is drawn

**Where a door sits on its map is where the place behind it lies.** The house's
door is up on the north-west side of the square because the house is north-west
of it; the shop's is straight down at the south. The engine reads the door's
position out of the map's event layer and places the destination that way, so
a region that was drawn sensibly maps sensibly, for free.

A door in the middle of a map says nothing about direction, and its
destination is simply set down beside what it connects to.

The region is laid out around its **hub**, the place with the most doors,
rather than around the player, so it does not rearrange itself depending on
where the player is standing.

When the doors get it wrong, a map can say where it belongs:

```php
return [
  'name' => 'Sealed Vault',
  'region' => 'Crypt',
  // Grid position on the region map. Beats anything the doors imply.
  'station' => ['x' => 4, 'y' => 2],
];
```

Stations are region-grid coordinates, not local field tiles or transfer
arrival positions. The diagram compresses unused station gaps while preserving
cardinal order. It does not allocate a world-sized raster for distant station
values or change authored stations, physical coordinates, transfers or saves.
Every distinct authored pin is reserved before unpinned places are inferred.
For duplicate pins, the first map in stable file-scan order keeps the pin and
the others use the next free cells east, without taking another authored pin.
This resolves display overlap without rewriting the project's station data.

What the player has seen governs what it says:

- visited places can show their name under focus,
- an immediately visible destination stays unnamed until visited or its owning
  route's declared label has been learned through approach or play,
- anything further is not drawn at all.

Visits are recorded in the world state (`GameState::markMapVisited()`, called
when a map loads) and ride the save file, so a map fills in as the game is
played. Immediate known exits outside the region are included as distinct blocks.
An unvisited inbound source does not reveal itself merely by linking to a visited
destination. Names from unexplored maps are never inferred from file metadata.

A region can hold a map no door reaches, one entered only by a cutscene, say.
It is still part of the region and becomes visible when visited.

## Random encounters

A map turns on random encounters by naming the troops that can appear and how
often:

```php
'encounters' => [
  // Troop name (from assets/Data/troops.php) => weight.
  'troops' => [
    'Bat x 2' => 5,
    'Rat + Bat' => 4,
    'Great Wolf' => 2,
  ],
  // The average number of steps between fights.
  'rate' => 10,
  // Optional: 'any' counts every tile, the default counts encounter tiles.
  'tiles' => 'encounter',
],
```

Weights are relative, so a `Bat x 2` above is met roughly five times as often
as `Great Wolf` twice. A map with no `encounters` key never rolls one.

The engine warns when a map declares `encounters` in a shape it cannot read,
because a silent no-op is indistinguishable from a design decision.
