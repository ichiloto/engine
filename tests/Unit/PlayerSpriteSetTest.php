<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\PlayerSpriteSet;

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
