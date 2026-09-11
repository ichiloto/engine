# Optional graphical sprite intent (S5)

This document describes the S5 capability boundary. The subsequent
[S6 runtime](runtime.md) adds project `sprites2d` loading, Game-loop collection
and renderer-only terminal masking without changing these sprite models.

S5 adds an explicitly invoked PHP-side chain:

```text
Player gameplay state -> GraphicalSpriteDefinition + logical world position
  -> GraphicalSpriteProjector using PHP Camera -> PresentationSprite
  -> existing RendererPresentation -> shared RendererClient
```

Ichiloto owns position, heading, movement, collision and Camera state. A renderer
receives presentation data; it does not decide how a character faces or moves.
S5 does **not** launch GPUI from Game, collect sprites during Game rendering,
suppress terminal glyphs, or load project graphical configuration automatically.
Last Legend and the renderer repository remain untouched.

## Models and validation

`Rendering\Sprites\GraphicalSpriteDefinition` is a final readonly value containing
only `asset`, `width`, `height`, `anchor` and `layer`. It contains no coordinates,
Camera, frame number or transport. Width and height are logical pixels (1-4096),
not grid cells. Layer is a signed 32-bit integer. The shared
`PresentationSpriteAnchor::BOTTOM_CENTER` enum remains the only anchor; layer
defaults to zero.

`SpriteValidation` supplies the same structural asset, dimension and layer checks
to both definitions and S4 `PresentationSprite`, without weakening that boundary.
Asset paths must be nonempty UTF-8, relative and forward-slash-separated, with no
NUL, absolute/drive/URI prefix, backslashes or `..` path components. There is no
filesystem lookup, image loading or PNG decoding in PHP. Renderer asset-root
containment, symlink checks and decoding remain renderer responsibilities.

`DirectionalGraphicalSpriteSet` is also final readonly. Its constructor requires
four typed definitions: `north`, `east`, `south`, `west`. Each may have independent
dimensions and layer. `getForHeading(MovementHeading)` uses the existing gameplay
enum, with `NONE` resolving to south. No partial-direction fallback is introduced.

## Authored data

`DirectionalGraphicalSpriteSet::fromArray()` accepts this complete structure:

```php
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;

$graphicalSprites = DirectionalGraphicalSpriteSet::fromArray([
    'north' => [
        'asset' => 'Graphics/Characters/Hero/Field/North.png',
        'width' => 32, 'height' => 48, 'anchor' => 'bottom_center', 'layer' => 100,
    ],
    'east' => [
        'asset' => 'Graphics/Characters/Hero/Field/East.png',
        'width' => 32, 'height' => 48, 'anchor' => 'bottom_center', 'layer' => 100,
    ],
    'south' => [
        'asset' => 'Graphics/Characters/Hero/Field/South.png',
        'width' => 32, 'height' => 48, 'anchor' => 'bottom_center', 'layer' => 100,
    ],
    'west' => [
        'asset' => 'Graphics/Characters/Hero/Field/West.png',
        'width' => 32, 'height' => 48, 'anchor' => 'bottom_center', 'layer' => 100,
    ],
]);
```

All four lowercase cardinal keys are required. Each entry requires a string asset
and integer width/height. Only omitted `anchor` and `layer` default to
`bottom_center` and `0`; explicit null is invalid. Numeric strings, floats,
unknown fields/directions, unsupported anchors and malformed entries throw
`InvalidArgumentException`. Entry errors identify the affected direction.
Parsed immutable values are detached from caller-owned array references.

This is a reusable parser, not a project configuration loader. No `sprites2d`
key is consumed, and no `assets/Data/Entities/player.php` is read by this feature.

## Provider capability and Player

`GraphicalSpriteProviderInterface` exposes three methods:

| Method | Meaning |
| --- | --- |
| `getGraphicalSpriteId(): string` | Stable object identity, unique in a composed frame |
| `getGraphicalSpriteDefinition(): ?GraphicalSpriteDefinition` | Current optional graphical intent |
| `getGraphicalSpriteWorldPosition(): Vector2` | Logical world/grid position, never screen/pixel coordinates |

The provider receives no Camera or client and never draws, polls or sends.
GameObject itself does not implement the capability. Player is the first
production implementation; future object types must opt in independently.

Player accepts an appended optional `?DirectionalGraphicalSpriteSet
$graphicalSprites = null` constructor argument. Existing calls are unchanged;
without a set, its definition is null and it remains terminal-only. Its stable ID
is `player`, representing the unique field player, independent of character name,
heading, position and GameObject's random hash. Do not include multiple field
Players under this ID in one frame; S4 rejects duplicate IDs.

Player resolves graphical art directly through its current `$heading`. It has no
graphical-facing state machine. `getGraphicalSpriteWorldPosition()` returns a
defensive clone of `$position`, without terminal width or overhang corrections.
Neither the optional set nor the getter changes `$sprite`, terminal directional
arrays or `PlayerSpriteSet` behavior. Terminal rendering and erasure remain
unchanged, even when a graphical definition exists.

Existing `tryMove()` updates facing before checking collision. A northward move
into a solid tile therefore changes both PHP heading and the resolved graphical
asset to north, while world position and projected x/y stay unchanged. Graphical
code does not participate in collision or synchronize movement separately.

## Explicit Camera projection

```php
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;

// Explicit caller-owned composition, not installed in the Game loop by S5.
$sprite = (new GraphicalSpriteProjector())->project($player, $camera);
$presentation->present(Console::snapshot(), $sprite === null ? [] : [$sprite]);
```

The projector returns null immediately for absent intent. Otherwise it calls
`Camera::getScreenSpacePosition()` with the provider's logical position and maps
the result into the existing `PresentationSprite`. Centering of small maps,
scrolling offsets and resized viewports all come from Camera; no camera formula
or Player special case is reproduced. The adapter has no client or Console
dependency and does not mutate providers or Camera.

Protocol x/y are screen-grid cells, **not pixels**. Camera's finite floating-point
results are range-checked before an explicit integer cast, using the same
truncation toward zero as its terminal drawing methods. Nonfinite or out-of-i32
results throw rather than wrapping. Off-grid coordinates are retained for
renderer clipping; no second visibility algorithm exists. The renderer converts
cells to logical pixels using session geometry and applies the bottom-center
anchor described in [S4 presentation](presentation.md).

Each projection is a new immutable DTO. S4 still suppresses unchanged values,
sequences successful enqueues transactionally, and borrows the shared client
without polling input or owning shutdown. Calling the projector alone does not
refresh terminal glyphs, send a frame or consume input.

## Save and runtime boundaries

Graphical definitions are project/presentation configuration, not mutable save
state. SaveManager, GameConfig serialization, compatibility schemas and fixtures
are unchanged. GameScene still saves explicit gameplay/terminal fields rather
than serializing Player itself. Loading graphical configuration for both newly
created and restored Players belongs to a later integration phase.

S6 must explicitly compose runtime collection, project loading, shared-client
lifecycle and any terminal-glyph masking policy. Those are not implicitly enabled
by this capability. NPC sprites, graphical maps/tiles, animation, interpolation,
battles and graphical UI are also outside S5.

See [S5 validation](s5-validation.md) for automated evidence and handoff details.
