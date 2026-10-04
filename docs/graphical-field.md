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
   graphical renderer draws each terminal cell as one RPG Maker tile, with
   no conversion of maps, coordinates or saves, so maps are laid out in
   tiles: a bed one tile wide and two long is one glyph column and two rows.
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

- The graphical field draws each terminal cell as one RPG Maker tile, a
  48 x 48 logical-pixel square (`FieldViewport::TILE_SIZE`). A step moves
  48 pixels across or down, one cell either way, as in the terminal.
- RPG Maker's tile is 48 x 48, built from four 24 x 24 quarters, and its
  character frames are 48 x 48 as well, so a tile or a character frame fills
  exactly its cell.
- A terminal character box is about twice as tall as it is wide, so a map
  laid out only for the terminal's proportions looks twice as wide
  graphically as it does in the terminal. Maps meant to be seen graphically
  are laid out in RPG Maker's proportions, one glyph per tile.
- The number of visible cells follows from the window size divided by the
  cell size and the field zoom, not from the terminal's column count.
- The field and the interface use separate grids. Dialogue, menus and the
  HUD keep their text grid; only the field uses the field cell.

## Characters

- Character art uses RPG Maker's character sheet layout: each character is
  3 frames by 4 directions (down, left, right, up) of 48 x 48 frames. A
  standard sheet holds 8 characters. As in RPG Maker, the leading `$` and
  `!` marks of the sheet's file name describe it: `$` holds one character,
  and `!` holds an object, such as a door or chest.
- Walking cycles frames in RPG Maker's pattern (1, 2, 1, 0 around the idle
  middle frame) by distance travelled; standing shows the middle frame.
- Characters walk at RPG Maker's default speed, 180 field pixels per second:
  every step, across or down, covers one 48-pixel cell in 16/60 s. While a direction is held the
  player keeps walking one committed cell at a time, and the renderer slides
  each character between cells over its step, with the camera following the
  player. The terminal keeps stepping once per key event, unchanged.
- As in RPG Maker, what reaching a cell does happens once the player is seen
  to arrive: its touch events, encounter step, save point notice and movement
  observers wait for the slide, so a dialogue, battle or transfer never cuts
  it short. The step itself (position, collision) is committed at once, and a
  step without a slide (the terminal, reduced motion) arrives immediately.
- A character stands on its one cell: its 48 x 48 frame is bottom-centred
  on the cell and fills it exactly, as in RPG Maker. There are no authored width, height
  or anchor values for field characters, and none are accepted. Field
  images such as cinematic poses are sized in whole character frames.
