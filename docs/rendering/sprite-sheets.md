# Field character sheets

Field characters (the player, NPCs and cinematic actors) use RPG Maker's
character sheet layout. A character stands on exactly one field cell, as it
occupies one terminal cell. The graphical field draws each terminal cell as
one RPG Maker tile, a `FieldViewport::TILE_SIZE` (48 x 48) square, and a
character is one 48 x 48 frame, RPG Maker's size, bottom-centred on its cell,
so it fills its cell exactly. PHP owns direction, walking
pattern and frame selection; the renderer draws the selected crop. See the
[graphical field plan](../graphical-field.md).

## Project metadata

Keep the terminal `sprites` entry. A fixed player or an independently authored
NPC uses `sprites2d` naming a character sheet:

```php
// A standard sheet holds 8 characters; index selects one (0 to 7).
'sprites2d' => ['sheet' => 'Graphics/Characters/People.png', 'index' => 2, 'layer' => 100],

// A sheet whose file name begins with `$` holds one character.
'sprites2d' => ['sheet' => 'Graphics/Characters/$Kaelion.png', 'layer' => 100],

// A sheet whose file name begins with `!` holds objects, drawn unlifted.
'sprites2d' => ['sheet' => 'Graphics/Characters/!$Chest.png', 'layer' => 100],
```

`sheet` is required; `index` defaults to 0 and `layer` to 0. Layers must lie in
the world range 0 to 999. Nothing else is accepted: size, anchor, frame size,
frame counts and timing are not authored, because the sheet and the cell
already define them.

### Player identity and actor roles

`Data/Entities/player.php` chooses the graphical subject explicitly:

```php
'graphicalSubject' => 'party-leader',
```

In this mode, the live party leader's stable actor ID resolves its current
`Data/Actors/<id>.php` definition. That actor owns the shared field role:

```php
'images' => [
    'field2d' => ['sheet' => 'Graphics/Characters/$Leader.png', 'layer' => 100],
    // Other existing artwork roles remain independent.
],
```

Changing party order changes the sheet, not the field player's identity,
position, heading, walking phase, camera or pending arrival. Saved gameplay
references the actor; current project definitions supply artwork after loading.
The actor role uses the same sheet/index/world-layer validation as fixed sheets.
It does not infer actor identity from file names, duplicate image dimensions or
fall back to a different actor when a role is missing or invalid. Such failures
are diagnosed while retaining the terminal presentation.

`'graphicalSubject' => 'fixed-player'` uses the player's existing `sprites2d`.
Omitting the selector preserves that compatibility behavior. An invalid selector
is diagnosed and refuses optional graphical artwork rather than guessing an
identity. The selector does not change terminal `sprites` or collision.

A cinematic staged subject without explicit artwork inherits the real subject's
current sheet. A staged player therefore follows the selected leader; explicitly
named actors and explicitly authored staged artwork retain their own identity.
Ending the stage releases its presentation lease without replacing the player.

## Sheet layout

Each character is 3 walking frames by 4 direction rows, in RPG Maker's order:
down, left, right, up. A standard sheet arranges 4 x 2 characters, so it is
12 frames wide and 8 tall; a `$` sheet is 3 wide and 4 tall. The frame size is
read from the image: a standard sheet of 576 x 384 pixels has 48 x 48 frames.
Frames of any size are scaled to fill the character's one cell.

As in RPG Maker, the marks are the file name's leading run of `$` and `!`, in
either order (`!$Chest.png` and `$!Chest.png` are the same kind of sheet); a
mark later in the name, or in a folder name, is not a mark.

## Lift

RPG Maker draws a character's frame 6 pixels above its tile
(Sprite_Character's shiftY), so artists draw the feet on the frame's bottom
edge and the character stands within its tile. `CharacterSheet::LIFT` is that
6, in the sprite's field pixels (one cell is 48). Every frame of a sheet
carries its `lift`: `LIFT`, or 0 for an object sheet (`!`), which sits on
its tile. The lift comes from the sheet's name alone, never from the image's
size or contents, so replacing the art never changes it.

The lift is presentation only. The character keeps its cell, so collision,
events, saves, draw order and the terminal glyph are unchanged, and the
renderer raises the drawn frame while it stands, slides or is followed by the
camera. Single-image field art (below) is drawn as authored, without a lift.

A sheet that is missing, corrupt, not a PNG or not divisible into its layout
keeps the character's terminal glyph and is reported once per distinct
failure. It never removes the character or stops the map.

## Walking

`CharacterWalkAnimation` follows RPG Maker: the middle frame (1) is standing,
and walking cycles 1, 2, 1, 0 by distance travelled, one pattern per 30 field
pixels (RPG Maker's 10 frames at 3 pixels per frame). Every step covers one
48-pixel cell, so a step carries the cycle 1.6 patterns on and both axes
animate at one pace. A walk begins on its first stride, so even one step shows
one. Travel advances over the step's own duration; steps that follow
each other keep the cycle going without a standing frame between them. Once
travel stops for 15 frames (RPG Maker's animation tick) the character stands
again. Rejected movement, explicit facing, interaction and suspension stop
walking at once.

The player walks at RPG Maker's default speed through the shared
[field metric](input-sources.md#field-walking-implemented): 16/60 s for a
step across or down. An NPC or staged actor on a
movement route steps at the route's own `secondsPerStep`; a wandering NPC's
step walks at field speed, whatever its pause between steps.

With `field_motion`, each committed step carries its duration to the
renderer, which slides the sprite from the cell it left over that time on its
own clock, and a camera following the player slides with it. The cell is
already committed: collision, triggers and saves never see a partial cell,
and the terminal ignores all of this. A placement that is not one step (a
transfer, a restored cinematic transform) snaps.

Under reduced motion every field character (player, NPCs and staged actors)
shows the standing frame and positions snap without sliding. Animation state
is presentation only and is never saved.

## Depth and prompts

Within a draw layer, characters are ordered by row: a character lower on the
field draws in front of one above it, with stable ties. The order is by cell,
so a lift never changes it. Because a character is exactly one cell, the field
action prompt sits in the cell directly above it: the game's bound prompt
effect where it has one, otherwise the prompt glyph (see
[Field effects](../effect-animation.md#field-effects)).

## Single-image field art

A fixed pose (for example a cinematic embrace) uses `GraphicalSpriteDefinition`
with `asset`, an optional `sourceRect` crop, an optional `layer`, and an
optional size in whole character frames, `cells` (default one frame, 48 x 48).
It is bottom-centred on its position's cell. Authored pixel sizes and anchors are rejected:

```php
'sprites2d' => ['asset' => 'Graphics/Poses/Embrace.png', 'cells' => ['width' => 2, 'height' => 1],
    'sourceRect' => ['x' => 0, 'y' => 0, 'width' => 512, 'height' => 256], 'layer' => 100],
```

## Renderer contract

The retained world carries `cellWidth` and `cellHeight`, and the renderer
draws the field at that pitch: world cells, field text and the sprites the
viewport names. Sprite positions are camera-screen cells; a character is sent
at 48 x 48 and anchored at the bottom centre of its cell. Crops use the
negotiated `sprite_source_rect` capability. A step's slide is an optional
`motion: {"duration": seconds}` on the sprite and the followed sprite an
optional viewport `follow`, both only for renderers advertising `field_motion`.
A lifted sprite carries an optional `lift` in logical pixels, 1 up to its
height, only for renderers advertising `sprite_lift`; without it, the sprite's
value is exactly the unlifted one.
See the renderer's documentation for the wire format.
