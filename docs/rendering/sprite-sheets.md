# Field Player sprite sheets (S8-A)

PHP owns direction, animation time and frame selection. GPUI receives a source
rectangle and draws that crop; it does not run an animation or move the Player.
This extends the existing graphical provider/projector path. Only the field
Player is integrated; NPCs, battlers, tilemaps and animation scripting are out of scope.

## Project metadata

Keep the existing terminal `sprites` entry in `assets/Data/Entities/player.php`.
Use this alternative `sprites2d` structure for a contiguous, row-major walk set:

```php
'sprites2d' => [
    'mode' => 'sheet',
    'frameWidth' => 256,
    'frameHeight' => 256,
    'width' => 56,
    'height' => 56,
    'frameDurationMs' => 80,
    'stepDurationMs' => 160,
    'idleFrame' => 0,
    'anchor' => 'bottom_center',
    'layer' => 100,
    'directions' => [
        'south' => ['asset' => 'Graphics/Characters/Kaelion/Field/South.png', 'columns' => 5, 'rows' => 5, 'frames' => 22],
        'east' => ['asset' => 'Graphics/Characters/Kaelion/Field/East.png', 'columns' => 5, 'rows' => 5, 'frames' => 21],
        'north' => ['asset' => 'Graphics/Characters/Kaelion/Field/North.png', 'columns' => 5, 'rows' => 4, 'frames' => 17],
        'west' => ['asset' => 'Graphics/Characters/Kaelion/Field/West.png', 'columns' => 5, 'rows' => 5, 'frames' => 21],
    ],
],
```

These are the verified Kaelion counts, not four identical 22-frame grids. North
is 1280x1024; the other sheets are 1280x1280. Unpopulated trailing cells are never
selected. The supplied images are preserved byte-for-byte, including transparency
and cell padding. No per-frame files or image generation are required.

Source cell dimensions are image pixels. Destination `width`/`height` are logical
display pixels, independent of the source dimensions. Square 56x56 destinations
preserve the 256x256 cells' proportions and give these padded resting frames
roughly 43-46 visible pixels of height. Position remains PHP Camera-projected grid
coordinates; anchor and layer semantics are unchanged.

All four directions and their asset/grid/frame-count fields are required. Shared
source and destination dimensions are required. Defaults are `bottom_center`,
layer 0, idle frame 0, 80 ms per frame and 160 ms per successful step. Integers
must be integers, not numeric strings or floats. Unknown fields, invalid counts,
out-of-range idle frames and durations outside 1-60000 ms fail validation.
Source geometry must fit unsigned 32-bit image coordinates. PHP validates authored
geometry without reading PNGs; GPUI validates each crop against the decoded image.

## Animation ownership

`SpriteSheet` maps a zero-based frame to its source rectangle. Immutable
`GraphicalSpriteDefinition` carries the sheet and current crop; calling
`atFrame()` produces another definition without changing gameplay state.
`SpriteWalkAnimation` is the Player's small presentation-only state machine:

- A successful position change starts or renews the authored walking interval.
- Continued successful steps in the same direction preserve animation phase.
- GameScene advances elapsed animation time once per normal update, independent
  of how often presentation is collected. The presentation getter is read-only.
- Frames advance sequentially and wrap at that direction's populated count.
- A new direction or a new walk after idle starts at frame 0. On interval expiry,
  the authored `idleFrame` is restored.
- Rejected movement, explicit facing, interaction, event-session start, scene-state
  changes and suspension stop walking. Facing a wall can still change the sheet,
  but does not start animation or move the Player.

The interval models successful grid steps, not key-down/key-up state. It does not
add key-repeat handling, interpolate position, change movement speed or alter
collision. Holding a key can keep animation running only when the existing input
path produces successful steps frequently enough. Long stalls settle to idle
rather than replaying old animation time. Existing S7 repeat/repaint limitations
are not fixed or hidden by this slice.

Both new and restored Players load current project metadata. Animation state and
graphical configuration are not added to saves. Whole-image directional sets and
terminal-only projects retain their existing format and rendering behavior.

## Negotiated renderer contract

Protocol versions 1 and 2 both support the optional `sprite_source_rect`
capability. There is no version 3. Before sending a cropped sprite, request:

```json
"requiredCapabilities": ["sprite_source_rect"]
```

The renderer must acknowledge it on `ready`:

```json
"capabilities": ["sprite_source_rect"]
```

Missing acknowledgment fails startup. Engine presentation also rejects crops
without a negotiated capability before enqueueing a frame. Automatic GPUI startup
requests it; custom integrations can pass
`requiredCapabilities: [RendererSessionConfig::SPRITE_SOURCE_RECT]` to
`RendererRuntimeConfig` or a low-level `RendererSessionConfig`.

`PresentationSprite` adds an optional field:

```json
"sourceRect": {"x": 512, "y": 256, "width": 256, "height": 256}
```

These are strict nonnegative integer origins and positive integer extents.
Omission means the legacy full-image path; explicit null is invalid. Omitting
capabilities preserves legacy hello/ready fields. Whole-image sprites also work
in negotiated sessions. Crop changes participate in frame equality, so a new
animation frame is sent even if the Player's position and asset are unchanged.

GPUI reuses the decoded full sheet and clips the selected rectangle at draw time.
It does not decode or allocate cropped images on each frame. Cache limits and
image-lifetime details belong to the Renderer documentation.

See [S8-A validation and handoff](s8-a-validation.md) for results and remaining checks.
