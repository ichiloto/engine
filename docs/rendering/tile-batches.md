# Retained world layers and tile artwork

PHP owns map meaning, collision, camera and authored layer order. Native GPUI
retains source-coordinate world rows and tile catalogs, then offsets and clips
them using the current viewport. Camera scrolling does not recollect, project or
serialize every visible tile in PHP.

**Removed behavior:** native stateless `tileBatches` frame output and the direct
`RendererPresentation::present(..., tileBatches: [...])` path are removed. That
argument rejects nonempty lists with guidance to supply a retained world.
`PresentationTileBatch` and old `StyledPresentationFrame` encoders remain reference
tooling, not native accepted packets. The authored `tiles2d` format is unchanged.
Automatic startup still requests the existing `sprite_source_rect` and
`tile_batches` capabilities; retaining state adds no capability flag.

## Upload and replacement

`MapManager::getPresentationWorld()` lazily builds one immutable `PresentationWorld`
per installed map and text-width policy. It contains a world definition, composed
owner glyph rows, and crop rows for each mapped layer. A map change replaces that
object. The presenter uploads a changed world once and reuses it during camera
movement; leaving the field removes it explicitly.

The retained operations are:

```json
{"op":"put","kind":"world","id":"map","value":{"columns":2,"rows":1,"layers":[{"id":"map:ground","layer":-100,"kind":"gameplay","asset":"Graphics/Tilesets/Field.png","sources":[{"x":0,"y":0,"width":16,"height":32}]}]}}
{"op":"worldRows","id":"map","rows":[{"row":0,"cells":[{"glyph":".","foreground":null,"background":null,"displayWidth":1,"ownerLayerId":"map:ground"},{"glyph":".","foreground":null,"background":null,"displayWidth":1,"ownerLayerId":"map:ground"}]}]}
{"op":"worldTiles","id":"map","layerId":"map:ground","rows":[{"row":0,"cells":[{"column":1,"source":0}]}]}
```

These are members of a frame's `operations`, not separate message envelopes.
World IDs and layer IDs are stable, control-free UTF-8 strings of at most 256
bytes. Layers have signed i32 priority and `gameplay` or `decoration` kind.
Optional atlas paths and source catalogs appear together. Paths remain confined
asset-root-relative PNG references; source rectangles use image pixels.

`worldRows` replaces complete authored rows: one glyph/colour/display-width/owner
cell per authored column, up to the declared world width. Ragged and empty rows
are allowed; missing trailing columns stay absent rather than becoming opaque
spaces. Every declared row needs an owner-row entry before presentation.
Owners reference gameplay layers, not decoration.
`worldTiles` replaces the listed sparse rows for one layer; each cell has a world
`column` and source-catalog index. An empty tile-cell list clears that row. Omitted
rows persist. Putting a world again clears its old rows and tiles, so its complete
owner-row entries must be supplied before presentation. `remove` with `kind:world`
removes it; omission from an update does not.

World coordinates are never preprojected to the hello grid. One tile destination
uses one session cellWidth by cellHeight rectangle before viewport scaling.
Source image proportions do not change camera or gameplay coordinates.

## Bounds and atomicity

- A world has 1..64 layers, dimensions of at most 16384 per axis, and a rectangular
  footprint of at most 1,048,576 logical cells.
- A layer has at most 256 crop sources; Engine authoring retains its aggregate
  4096-source limit. Up to 1,048,576 tile candidates can be retained across a world.
- Owner rows must not exceed the declared width. Tile rows use unique in-bounds
  columns and valid source indexes. Actor sprites retain their separate 1024 limit.
- Retained source state is bounded to 64 MiB, with 128 MiB combined staging and
  visible state. Operation chunks target 32 KiB and NDJSON lines remain at most 4 MiB.
- Image decoding, path confinement and prepared crop caches remain separately
  bounded; retaining tile rows does not bypass image validation or allocate a
  cropped PNG per destination.

Uploads can span `present:false` chunks. Only the final validated `present:true`
update replaces visible state. Rejection leaves the previous presentation visible
and requests a reset. See [retained presentation](presentation.md) for generations,
acknowledgements and timeout recovery.

