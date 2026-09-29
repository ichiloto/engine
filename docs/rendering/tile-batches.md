# Retained world

PHP owns map meaning, collision, camera and authored layer order. Native GPUI
retains source-coordinate world rows, then offsets and clips them using the
current viewport. Camera scrolling does not recollect, project or serialize the
map in PHP. The graphical direction for tiles is
[graphical field](../graphical-field.md).

**Removed behavior:** native stateless `tileBatches` frame output and the direct
`RendererPresentation::present(..., tileBatches: [...])` path are removed; that
argument rejects nonempty lists with guidance to supply a retained world. The
glyph-keyed tile crops (`tiles2d` in map data, per-cell crop overrides, world
layer atlases) are retired: graphics are their own authored data, never
keyed off glyphs. A map data file that still has `tiles2d` loads with a
warning and shows its terminal glyphs. Tilesets and tile layers replace them
(see Tiles below).

## Upload and replacement

`MapManager::getPresentationWorld()` lazily builds one immutable `PresentationWorld`
per installed map and text-width policy. It contains a world definition and the
composed owner rows. A map change replaces that object. The presenter uploads a
changed world once and reuses it during camera movement; leaving the field
removes it explicitly.

The retained operations are:

```json
{"op":"put","kind":"world","id":"map","value":{"columns":2,"rows":1,"cellWidth":48,"cellHeight":48,"layers":[{"id":"map:ground","layer":-100,"kind":"gameplay"}]}}
{"op":"worldRows","id":"map","rows":[{"row":0,"cells":[{"glyph":".","foreground":null,"background":null,"ownerLayerId":"map:ground"},{"glyph":"#","foreground":null,"background":null,"ownerLayerId":"map:ground"}]}]}
```

These are members of a frame's `operations`, not separate message envelopes.
World IDs and layer IDs are stable, control-free UTF-8 strings of at most 256
bytes. Layers have signed i32 priority and `gameplay` or `decoration` kind.
`cellWidth` and `cellHeight` are one terminal cell's size on the field in
logical pixels (48 x 48: one RPG Maker tile).

`worldRows` replaces complete authored rows: one cell per map cell, carrying the
cell's text, its colours, and its owning gameplay layer, up to the declared
world width. Ragged and empty rows are allowed; missing trailing cells stay
absent rather than becoming opaque spaces. Every declared row needs an owner
row before presentation. Owners reference gameplay layers, not decoration.
Putting a world again clears its old rows, so its complete owner rows must be
supplied before presentation. `remove` with `kind:world` removes it; omission
from an update does not.

World coordinates are never preprojected to the hello grid. The renderer draws
each world cell as one `cellWidth` x `cellHeight` box before viewport scaling,
with its text fitted to that box.

## Tiles

A world whose map has graphics also carries a `tileset`: its `tileSize` (48),
its asset-relative `sheets`, and a catalog of `tiles`, each one to four frames
of pieces copied from a sheet into the tile. The Engine composes RPG Maker
autotiles into these pieces; the renderer knows no sheet layouts. World
layers of kind `tiles` own no glyphs, and `worldTiles` lists each row's
`column` and catalog `tile`. A tile is one cell tall and may set its own
`width` and `left` and `top` offsets from its cell's corner (in source pixels).
The Engine sends every tile whole, one per cell, and uses `left` and `top`
only for a map's half-cell layer offsets; it never sends `width`. A cell with
an available tile shows no glyph; cells a tile only overhangs keep theirs. The viewport's optional
`tileFrame` selects each tile's frame `tileFrame % frames`, so water animates
with a camera-only update.

## Bounds and atomicity

- A world has 1..64 layers, dimensions of at most 16384 per axis, and a
  rectangular footprint of at most 1,048,576 logical cells.
- Owner rows must not exceed the declared width. Actor sprites retain their
  separate 1024 limit.
- Retained source state is bounded to 64 MiB, with 128 MiB combined staging and
  visible state. Operation chunks target 32 KiB and NDJSON lines remain at most
  4 MiB.

Uploads can span `present:false` chunks. Only the final validated `present:true`
update replaces visible state. Rejection leaves the previous presentation visible
and requests a reset. See [retained presentation](presentation.md) for generations,
acknowledgements and timeout recovery.

Paint order is ascending layer. Each authored map layer uses `-100 + order`, with
filename prefixes `00..99`, below world actors at 0. Retained layer IDs use
`map:<name>`, including the legacy map's normalized gameplay layer.

## Camera viewport

`viewport.worldId` selects the retained world. `worldOrigin` contains signed
`column` and `row` values in logical world cells; negative origins center small
maps. Native painting subtracts that origin for world content, applies `scale`,
then adds the pixel `origin` and clips to `clipRect` before window fitting.

`textLayerIds` and `spriteIds` name screen-space contributions to scale and clip.
Those coordinates have already been projected by PHP (text in console columns,
one per cell; field sprites in cells), so worldOrigin is not subtracted a second
time. UI not listed remains unscaled. Omitted `viewport` retains the previous
transform; explicit null clears it. A camera-only update therefore needs no
world rows.

A screen-only viewport may omit `worldId`; its world origin must then be zero.
Retained camera transforms are baseline V2 behavior, not gated by the historical
`frame_viewport` capability symbol.
