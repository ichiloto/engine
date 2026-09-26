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
2. **One cell is one unit, square in every renderer.** A map cell is two
   terminal columns wide, which is square on a terminal whose character
   boxes are about twice as tall as wide, and one 48 x 48 pixel tile in a
   graphical renderer. Everything on the field is sized in cells, never in
   pixels or columns. A character occupies exactly one cell in every
   renderer, so what the player sees agrees with what collides, and a map
   keeps its proportions in both.
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
- A terminal character box is about twice as tall as it is wide, so a map
  cell spans two terminal columns. The map is the same grid of square
  cells in both renderers, and a map drawn to look right in the terminal
  looks right as tiles.

## Map cells

- **Content.** A cell holds either one two-column glyph (an emoji or CJK
  character) or two one-column characters side by side, each with its own
  style. `##`, `[]`, `~~` and `🌲` are each one cell. Every authored row is
  whole cells; a one-column character followed by a two-column glyph, or a
  lone trailing character, is refused with its row and column.
- **Blank.** A cell whose characters are all spaces is blank. In an upper
  layer a blank cell is transparent; any other cell replaces the cell below
  it whole.
- **Collision.** Collision dictionaries keep single-character keys. A cell
  is solid when any of its characters is solid; otherwise it takes the
  first kind other than none among its characters, left to right. Pairing
  can only make a cell more solid, never open a wall.
- **Events.** An event cell's marker is its non-blank character (`E `,
  ` E` and `EE` all mark event `E`); two different markers in one cell are
  refused.
- **Characters.** The player, NPCs and staged actors occupy one cell. A
  one-column terminal sprite draws in the cell's first column and a
  two-column sprite fills it. Movement is one cell per step, so a step
  covers the same screen distance horizontally and vertically.
- **Camera.** The camera and everything addressing the field work in
  cells. Cells become terminal columns only where text is written to the
  console, so the terminal field shows half as many cells across as the
  console has columns.
- **Graphical field.** A retained world cell is one field cell whatever its
  text; glyph fallback draws the cell's text at two terminal columns per
  square, so an unpainted map looks like its terminal presentation scaled.
- **Format version.** Two-column cells change what every x coordinate
  means, so they begin a new project format version. The game, editor and
  validator refuse a project whose format is older than the engine's and
  point to `ichiloto upgrade`, rather than misplace its contents.

## Converting existing projects

`ichiloto upgrade` is the project's format chain. A project records its
format version in `ichiloto.json`, and the command runs every numbered
step between that version and the engine's, in order, like the save
compatibility chain. Each step is its own class; a later format change
adds a step and never edits an earlier one. The existing metadata upgrade
(project id and save compatibility manifest) is the first step.

The version is bookkeeping the tools write and read; nobody tracks it.
The experience follows established upgraders (Unity and Godot on
opening an older project, framework migrations, `ng update`):

1. **Detect.** `ichiloto play`, `validate` and the editor notice a project
   behind the engine's format and say what is outdated and to run
   `ichiloto upgrade`, instead of failing on the old data. The editor may
   offer the upgrade when it opens such a project.
2. **Explain.** `ichiloto upgrade` takes no arguments. It first lists, one
   line per pending step, what will change (for example, 30 maps converted,
   coordinates halved in 57 files, a save migration added), then asks to
   continue. `--dry-run` prints the same list and changes nothing.
3. **Protect.** It refuses to run over uncommitted changes unless told to,
   so each upgrade is one reviewable, reversible change.
4. **Report.** It ends with the follow-up list for a person and writes it
   to a file in the project.

The two-column cell step converts a project from one-column cells:

- Each map layer and event layer groups every two columns into one cell,
  so the terminal art is unchanged. A row with an odd width gains a
  trailing space.
- Every field x coordinate is halved (rounded down): NPC positions and
  wander areas, event spawn points, transfers and player moves, staged
  actors, camera targets, cinematic cast and waypoints, and the new game
  start. Widths become the cells their old columns covered.
- Horizontal move route step counts are halved and reported, because the
  exact count depends on where the route starts at runtime.
- Saved games keep working through the project's save compatibility
  chain: the conversion adds a content migration that halves the saved
  player's x after every earlier migration.
- The conversion writes a report of everything that needs a person: cells
  that became solid from mixing a wall and a floor character (such as a
  one-column doorway), cells holding two different event markers, NPCs,
  events and spawn points that now stand in a solid cell, and halved
  route counts. Re-proportioning furniture and rooms for square cells is
  the author's work after the conversion.

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

### Phase 2 - Square map cells

1. Two-column map cells in the engine: parsing, composition, collision,
   events, the camera, terminal and retained presentation, and the
   project format version check. The glyph-keyed crop tables (`tiles2d`)
   cannot address two-column cells and are removed here, ahead of
   Phase 3; maps using them show their terminal glyphs until tilesets.
2. The TUI editor paints, selects and validates two-column cells.
3. Turn `ichiloto upgrade` into the project format chain, with the
   two-column cell step and its review report.
4. Convert Last Legend and Epic Quest, then work through the report.

### Phase 3 - Tilesets and graphical layers

1. Tileset resources with layout validation.
2. Tile identities and autotile composition for A1 to A4.
3. Graphical layer files, loading, validation and retained upload.

### Phase 4 - Authoring

Map painting with tilesets and autotiles in the GUI Editor, per its plan.

### Phase 5 - Last Legend art

New tilesets and character sheets in RPG Maker's layouts, then the Home
proof, then wider maps. The existing 16 x 32 art is not carried forward.

## Decisions already made (do not relitigate)

- The terminal is the game; collision always derives from terminal layers.
- One map cell is two terminal columns and one 48 x 48 pixel tile, square
  in both; characters occupy exactly one cell.
- RPG Maker MZ's tile size, sheet layouts, autotiles, tile identities and
  character sheets are adopted as the conventions.
- Graphics are independent authored data, never keyed off glyphs.
- Existing 16 x 32 art is recreated, not migrated.
