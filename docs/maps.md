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
Each occupied decoration cell must instead have a crop mapping in that layer's
`tiles2d` table. A decoration layer named in the collision dictionary, or an
occupied decoration cell without a mapping, refuses the map with a diagnostic.
Interactive objects must be gameplay glyphs or events, never hidden decoration.

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
dictionary, never from colour or a separate stored collision grid.

Graphical crop mappings are also keyed by layer and symbol, so a symbol can have
different crops on different layers without changing the terminal or collision.
Optional per-cell crop overrides distinguish repeated symbols within one layer,
for example the ends and middle of a multi-cell table. They select appearance
only: authored glyphs and collision remain authoritative. Their coordinates are
validated against the owning map layer, including ragged rows. Explicit artwork
on blank cells does not make those cells occupied for gameplay.
See [tile batches](rendering/tile-batches.md) for the `tiles2d` format, stacking
and resource limits. All current map layers paint below the player; pass-through
does not yet imply above-player drawing.

## Editing and migration

The Editor cycles through gameplay, decoration and event layers with independent
visibility and dimming. Terminal preview shows the composed gameplay grid only.
Vim, mouse, colour, selection and clipboard operations use the active layer.
Event colours are authoring aids only; runtime event markers are read without
colour tags. Symbol crop defaults remain read-only in the inspector. The
`Tile art: Edit selected cell` action opens a staged form for a cell override,
using the shared project PNG picker and numeric crop fields. Apply and Remove
are undoable; Cancel leaves the map unchanged. Changing a layer's atlas requires
confirmation because every existing crop on that layer uses it. Opaque source
edits are refused before writing, and painting warns when a symbol has a crop
mapping or a cell override stays attached to that coordinate.

Saves transact the whole changed file set. Untouched layer files are not written,
and unchanged rows preserve their original bytes. Layer create, rename and remove
participate in undo. Renaming preserves the numeric order and updates local crop
keys without flattening the data file. Because a shared collision dictionary may
use the old name, the Editor asks for confirmation if a rename changes resolved
collision; it does not rewrite that dictionary automatically. The first explicit
layer creation on a legacy map converts its grid and optional flat crop table.

A game may supply a building catalogue for multi-row facade brushes. The Editor
reads the catalogue as the shape source rather than keeping copied definitions.
The game's layer conventions still determine where those brushes belong.

When splitting an existing map, compare its complete styled composed grid and
every cell's resolved collision before and after. A geometry-preserving split
does not by itself require a save-content version change. Deliberate changes to
walls, approaches or safe arrival cells need separate save-compatibility review
and migrations where old positions become unsafe. Do not hide such changes by
updating the equivalence baseline.

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
