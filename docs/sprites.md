# Player sprites and spawning

The player's art is declared once, in the project's
`assets/Data/Entities/player.php`, and everything else in the game refers to
it by direction rather than repeating the glyphs.

```php
<?php
// assets/Data/Entities/player.php
use Ichiloto\FinalQuest\Graphics\PlayerSprite;

return [
  'sprites' => [
    'north' => PlayerSprite::NORTH->value,
    'east' => PlayerSprite::EAST->value,
    'south' => PlayerSprite::SOUTH->value,
    'west' => PlayerSprite::WEST->value,
  ],
];
```

A sprite may be a single row or several, and a project is free to author its
art as an enum (as above), as plain strings, or anything else that evaluates
to a string.

## Spawn data names a heading

Anything that places the player, the starting position in `system.php`,
`TransferPlayerTrigger`, and `SleepEventTrigger`, takes a `spawnSprite`. Give
it a **heading name** rather than art:

```php
'spawnSprite' => [
  MovementHeading::SOUTH->value,   // 'South'
],
```

The engine resolves the name against the project's sprite set at spawn time,
so a map never names a glyph. Change the art in one file and every spawn
point in the game follows, with nothing to migrate. Names match case
insensitively, so `'south'` and `'South'` are the same thing.

This matters for round-tripping: the editor writes map data with
`var_export`, which serialises values rather than expressions. A `PlayerSprite::SOUTH->value`
in a map file is flattened back to a bare `'▼'` the first time that map is
saved from the editor, whereas a heading name flattens to `'South'`, which is
still a stable identifier and still resolves through the sprite set.

Art is still accepted. `'spawnSprite' => ['▼']` keeps working, and existing
projects need no changes; it is simply the form that has to be revisited when
the art changes.

## How a spawn resolves

1. `PlayerSpriteSet::headingFromName()` checks whether the value names a
   heading. Anything else, including every glyph, is treated as art.
2. A named heading resolves to that direction's configured sprite, and the
   player faces that way.
3. Art resolves through `PlayerSpriteSet::normalizeSprite()` as before, and
   the heading is derived from which direction owns the sprite.
4. Art belonging to no direction leaves the player facing south with the
   configured south sprite, rather than drawing an orphan glyph.

Every path that positions the player, loading a game, walking through a door,
waking up in an inn, and restoring a save, funnels through
`Player::setFacingSprite()`, so all four behave identically.

## Composite emoji

Directional emoji variants such as the right-facing runner exist only as ZWJ
sequences, and terminals disagree on whether they compose. The engine reduces
them to their base glyph unless a project opts in with
`graphics.sprites.allow_composite_emoji`, and detects at boot whether the
running terminal can compose them at all. A project that opts in may author
`fallbackSprites` for terminals that cannot; without them the engine derives
distinct fallbacks itself, so the four directions never collapse into each
other.

Single code point, single column art (the geometric shapes `▲▼◀▶`, say) sits
in exactly one cell and never overhangs a neighbouring tile, which is the
safest choice for a sprite.
