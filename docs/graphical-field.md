# Graphical field - authoritative plan

This plan defines how a graphical renderer (GPUI today, others later)
represents the field. Ichiloto is a terminal game engine: the terminal
layers are the game. The graphical field is a representation drawn on top
of that foundation, following RPG Maker's established conventions rather
than inventing new ones. It supersedes Stages 1 to 3 of the graphical
correction roadmap in [layered-tilemaps.md](layered-tilemaps.md); the
editing surface is owned by the GUI Editor plan. Related docs:
[maps.md](maps.md), [rendering/tile-batches.md](rendering/tile-batches.md),
[rendering/runtime.md](rendering/runtime.md).

## Principles

1. **The terminal is the game.** Map geometry, collision, movement, events,
   interactions and saves derive from the terminal layers, always. Nothing
   graphical can make a cell solid, walkable or interactive, and nothing
   graphical changes how the terminal looks or plays.
2. **The terminal grid is the field's grid.** A map cell is one terminal
   character, and the player moves one cell per step, in every renderer. A
   graphical renderer corrects for its own pixels: it draws each terminal
   cell in the terminal's tall shape, so a map that looks right in the
   terminal looks right graphically, with no conversion of maps,
   coordinates or saves.
3. **RPG Maker's conventions, adopted outright.** Tile size, tileset sheet
   layouts, autotile composition, tile identities and character sheet
   layouts follow RPG Maker MZ. Only the conventions are adopted; no RPG
   Maker assets are used or required.
4. **Graphical meaning is independent of glyph meaning.** A `#` may be a
   wall in one place and a chair in another, and Apthia's architecture need
   not look like Gedisville's. Graphics are their own authored data on the
   same grid, never derived from which glyph a cell holds.
5. **Art is the developer's and is replaceable.** The engine reads image
   dimensions from the files, validates sheet shapes against the layouts,
   and fails an asset only when it is missing, the wrong type, corrupt or
   not a valid sheet. Authored data never restates what an image knows.

## The unit

- A terminal character box is about twice as tall as it is wide. The
  graphical field draws each terminal cell as a 24 x 48 logical-pixel box
  (`FieldViewport::CELL_WIDTH` x `CELL_HEIGHT`): the same shape, enlarged.
  A step moves 24 pixels across or 48 down, one cell either way, as in the
  terminal.
- RPG Maker's tile is 48 x 48, built from four 24 x 24 quarters, so one
  terminal cell is exactly one column of quarters and a whole tile is two
  cells wide. Character frames are 48 x 48 as well.
- The number of visible cells follows from the window size divided by the
  cell size and the field zoom, not from the terminal's column count.
- The field and the interface use separate grids. Dialogue, menus and the
  HUD keep their text grid; only the field uses the field cell.

## Characters

- Character art uses RPG Maker's character sheet layout: each character is
  3 frames by 4 directions (down, left, right, up) of 48 x 48 frames. A
  standard sheet holds 8 characters; a sheet whose name begins with `$`
  holds one.
- Walking cycles frames in RPG Maker's pattern (1, 2, 1, 0 around the idle
  middle frame); standing shows the middle frame.
- A character stands on its one cell: its 48 x 48 frame is bottom-centred
  on the cell and overhangs half a cell on each side, where RPG Maker's
  figures leave the frame transparent. There are no authored width, height
  or anchor values for field characters, and none are accepted. Field
  images such as cinematic poses are sized in whole character frames.
- Depth is row order: within a draw band, a character in a lower row draws
  in front of one above it, with stable ties.

## Tilesets

- A tileset is a project resource naming its sheets in RPG Maker's layout:
  A1 (animated water and waterfalls), A2 (ground), A3 (building exteriors),
  A4 (walls), A5 (normal lower tiles) and B to E (upper tiles, 256 each).
  The engine validates each sheet's dimensions against its layout.
- Autotiles (A1 to A4) compose each tile from 24 x 24 quarter pieces
  according to its neighbours, exactly as RPG Maker does, so edges,
  corners, ends and junctions need no hand-placed variants.
- Tile identities follow RPG Maker MZ's numbering (B from 0, C from 256,
  D from 512, E from 768, A5 from 1536, A1 from 2048, A2 from 2816, A3 from
  4352, A4 from 5888), with the autotile shape carried in the identity.
- RPG Maker's per-tile passage settings do not apply: passage comes from
  the terminal. Its "above characters" priority is kept as a purely
  graphical draw band.

## Graphical map data

- **Tileset resource.** `assets/Data/Tilesets/<id>.php` returns the
  tileset's `name`, its `sheets` keyed by RPG Maker sheet name (`A1` to
  `A5`, `B` to `E`; any may be omitted) as asset-relative PNG paths, and
  two optional lists of tile identities: `above` (RPG Maker's star: drawn
  above characters) and `tables` (A2 autotiles drawn as tables). The file
  name is the tileset's stable identity.
- **Sheet validation.** Each sheet's tile size is its width divided by its
  layout's tile columns (A1, A2, A3, A4, B to E: 16 columns; A5: 8). It
  must be a whole, even number of pixels (autotiles are built from quarter
  tiles), the height must be the layout's rows (A1 and A2: 12, A3: 8, A4:
  15, A5 and B to E: 16) times the tile size, and every sheet of a tileset
  shares one tile size. RPG Maker's 48 pixels is recommended, not
  required. A missing or invalid sheet is reported, and the cells that
  would use it fall back to terminal glyphs.
