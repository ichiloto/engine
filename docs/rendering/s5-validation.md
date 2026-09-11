# S5 validation: Player graphical sprite capability

**S5 passes. The proof is PHP-only. No Game-loop integration, terminal masking,
project graphical loading, renderer changes or Last Legend changes were added.
S6 has not begun.**

Engine baseline: clean synchronized `develop` at
`fd95c1c4f85b1acece41faa767b3efe274e8f21b` (S4). Renderer reference: clean
synchronized `develop` at `c9e87bb56756a7f3d4b18948949be4aa13ddbc79`.
Validation ran on Apple Silicon macOS with PHP 8.5.10. A new native GPUI run is
not required: S4 already proved the unchanged PresentationSprite contract.

## Automated acceptance

- Before changes: 981 passed, one existing skip, 3427 assertions (7.52 s).
- Full suite after changes: 1068 passed, one existing skip, 3722 assertions (7.69 s).
- Four new S5 suites: 87 passed, 297 assertions (0.33 s), no warnings.
- Focused S2/S3/S4, Player, Camera and event-movement regression run:
  319 passed, 1174 assertions (4.50 s).
- SaveCompatibility, SaveManager and SaveSlotWindow suites:
  16 passed, 91 assertions (0.14 s).
- Full `composer analyse -- --debug --no-progress` passes; all sprite and
  presentation classes also pass targeted PHPStan level 6.
- All 12 added/changed PHP files pass `php -l`; `git diff --check` passes.

The full suite retains pre-existing non-TTY `stty` diagnostics. The new S5 suites
do not emit them. Transport assertion totals can vary slightly with poll timing;
the counts above record the actual runs. No separate Linux environment was used.
Existing POSIX transport and terminal paths were exercised on macOS and not
modified by this change.

## Added coverage

`GraphicalSpriteDefinitionTest` checks immutable intent fields, absent filesystem
dependencies, portable UTF-8 names, exact dimension/layer bounds and invalid
paths/encoding/geometry against both the new definition and S4 PresentationSprite.
Both use the extracted shared `SpriteValidation`; S4's boundary remains intact.

`DirectionalGraphicalSpriteSetTest` checks four independent immutable directions,
all MovementHeading values including NONE/south, direct construction, documented
array parsing, omitted anchor/layer defaults, caller-reference isolation, missing
directions, wrong types, malformed values and unknown keys. No coercion or partial
direction fallback is permitted.

`GraphicalSpriteProjectorTest` exercises a non-Player provider, mapping of every
DTO field, null short-circuiting, explicit Camera delegation, real Camera small-map
centering and resizing, scrolling, off-grid preservation, no mutation, fractional
cell conversion and rejection of nonfinite/overflowing projected values.

`PlayerGraphicalSpriteTest` constructs actual Players and Cameras. Only unrelated
Game lifecycle/scene bootstrap is isolated. It checks legacy constructor calls,
optional definitions, canonical `player` ID, every heading, terminal configurations
and actual Console rendering with and without graphical sets. ASCII, wide Unicode
and multi-row terminal art do not affect graphical world anchoring. Returned world
positions are defensive copies. Projection leaves Player, Camera, Console, cursor,
output and an independent transport unchanged.

### Actual Player to FRAME

The integration test renders the real Player's terminal `vv` into Console at
world/screen (7,4), captures that Console snapshot and explicitly projects south
art. RendererPresentation queues FRAME 1 through a shared RendererClient and fake
transport. Updating the real PHP heading to north changes **only** the projected
asset; ID and x/y remain unchanged. The same text snapshot plus north art becomes
FRAME 2.

Changing Player world position to (11,8) and Camera position to (3,2) produces
screen cell (8,6) through the existing Camera, becoming FRAME 3. Repeating unchanged
state queues nothing. All three exact payloads are asserted, and previously
projected DTOs retain their original values. Presentation does not poll or shut
down the client; RendererInputSource can still consume a pending Up key through
that same client. Explicit terminal rendering afterwards still draws `^^` at
(8,6). Graphical projection itself neither hides nor refreshes terminal glyphs.

### Actual blocked movement

A lightweight concrete MapManager fixture marks (7,3) SOLID while the real Player
begins south-facing at (7,4). The test calls unmodified `Player::tryMove(up)` and
the real `MapManager::canMoveTo()`, not a reimplemented movement method. The move
returns false; world position stays (7,4), heading becomes NORTH and the graphical
definition changes to North.png. Comparing both projected DTOs shows only `asset`
changed. Existing blocked-move rendering also draws the north terminal glyph at
the same tile. No graphical class contains collision or movement logic.

## Scope and handoff

Production additions are five small classes under `src/Rendering/Sprites`:
definition, complete directional set/parser, provider interface, projector and
shared validation. Player adds only an optional constructor parameter and three
capability getters. PresentationSprite replaces inline structural checks with
the same shared validation. GameObject, Camera, Player movement/render/erase,
Game loop, GameLoader, input, transport, saves and all existing tests are unchanged.
The support addition is one authored-data fixture; no new runtime smoke tool,
assets or project-specific behavior is needed.

The new design is documented in [graphical sprites](graphical-sprites.md) and
linked from README and S4 presentation documentation. The separate
`feature/scenario-music` branch remains preserved; no release branch is created.

Last Legend is untouched, including the pre-existing user `config.php` edit:
SHA-256 `77a0b26533c8246062182748e46bed4bd651ae24bc1b43ebbebbeb1e6b8177f4`.
Renderer source and repository state are untouched at the reference above.

S6 considerations, not implementations: load the same immutable project sprite
configuration for new and restored Players; collect only opted-in providers;
ensure IDs are unique in a frame (the field Player is `player`); retain PHP Camera
ownership; explicitly decide glyph masking/composition and off-grid collection;
share a session's RendererClient across input and presentation. No automatic
graphical runtime behavior is enabled by S5.
