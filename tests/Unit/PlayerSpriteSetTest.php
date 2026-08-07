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

  // Both the stored set and the probe sprite are sanitized, so a raw
  // composite sprite still resolves to its heading.
  expect($spriteSet->resolveHeading('🏃🏽'))->toBe(MovementHeading::EAST)
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