Paint order is ascending layer. Each authored map layer uses `-100 + order`, with
filename prefixes `00..99`, below world actors at 0. Retained layer IDs use
`map:<name>`, including the legacy map's normalized gameplay layer. Cropped cells
suppress only their owning map text, never later screen-space actors or UI.

## Optional map metadata

`tiles2d` in the current map's `.data.php` is optional. A legacy map without
`layers/` retains the flat `asset` and `symbols` format:

```php
'tiles2d' => [
    'asset' => 'Graphics/Tilesets/Garden/Field.png',
    'symbols' => [
        ';' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 32],
        '~' => ['x' => 16, 'y' => 0, 'width' => 16, 'height' => 32],
        'x' => ['x' => 32, 'y' => 0, 'width' => 16, 'height' => 32],
    ],
],
```

### Authored layers

A map with `layers/` uses a `layers` table keyed by the exact authored layer name,
without its order prefix. It can provide a shared atlas or a different atlas per
layer. For example, given `01.ground.map.php`, `02.floor.deco.php` and
`03.structures.map.php`:

```php
'tiles2d' => [
    'asset' => 'Graphics/Tilesets/Outdoor.png',
    'layers' => [
        'ground' => [
            'symbols' => [
                'x' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 32],
            ],
        ],
        'floor' => [
            'asset' => 'Graphics/Tilesets/Indoor.png',
            'symbols' => [
                'r' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 32],
            ],
        ],
        'structures' => [
            'symbols' => [
                'x' => ['x' => 16, 'y' => 0, 'width' => 16, 'height' => 32],
            ],
        ],
    ],
],
```

Here `ground:x` and `structures:x` deliberately select different crops; no
colour inference is involved. The names are examples, not required Engine layer
conventions. A layer entry contains `symbols`, `cells`, or both, and an optional
`asset`; without a local asset it inherits the shared one. Unknown layer names
and fields, empty mapping definitions and missing effective atlas paths are
rejected.

Gameplay layers may omit the table or leave individual symbols unmapped. Every
non-space decoration cell must have a symbol mapping or an explicit cell override.
Upper-layer spaces, including decoration spaces, stay empty even if a space
symbol crop was declared; an explicit cell override can place artwork at a
particular blank cell. A mapped space on the base gameplay layer can render.
Decoration never changes
the composed terminal grid or collision. See [map layers](../maps.md) for source
validation and collision precedence.

Retained layers preserve `map:<name>` and filename-derived z-order. Separate
layers may reuse an atlas and overlap world cells; destinations within one layer
row remain unique. Offscreen rows stay retained rather than being removed and
reuploaded as the camera moves.

### Cell-specific artwork

Repeated terminal symbols need not repeat the same picture. A table drawn as
`###` can use separate left, middle and right image crops without changing those
symbols, the collision dictionary, or its interaction. Add a `cells` list to
the owning layer's definition (or the flat definition on a legacy map):

```php
'fixtures' => [
    'asset' => 'Graphics/Tilesets/Furniture.png',
    'symbols' => ['#' => ['x' => 16, 'y' => 0, 'width' => 16, 'height' => 32]],
    'cells' => [
        ['column' => 4, 'row' => 2,
         'source' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 32]],
        ['column' => 6, 'row' => 2,
         'source' => ['x' => 32, 'y' => 0, 'width' => 16, 'height' => 32]],
    ],
],
```

Coordinates are zero-based logical map cells, never screen or image coordinates.
The explicit cell takes precedence over the symbol default. `symbols` may be
omitted when all mappings are explicit. Each cell has exactly `column`, `row`
and `source`; coordinates must be nonnegative integers within the actual
authored row, including ragged maps. Duplicate positions, unknown fields and
invalid rectangles are refused with map context before replacing active geometry.
Overrides share the existing deduplicated 256-source layer catalog and have a
32,768-entry authored limit. These authoring limits are distinct from retained
world-size and transport budgets.