- **Map graphics.** A map names its tileset in its data file
  (`'tileset' => 'home'`, like RPG Maker's map properties). Its tile layers
  live in `graphics/` beside `layers/`, named `NN.name.tiles.php` and
  ordered by `NN` like terminal layers. Each returns a literal nowdoc with
  one row per map row and one whitespace-separated tile identity per
  terminal cell; `0` is empty (RPG Maker's first B tile). Rows match the
  map's cells exactly. Nothing in them is executed.
- **Autotiles per cell.** Every cell of a floor, wall or water area holds
  its autotile, and the engine composes it for that cell from its
  neighbouring cells, as RPG Maker composes a tile from its neighbouring
  tiles. The cell shows the quarter column facing the edge it borders: the
  left half at a west edge or inner corner, the right half at an east one,
  the outer half of each when it borders both (a wall one column thick),
  and elsewhere the half matching its column's parity, so a texture keeps
  one phase in every row. A wall is therefore exactly as thick as its
  terminal column.
- **Layer offsets.** The map data may shift a whole tile layer by half a
  field cell across or down, like a Tiled layer offset:
  `'tileLayers' => ['lounge' => ['offset' => [0, -0.5]]]`. Art can then sit
  between the cells its terminal footprint allows, such as a coffee table
  centred between a sofa and a television. Offsets are -0.5, 0 or 0.5 and
  never move collision or events.
- **Plain tiles** (A5 and B to E) are drawn whole and centred on their cell,
  like a character, so a chair on one column or a bed over an odd number of
  columns sits centred on its footprint. An entry with an `L` or `R` suffix
  (`42L`) fills its cell with only that half of the tile, so a tile can lie
  exactly across two cells, and a wide object can be stretched with
  repeated halves. Only a plain tile takes a suffix.
- **Autotile shapes** are carried in the identity, as RPG Maker stores them.
  The engine resolves them from neighbouring cells of the same kind for
  authoring tools, and composes each shape from quarter tiles when
  presenting. Map edges count as the same kind, as in RPG Maker.
- **Draw bands.** Tile layer `NN` draws at `-100 + NN`, the same band as the
  terminal layers, below characters. Tiles listed in `above` draw at
  `900 + NN`, above characters and below the interface.
- **Glyph fallback.** A cell with no tile of its own shows its terminal
  glyph, even where a tile placed beside it overhangs; a cell with a tile
  does not, even where its tile is transparent.
- **Animation.** A1 water cycles RPG Maker's frames (water 0, 1, 2, 1;
  waterfalls 0, 1, 2) on one counter advancing every 30/60 seconds, as
  RPG Maker does. Reduced motion holds the first frame.
- **Retained upload.** The world carries the cell size, and the tileset:
  its sheets, tile size and a catalog of the tile identities the map uses,
  each as frames of pieces copied from a sheet into the tile. Tile layers
  are world layers of kind `tiles` whose rows list cells and catalog
  indices, uploaded once per map load. The viewport carries only the camera
  and the animation frame. The renderer knows nothing of RPG Maker:
  composition rules stay in the engine.
- **Terminal and editors.** None of this changes terminal geometry,
  collision, events or saves. The TUI editor preserves a map's graphics
  folder through resize, duplicate, move and delete, validates it, and
  never edits it; painting tiles belongs to the GUI editor (Phase 4).

## Project format

`ichiloto upgrade` is the project's format chain. A project records its
format version in `ichiloto.json`, and the command runs every numbered
step between that version and the engine's, in order, like the save
compatibility chain. Each step is its own class; a later format change
adds a step and never edits an earlier one. Format 1 is the project id and
save compatibility manifest. The version is bookkeeping the tools write and
read; nobody tracks it by hand.

The experience follows established upgraders (Unity and Godot on opening
an older project, framework migrations, `ng update`):

1. **Detect.** `ichiloto play`, `validate` and the editor notice a project
   behind the engine's format and say what is outdated and to run
   `ichiloto upgrade`, instead of failing on the old data.
2. **Explain.** `ichiloto upgrade` takes no arguments. It first lists, one
   line per pending step, what will change, then asks to continue.
   `--dry-run` prints the same list and changes nothing.
3. **Protect.** It refuses to run over uncommitted changes unless told to,
   so each upgrade is one reviewable, reversible change.
4. **Report.** It ends with the follow-up list for a person and writes it
   to a file in the project.

## Phases

### Phase 1 - The unit and characters

1. Render the field on its own grid, separate from the interface's text
   grid, one terminal cell per 24 x 48 field cell.
2. RPG Maker character sheets for the player, NPCs and field creatures,
   bottom-centred on their cell, with the walking pattern and row-order
   depth.
3. Remove per-sprite width, height and anchor for field characters.
4. Maps without graphics render terminal glyphs in their cells.

### Phase 2 - Tilesets and graphical layers

1. Tileset resources with layout validation.
2. Tile identities and autotile composition for A1 to A4.
3. Graphical layer files, loading, validation and retained upload, with
   autotiles composed per cell and plain tiles centred or halved.
4. Remove the glyph-keyed crop tables and cell overrides.

### Phase 3 - Last Legend Home proof

Home drawn from its tileset over its unchanged terminal layers.

### Phase 4 - Authoring

Map painting with tilesets and autotiles in the GUI Editor, per its plan.

### Phase 5 - Last Legend art

New tilesets and character sheets in RPG Maker's layouts for the wider
maps. The existing 16 x 32 art is not carried forward.

## Decisions already made (do not relitigate)

- The terminal is the game; collision always derives from terminal layers.
- Graphics never change terminal geometry, movement or authoring. A map
  cell is one terminal character in every renderer; the graphical field
  draws it as a 24 x 48 box and corrects for its shape itself.
- RPG Maker MZ's tile size, sheet layouts, autotiles, tile identities and
  character sheets are adopted as the conventions. A cell shows one column
  of an autotile's quarters; a whole tile or character frame is two cells
  wide and centred on its cell.
- Graphics are independent authored data, never keyed off glyphs.
- Existing 16 x 32 art is recreated, not migrated.