- Like RPG Maker (Sprite_Character's shiftY), the field draws a character
  6 field pixels above its cell, so art drawn with the feet on the frame's
  bottom edge stands inside its tile. An object sheet (`!`) is not lifted,
  and neither are single-image field art, battle or interface sprites. The
  Engine derives the lift from the sheet's name, never from its image, and
  sends it only to renderers advertising `sprite_lift`; other renderers
  place the character on its cell exactly as before.
- Depth is row order: within a draw band, a character in a lower row draws
  in front of one above it, with stable ties. A lift never changes it: a
  character is ordered by its cell, not by where it is drawn.

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
- A tileset may name its missing-art tile (`missingArt`), a plain tile on
  one of its sheets that marks a cell whose art nobody can yet infer. It
  draws like any tile; tools report the cells that show it as art to do.
- A tileset may give its raised tiles a shadow (`shadows`): the tiles that
  cast (`casters`: whole sheets such as `'A3'` and `'A4'`, RPG Maker's
  raised buildings and walls, so a wall kind painted later casts too, or
  flag identities like `above`, so every shape of an autotile kind casts),
  the band's `width` as a fraction of a cell and its
  `opacity`. Its look belongs to the tileset because it matches that art's
  lighting. A tileset without `shadows` casts none.

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
- **Pieces.** A tileset may list `pieces`: whole items a map is built
  from, such as a bed, keyed by id. Each has a `name`, the gameplay `layer`
  its terminal `glyphs` go on (rows of one-cell characters), and optional
  `tiles` keyed by tile layer name, with rows of the same entries a tile
  layer holds (`42`, `0`) over the same footprint, one whole tile per cell:
  `'bed' => ['name' => 'Bed', 'layer' => 'fixtures', 'glyphs' => ['=', '='], 'tiles' => ['furniture' => ['32', '40']]]`.
  A space glyph or a `0` tile leaves that cell as it was. An editor stamps
  a piece whole, writing its glyphs and its tiles together, so a map made
  in the terminal draws correctly graphically without a second pass; the
  terminal editor never asks for or shows the tiles. Collision still comes
  from the glyphs alone. A map offers the pieces of the tileset it names.
- **Connected pieces.** A piece with `'connects' => 'lines'`, such as a
  wall or fence, is drawn cell by cell and joins the cells of the same
  piece beside it. Its `glyphs` name one glyph for each shape (`horizontal`,
  `vertical`, `corner`) and its `tiles` give one entry per tile layer, or
  one per shape. A cell joined only across is horizontal, only down is
  vertical, and anything else (corners, junctions, a lone post) is a corner.
  Drawing or erasing a cell reshapes the cells of the piece beside it, which
  are recognised by their glyphs. A wall's tile can be an A4 wall top: the
  autotile shapes its own edges.
- **Map graphics.** A map names its tileset in its data file
  (`'tileset' => 'interior'`, like RPG Maker's map properties). Tilesets are
  grouped by kind of setting, such as interiors, and shared by every map of
  that kind ([maps](maps.md#graphics)). Its tile layers
  live in `graphics/` beside `layers/`, named `NN.name.tiles.php` and
  ordered by `NN` like terminal layers. Each returns a literal nowdoc with
  one row per map row and one whitespace-separated tile identity per
  terminal cell; `0` is empty (RPG Maker's first B tile). Rows match the
  map's cells exactly. Nothing in them is executed.
- **Autotiles per cell.** Every cell of a floor, wall or water area holds
  its autotile, and the engine composes that cell's whole tile from its four
  quarters by the shape its neighbouring cells give it, exactly as RPG Maker
  composes a tile from its neighbouring tiles. A wall one column thick is
  one tile wide, with both its edges.
- **Layer offsets.** The map data may shift a whole tile layer by half a
  field cell (half a tile) across or down, like a Tiled layer offset:
  `'tileLayers' => ['lounge' => ['offset' => [0, -0.5]]]`. Art can then sit
  between the cells its terminal footprint allows, such as a coffee table
  centred between a sofa and a television. Offsets are -0.5, 0 or 0.5 and
  never move collision or events.
- **Tiles belong to a gameplay layer.** Each tile layer belongs to at most
  one gameplay layer, so moving that layer's glyphs in an editor carries its
  tiles in the same cells, moving other layers leaves them, and its tiles
  hide only that layer's glyphs (see Glyph fallback). The map data may name
  it: `'tileLayers' => ['floor' => ['movesWith' => 'buildings']]`, which
  must name a gameplay layer. Otherwise it is the gameplay layer whose
  tileset pieces write that tile layer, when exactly one does; a tile layer
  with neither belongs to none and stays where it is.
  `MapGraphics::resolveLayerOwners()` is the one resolution the runtime and
  the editors share.
- **Plain tiles** (A5 and B to E) are drawn whole in their cell, so an item
  two tiles wide is two glyphs wide. A cell holds exactly one whole tile: an
  entry naming half a tile (`42L`) is refused by the engine and the editor
  validator with a message asking for whole tiles, never reinterpreted.
- **Autotile shapes** are carried in the identity, as RPG Maker stores them.
  The engine resolves them from neighbouring cells of the same kind for
  authoring tools, and composes each shape from quarter tiles when
  presenting. Map edges count as the same kind, as in RPG Maker.
- **Draw bands.** Tile layer `NN` draws at `-100 + NN`, the same band as the
  terminal layers, below characters. Tiles listed in `above` draw at
  `900 + NN`, above characters and below the interface.
- **Glyph fallback.** A cell shows the terminal glyph of the gameplay layer
  that owns it, the topmost one with a glyph there, unless a tile of its own
  covers that layer: a tile of a tile layer that belongs to that gameplay
  layer, or of a tile layer that belongs to none. A covered glyph stays
  hidden even where its tile is transparent, and a tile placed beside a cell
  never hides its glyph, even where it overhangs. So a floor that belongs to
  the buildings layer leaves visible the glyph of a fixture that has no tile
  yet, while the fixture's own tile hides it. Renderers that do not
  advertise `tile_covers` never learn which layer a tile layer belongs to,
  and there every tile hides the glyph of its cell.
  `MapGraphics::getShownGlyphCells()` answers this rule for a whole map, so
  authoring tools can report the glyphs that have no graphics yet.
- **Shadows (ambient occlusion).** As RPG Maker's auto-shadow, a caster
  throws a translucent black band over the left of the cell to its right.
  The Engine derives the bands from the tiles on every upload; they are never
  authored, stamped or saved. A cell is shaded when a caster in any tile
  layer stands to its left, it shows a tile below the characters, and it
  holds no caster itself. So a wall run shades only the floor at its open
  right edge, and painting, stamping, dragging or erasing either neighbour,
  in the GUI editor or anywhere else, needs nothing more to keep the
  shadows true. Each band belongs to the highest tile layer whose caster
  throws it, keeps that layer's offset, and paints right after that layer's
  tiles: over the floor and wall beneath and under later layers (furniture)
  and every character. Bands never hide a glyph, and never touch terminal
  geometry, collision, events or saves. They reach only renderers that
  advertise `tile_shadows`; others receive exactly the world they always
  did. The tile palette shows loose tiles without neighbours, so it never
  asks for shadows. Per-cell author overrides (RPG Maker's shadow pen) are
  not part of this; add them only if an authored exception is ever needed.
- **Animation.** A1 water cycles RPG Maker's frames (water 0, 1, 2, 1;
  waterfalls 0, 1, 2) on one counter advancing every 30/60 seconds, as
  RPG Maker does. Reduced motion holds the first frame.
- **Retained upload.** The world carries the cell size, and the tileset:
  its sheets, tile size and a catalog of the tile identities the map uses,
  each as frames of pieces copied from a sheet into the tile. Tile layers
  are world layers of kind `tiles` whose rows list cells and catalog
  indices, uploaded once per map load; with `tile_covers`, each names the
  gameplay layer it belongs to in `coversLayerId`. With `tile_shadows`,
  shadow bands follow as fill tiles in world layers of kind `shadows`. The viewport carries only the camera
  and the animation frame. The renderer knows nothing of RPG Maker:
  composition rules stay in the engine.
- **Terminal and editors.** None of this changes terminal geometry,
  collision, events or saves. The TUI editor preserves a map's graphics
  folder through resize, duplicate, move and delete, and validates it. It
  writes tiles only when it stamps a piece or copies, cuts and pastes the
  glyphs they move with, and never shows or asks for them; painting
  individual tiles belongs to the GUI editor (Phase 4).

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
   grid, one terminal cell per 48 x 48 field cell.
2. RPG Maker character sheets for the player, NPCs and field creatures,
   bottom-centred on their cell, with the walking pattern and row-order
   depth.
3. Remove per-sprite width, height and anchor for field characters.
4. Maps without graphics render terminal glyphs in their cells.

### Phase 2 - Tilesets and graphical layers

1. Tileset resources with layout validation.
2. Tile identities and autotile composition for A1 to A4.
3. Graphical layer files, loading, validation and retained upload, with
   one whole tile per cell: autotiles composed for their cell and plain
   tiles drawn whole.
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
  draws it as one 48 x 48 RPG Maker tile, and maps are laid out in tiles.
- RPG Maker MZ's tile size, sheet layouts, autotiles, tile identities and
  character sheets are adopted as the conventions. A cell shows one whole
  tile, and a character frame fills its cell.
- Graphics are independent authored data, never keyed off glyphs.
- Existing 16 x 32 art is recreated, not migrated.
