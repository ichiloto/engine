# Protocol v2 tile batches (S8-B)

Engine and GPUI contract frozen on 2026-09-13. This is a negotiated v2
extension, not protocol v3. PHP owns map meaning, camera and destination cells.
GPUI crops and paints only; it does not infer terrain, collision or movement.

## Negotiation and replacement

Automatic GPUI startup requests `sprite_source_rect` and `tile_batches`, even
before entering a map with terrain. `tile_batches` is invalid in v1. Any v2
`tileBatches` field presence, including `[]`, requires negotiated support.
Omission or `[]` clears previous tiles as part of a complete frame replacement;
`null` is invalid. Text-only and actor-sprite-only v1/v2 frames remain valid.

Each batch has exactly these required fields:

```json
{"id":"terrain","asset":"Graphics/Tilesets/Garden/Field.png","layer":-100,"sources":[{"x":0,"y":0,"width":16,"height":32}],"cells":[{"column":8,"row":3,"source":0}]}
```

IDs are nonempty UTF-8, unique in the batch namespace, at most 256 bytes.
Assets are nonempty confined relative PNG paths, at most 4096 UTF-8 bytes.
`layer` is i32. Rectangles use the existing S8-A unsigned image-pixel contract:
nonnegative x/y, positive width/height, endpoints within u32 and the decoded
atlas. Every source, including unused sources, is validated. Cells contain
strict u32 column/row/source integers; column/row must be within the hello grid
and source must index the catalog. Unknown fields, floats and numeric strings
are rejected. Sources are nonempty; cells may be empty. Duplicate destination
cells within one batch are invalid; overlap across batches is allowed.

One destination covers exactly one session cellWidth by cellHeight rectangle.
Source pixel proportions never change logical camera or gameplay coordinates.

## Independent budgets and atomicity

- At most 64 batches per frame.
- At most 256 sources per batch and 4096 sources across all batches.
- At most 32768 cells across all batches, independently of the unchanged
  1024 actor-sprite limit.
- Existing 4 MiB NDJSON frame limit remains in effect.
- Tiles and sprites share the existing 64 MiB decoded-image and 1024 unique
  image budgets, PNG validation and root confinement.

Any structural, bounds, path, decode or budget failure rejects the entire frame
before replacing displayed state. No partial terrain/text/sprite mutation.
Prepared source regions, if required by the painter, are bounded and reused;
their allocation and accounting must be reported separately from decoded PNGs.
GPUI's edge-extruded prepared-region cache has a separate 64 MiB / 4096-region
frame and cache limit. It is keyed by decoded atlas identity and source rectangle,
not by destination cell, and never creates cropped PNG files per frame.

Paint order is ascending layer, with ties ordered tiles, text, sprites. Incoming
order within each type and within the cells array is stable. Engine terrain uses
-100, world text 0, graphical Player the existing world-sprite policy and UI its
existing priorities. Clearing, cinematic eligibility and snapshot rollback must
not retain terrain from the previous full frame.

## Optional map metadata

`tiles2d` in the current map's `.data.php` is optional. When present it contains
exactly `asset` and `symbols`:

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

Symbols explicitly map one terminal symbol of
display width one to an S8-A source rectangle. ANSI styling is normalized using
TerminalText. Numeric PHP array keys are accepted as their literal symbols;
duplicate normalized keys, controls, wide/combining-only symbols and malformed
rectangles fail with map context. Space is never implicit. Missing metadata and
unmapped symbols retain terminal presentation.

Only the current visible in-bounds map region is collected, through the same
Camera bounds and projection as text. No duplicate map or collision grid exists.
An Engine runtime requiring `tile_batches` accepts at most 32,768 viewport cells
so one fully mapped field fits the frame budget. Larger configurations fail
before starting the renderer or changing input/Console ownership; reduce columns
or rows rather than silently degrading terrain. This is an Engine runtime policy,
not a change to low-level protocol grid limits. Custom overlapping batches and
styled text still have their independently validated aggregate frame limits.
Graphical snapshots omit a replaced terrain write and its opaque underlay using
draw provenance, not equality with the final glyph. Later text, including an
identical glyph or a deliberate blank, remains opaque. Canonical Console output
is unchanged.

An unmapped wide glyph retains the existing terminal path. If its display width
shifts subsequent text away from logical map anchors, those shifted cells remain
text rather than cutting holes at incorrect coordinates. No camera or collision
coordinate is changed to compensate for wide art.
