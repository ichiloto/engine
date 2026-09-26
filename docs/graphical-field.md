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
   graphical can make a cell solid, walkable or interactive.
2. **One cell is one unit.** A terminal cell is one graphical unit: a
   48 x 48 pixel square tile. Everything on the field is sized in cells,
   never in pixels. A character occupies exactly one cell in every
   renderer, so what the player sees agrees with what collides.
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

- A field cell renders as a 48 x 48 pixel square. The number of visible
  cells follows from the window size divided by the unit (and any display
  scale for fitting the window), not from the terminal's column count.
- The field and the interface use separate grids. Dialogue, menus and the
  HUD keep their text grid; only the field uses the 48-pixel unit.
- Terminal cells are roughly twice as tall as wide and graphical cells are
  square. The map is the same grid of cells in both; only the pixel shape
  of a cell differs.

## Characters

- Character art uses RPG Maker's character sheet layout: each character is
  3 frames by 4 directions (down, left, right, up) of 48 x 48 frames. A
  standard sheet holds 8 characters; a sheet whose name begins with `$`
  holds one.
- Walking cycles frames in RPG Maker's pattern (1, 2, 1, 0 around the idle
  middle frame); standing shows the middle frame.
- A character is drawn inside its one cell. There are no authored width,
  height or anchor values for field characters, and none are accepted.
- Depth is row order: within a draw band, a character in a lower row draws
  in front of one above it, with stable ties.

## Tilesets

- A tileset is a project resource naming its sheets in RPG Maker's layout:
  A1 (animated water and waterfalls), A2 (ground), A3 (building exteriors),
  A4 (walls), A5 (normal lower tiles) and B to E (upper tiles, 256 each).
  The engine validates each sheet's dimensions against its layout.
- Autotiles (A1 to A4) compose each cell from 24 x 24 quarter pieces
  according to its neighbours, exactly as RPG Maker does, so edges,
  corners, ends and junctions need no hand-placed variants.
- Tile identities follow RPG Maker MZ's numbering (B from 0, C from 256,
  D from 512, E from 768, A5 from 1536, A1 from 2048, A2 from 2816, A3 from
  4352, A4 from 5888), with the autotile shape carried in the identity.
- RPG Maker's per-tile passage settings do not apply: passage comes from
  the terminal. Its "above characters" priority is kept as a purely
  graphical draw band.

## Graphical map data

- Each map may have a `graphics/` folder beside `layers/`: which tileset
  the map uses, and ordered tile layers holding one tile identity per cell,
  following RPG Maker's layer model. Each file returns literal data only,
  like the terminal layers, and shares the map's dimensions.
- A map without graphics renders its terminal glyphs in the graphical
  renderer, on the 48-pixel unit.
- The retained presentation uploads a map's graphical layers once per map
  load, in world coordinates, and each frame carries only the camera, as
  the retained contract already specifies.
- The glyph-keyed crop tables (`tiles2d`) and per-cell crop overrides are
  removed once graphical layers replace them. They bound graphical meaning
  to glyphs, which principle 4 rules out.

## Phases

### Phase 1 - The unit and characters

1. Render field cells as 48 x 48 squares on their own grid, separate from
   the interface's text grid.
2. RPG Maker character sheets for the player, NPCs and field creatures,
   drawn in one cell, with the walking pattern and row-order depth.
3. Remove per-sprite width, height and anchor for field characters.
4. Maps without graphics render terminal glyphs on the unit.

### Phase 2 - Tilesets and graphical layers

1. Tileset resources with layout validation.
2. Tile identities and autotile composition for A1 to A4.
3. Graphical layer files, loading, validation and retained upload.
4. Remove the glyph-keyed crop tables and cell overrides.

### Phase 3 - Authoring

Map painting with tilesets and autotiles in the GUI Editor, per its plan.

### Phase 4 - Last Legend art

New tilesets and character sheets in RPG Maker's layouts, then the Home
proof, then wider maps. The existing 16 x 32 art is not carried forward.

## Decisions already made (do not relitigate)

- The terminal is the game; collision always derives from terminal layers.
- One cell is one 48 x 48 pixel unit; characters occupy exactly one cell.
- RPG Maker MZ's tile size, sheet layouts, autotiles, tile identities and
  character sheets are adopted as the conventions.
- Graphics are independent authored data, never keyed off glyphs.
- Existing 16 x 32 art is recreated, not migrated.
