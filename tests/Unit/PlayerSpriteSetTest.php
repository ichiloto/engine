<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\PlayerSpriteSet;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

/**
 * A minimal ProjectConfig stand-in exposing the composite emoji flag.
 */
class SpriteConfigStub implements ConfigInterface
{
  public function __construct(private bool $allowCompositeEmoji)
  {
  }

  public function get(string $path, mixed $default = null): mixed
  {
    if ($path === PlayerSpriteSet::CONFIG_ALLOW_COMPOSITE_EMOJI) {
      return $this->allowCompositeEmoji;
    }

    return $default;
  }

  public function set(string $path, mixed $value): void
  {
  }

  public function has(string $path): bool
  {
    return $path === PlayerSpriteSet::CONFIG_ALLOW_COMPOSITE_EMOJI;
  }

  public function persist(): void
  {
  }
}

afterEach(function () {
  ConfigStore::put(ProjectConfig::class, new SpriteConfigStub(false));
});

it('keeps plain ascii sprites untouched', function () {
  expect(PlayerSpriteSet::normalizeSprite('^'))->toBe(['^'])
    ->and(PlayerSpriteSet::normalizeSprite(['<', '>']))->toBe(['<', '>']);
});

it('keeps single-code-point emoji sprites untouched', function () {
  expect(PlayerSpriteSet::normalizeSprite('🚶'))->toBe(['🚶'])
    ->and(PlayerSpriteSet::normalizeSprite('🧍'))->toBe(['🧍']);
});

it('keeps base plus variation selector sprites untouched', function () {
  expect(PlayerSpriteSet::normalizeSprite('🗡️'))->toBe(['🗡️']);
});

it('strips skin-tone modifiers from sprites', function () {
  expect(PlayerSpriteSet::normalizeSprite('🏃🏽'))->toBe(['🏃']);
});

it('reduces zwj sequences to their base glyph', function () {
  expect(PlayerSpriteSet::normalizeSprite('🏃🏽‍➡️'))->toBe(['🏃']);
});

it('resolves headings for sanitized sprites', function () {
  $spriteSet = PlayerSpriteSet::fromArray([
    'sprites' => [
      'north' => '🧍',
      'south' => '🚶',
      'east' => '🏃🏽‍➡️',
      'west' => '🏃🏽',
    ],
  ]);

  // On a terminal that cannot compose ZWJ, the composite east sprite is
  // derived into "base glyph + direction marker", so the plain runner is
  // west's sprite alone and the two directions stay distinct instead of
  // both sanitizing down to the same glyph.
  expect($spriteSet->resolveHeading('🏃🏽'))->toBe(MovementHeading::WEST)
    ->and($spriteSet->resolveHeading('🏃>'))->toBe(MovementHeading::EAST)
    ->and($spriteSet->resolveHeading('🚶'))->toBe(MovementHeading::SOUTH);
});

it('preserves composite emoji sprites when the project opts in', function () {
  ConfigStore::put(ProjectConfig::class, new SpriteConfigStub(true));

  expect(PlayerSpriteSet::normalizeSprite('🏃🏽‍➡️'))->toBe(['🏃🏽‍➡️'])
    ->and(PlayerSpriteSet::normalizeSprite('🏃🏽'))->toBe(['🏃🏽']);
});

it('keeps east and west distinguishable with the composite emoji opt-in', function () {
  ConfigStore::put(ProjectConfig::class, new SpriteConfigStub(true));

  $spriteSet = PlayerSpriteSet::fromArray([
    'sprites' => [
      'north' => '🧍',
      'south' => '🚶',
      'east' => '🏃🏽‍➡️',
      'west' => '🏃🏽',
    ],
  ]);

  expect($spriteSet->resolveHeading('🏃🏽‍➡️'))->toBe(MovementHeading::EAST)
    ->and($spriteSet->resolveHeading('🏃🏽'))->toBe(MovementHeading::WEST)
    ->and($spriteSet->getSpriteForHeading(MovementHeading::EAST))->toBe(['🏃🏽‍➡️'])
    ->and($spriteSet->getSpriteForHeading(MovementHeading::WEST))->toBe(['🏃🏽']);
});

it('sanitizes composite emoji without the opt-in', function () {
  ConfigStore::put(ProjectConfig::class, new SpriteConfigStub(false));

  expect(PlayerSpriteSet::normalizeSprite('🏃🏽‍➡️'))->toBe(['🏃']);
});

/* Spawn data that names a heading instead of spelling out the art */

it('resolves a named heading to the configured sprite', function () {
  ConfigStore::put(ProjectConfig::class, new SpriteConfigStub(false));

  $set = PlayerSpriteSet::fromArray(['sprites' => [
    'north' => '▲',
    'east' => '▶',
    'south' => '▼',
    'west' => '◀',
  ]]);

  expect($set->resolveSprite(['South']))->toBe(['▼'])
    ->and($set->resolveSprite('north'))->toBe(['▲'])
    ->and($set->resolveSprite(['EAST']))->toBe(['▶'])
    // Art still works exactly as before.
    ->and($set->resolveSprite(['◀']))->toBe(['◀']);
});

it('reads a heading out of spawn data that names one', function () {
  expect(PlayerSpriteSet::headingFromName(['South']))->toBe(MovementHeading::SOUTH)
    ->and(PlayerSpriteSet::headingFromName('West'))->toBe(MovementHeading::WEST)
    ->and(PlayerSpriteSet::headingFromName([MovementHeading::NORTH->value]))->toBe(MovementHeading::NORTH);
});

it('treats sprite art as art, never as a heading name', function () {
  expect(PlayerSpriteSet::headingFromName(['▼']))->toBeNull()
    ->and(PlayerSpriteSet::headingFromName('🚶'))->toBeNull()
    ->and(PlayerSpriteSet::headingFromName(['Northward']))->toBeNull()
    ->and(PlayerSpriteSet::headingFromName(['None']))->toBeNull()
    // Multi-row art is a sprite, whatever the rows happen to say.
    ->and(PlayerSpriteSet::headingFromName(['South', 'North']))->toBeNull();
});

it('resolves a heading from spawn data that names it', function () {
  ConfigStore::put(ProjectConfig::class, new SpriteConfigStub(false));

  $set = PlayerSpriteSet::fromArray(['sprites' => [
    'north' => '▲',
    'east' => '▶',
    'south' => '▼',
    'west' => '◀',
  ]]);

  expect($set->resolveHeading(['East']))->toBe(MovementHeading::EAST)
    ->and($set->resolveHeading(['▲']))->toBe(MovementHeading::NORTH);
});