These positions select fidelity only, not gameplay identity. Supplemental art
may occupy blank cells of a sparse fixture footprint without making them solid.
Only text contributed by the owning layer is replaced; art on a decoration layer
does not suppress a different fixture or an NPC. Keep map-owned objects' crops
on their contributing gameplay layer so transparent corners reveal lower map
art, not the object's old text backing. Later player, dialogue and UI content
remains intact. Wide/shifted glyphs retain the existing text fallback even when
an override is present.

### Mixed interior materials

Use decoration layers to vary appearance without changing gameplay. For example,
an interior may stack these authored layers:

```text
01.ground.map.php       blank walkable floor
02.floor.deco.php       w wood, k kitchen tile, s stone
03.rugs.deco.php        r rug, c carpet, spaces elsewhere
04.walls.map.php        solid wall geometry
05.wall-detail.deco.php cosmetic writing, trim and ornaments
06.fixtures.map.php    visible interaction markers
```

The material letters are examples of game-owned crop keys, not Engine tile types.
Each non-space decoration marker has an explicit crop in its layer's `symbols`
table. Wood, kitchen tile and stone may select different crops within one layer;
a carpet layer can cover stone without changing its walkability. Floor detail
sits above blank ground and below walls. Wall ornaments sit above wall artwork,
but never replace its collision. All these layers remain below world actors.

Do not map every blank space to one universal floor: the same map can contain
interior floors, doorways, exterior space and gaps in its drawing. Author the
material coverage explicitly. Native terminal players still see the underlying
blank floors, solid wall glyphs and interaction markers, not the decoration
letters. A readable notice or usable object belongs to a gameplay fixture or
event, while meaningless writing and wall texture remain decoration. Decoration
cannot turn a wall into a doorway or a rug into an obstacle.

### Symbols and fallback

Symbols explicitly map one terminal symbol of
display width one to an image source rectangle. ANSI styling is normalized using
TerminalText. Numeric PHP array keys are accepted as their literal symbols;
duplicate normalized keys, controls, wide/combining-only symbols and malformed
rectangles fail with map context. Space is never implicit. Missing metadata and
unmapped symbols retain terminal presentation.

The GPUI field bypasses Console map drawing only after a valid retained world
has been constructed. A glyph-only world needs no `tiles2d` metadata. If mapped
world construction fails, MapManager reports the failure and attempts a complete
glyph-only world; if no valid world can be built, ordinary screen-space Console
map drawing remains available. T1 continues its existing map draw/restore path.

In retained-world mode, synthetic Console base blanks are omitted rather than
painting over the map. Camera background restoration removes stale dynamic cell
contributions without writing an opaque space. Explicit authored base spaces,
named UI and overlays remain intact. See [Console row tracking](styled-presentation.md#incremental-console-api).

Unmapped composed map text retains the z-order of its owning gameplay layer.
Mapped cells are replaced only at that layer; decoration can paint over lower
map content without erasing a higher gameplay glyph. The synthetic opaque
WORLD-plane underlay was removed from replaced map drawing because it covered
all negative-z map layers. This does not remove canonical terminal cells or
later world/UI writes.

The world upload carries normalized glyph widths and ownership once. Native
projection keeps unmapped wide glyphs and shifted cells as text rather than
cutting tile holes at incorrect coordinates. No camera or collision coordinate
is changed to compensate for wide art. PHP retains the existing map/collision
source of truth; uploaded world data is derived presentation only.

## Camera viewport

`viewport.worldId` selects the retained world. `worldOrigin` contains signed
`column` and `row` values in logical world cells; negative origins center small
maps. Native painting subtracts that origin for world content, applies `scale`,
then adds the pixel `origin` and clips to `clipRect` before window fitting.

`textLayerIds` and `spriteIds` name screen-space contributions to scale and clip.
Those coordinates have already been projected by PHP, so worldOrigin is not
subtracted a second time. UI not listed remains unscaled. There is no
`tileBatchIds` field in the retained wire viewport. Omitted `viewport` retains
the previous transform; explicit null clears it. A camera-only update therefore
needs no world rows, crop catalog or tile-cell payload.

A screen-only viewport may omit `worldId`; its world origin must then be zero.
Retained camera transforms are baseline V2 behavior, not gated by the historical
`frame_viewport` capability symbol.
