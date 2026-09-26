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
layer atlases and the `worldTiles` operation) are retired because a crop keyed
by a single character cannot address a two-column map cell. A map data file
that still has `tiles2d` loads with a warning and shows its terminal glyphs.
Tilesets and graphical layers replace them.

## Upload and replacement

`MapManager::getPresentationWorld()` lazily builds one immutable `PresentationWorld`
per installed map and text-width policy. It contains a world definition and the
composed owner rows. A map change replaces that object. The presenter uploads a
changed world once and reuses it during camera movement; leaving the field
removes it explicitly.

The retained operations are:

```json
{"op":"put","kind":"world","id":"map","value":{"columns":2,"rows":1,"cellSize":48,"cellColumns":2,"layers":[{"id":"map:ground","layer":-100,"kind":"gameplay"}]}}
{"op":"worldRows","id":"map","rows":[{"row":0,"cells":[{"glyph":"..","foreground":null,"background":null,"ownerLayerId":"map:ground"},{"glyph":"##","foreground":null,"background":null,"ownerLayerId":"map:ground"}]}]}
```

These are members of a frame's `operations`, not separate message envelopes.
World IDs and layer IDs are stable, control-free UTF-8 strings of at most 256
bytes. Layers have signed i32 priority and `gameplay` or `decoration` kind.
`cellSize` is the square field cell in logical pixels (48, RPG Maker's tile
size) and `cellColumns` the terminal columns one cell holds (2).

`worldRows` replaces complete authored rows: one cell per map cell, carrying the
cell's text (both of its characters, or its two-column glyph), the colours of
its first visible character, and its owning gameplay layer, up to the declared
world width. Ragged and empty rows are allowed; missing trailing cells stay
absent rather than becoming opaque spaces. Every declared row needs an owner
row before presentation. Owners reference gameplay layers, not decoration.
Putting a world again clears its old rows, so its complete owner rows must be
supplied before presentation. `remove` with `kind:world` removes it; omission
from an update does not.

World coordinates are never preprojected to the hello grid. The renderer draws
each world cell as one square of `cellSize` before viewport scaling, with its
text at `cellColumns` columns per square.

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
two per cell; field sprites in cells), so worldOrigin is not subtracted a second
time. UI not listed remains unscaled. Omitted `viewport` retains the previous
transform; explicit null clears it. A camera-only update therefore needs no
world rows.

A screen-only viewport may omit `worldId`; its world origin must then be zero.
Retained camera transforms are baseline V2 behavior, not gated by the historical
`frame_viewport` capability symbol.
