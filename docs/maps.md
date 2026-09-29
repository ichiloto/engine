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
file and keeps one tile identity per terminal cell in `graphics/`:

```
assets/Data/Tilesets/home.php          # name, sheets A1 to E, above, tables
assets/Maps/village/harbour/
  harbour.data.php                      # 'tileset' => 'home'
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
layer moves with in the editor (`'floor' => ['movesWith' => 'buildings']`).
Graphics never change
geometry, collision, events or saves, and unusable graphics or sheets are
reported while the map shows its terminal glyphs. Autotile shapes are stored in the identities, as RPG Maker
stores them; `AutotileShape::resolveLayer` chooses them from neighbours for
authoring tools. See [graphical field](graphical-field.md) for sheets, draw
bands and animation.

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
Decoration is excluded entirely. Collision comes from authored symbols and the
dictionary, never from colour, graphics or a separate stored collision grid.

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

The `map` action (M by default) opens the region the player is standing in,
drawn from its authored stations and transfer links:

```
  [    Home     ]──┐                                    N
                   │                                  W ─ E
                   └──[ Town Center ]──┐                 S
                             │         │
                      [    Shop     ]  └──[  Inn Front  ]
```

Nothing about this is authored twice. `Field\RegionMap` reads every map's
`name`, `region`, and `TransferPlayerTrigger` destinations, so a door drawn on
a map appears on the region map by existing.

The view opens on the current place. Use the directional movement bindings
(arrow keys by default) to pan a region larger than the panel, and `Home` to
return to the current place. `c`, back, or the map action closes it. Panning
changes only the view, never the party position or the region layout. The
map and info panels fit the logical screen, including an 80x24 terminal.

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
arrival positions. Sparse coordinates retain their spacing and can be
reached by panning; the engine does not compress them to fit the panel.
Every distinct authored pin is reserved before unpinned places are inferred.
For duplicate pins, the first map in stable file-scan order keeps the pin and
the others use the next free cells east, without taking another authored pin.
This resolves display overlap without rewriting the project's station data.

What the player has seen governs what it says:

- places the party has been are named,
- places one door from somewhere they have been show as `?????`,
- anything further is not drawn at all.

Visits are recorded in the world state (`GameState::markMapVisited()`, called
when a map loads) and ride the save file, so a map fills in as the game is
played. Doors leading out of the region are named under the map as exits.

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
