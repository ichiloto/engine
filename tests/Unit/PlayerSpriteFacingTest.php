<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PlayerSpriteSet;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * The four directional sprites a project might author, including the
 * composite (ZWJ) east-facing runner that only exists as a sequence.
 */
function directionalSprites(): array
{
  return [
    'north' => ['🧍'],
    'east' => ['🏃‍➡️'],
    'south' => ['🚶'],
    'west' => ['🏃'],
  ];
}

it('keeps all four directional sprites distinct', function () {
  $sprites = directionalSprites();
  $rendered = array_map(static fn(array $sprite): string => $sprite[0], $sprites);

  expect(count(array_unique($rendered)))->toBe(4);
});

it('resolves each unambiguous heading from its own sprite', function () {
  $set = PlayerSpriteSet::fromArray(['sprites' => array_map(
    static fn(array $sprite): string => $sprite[0],
    directionalSprites()
  )]);

  expect($set->resolveHeading('🧍'))->toBe(MovementHeading::NORTH)
    ->and($set->resolveHeading('🚶'))->toBe(MovementHeading::SOUTH)
    // A sprite outside the set resolves to no direction, which callers
    // translate into the default facing rather than drawing it verbatim.
    ->and($set->resolveHeading('^'))->toBe(MovementHeading::NONE);
});

it('keeps composite sprites distinct on terminals that cannot compose them', function () {
  // The east runner is a ZWJ sequence. Rather than reducing it to the same
  // base glyph as west, the engine derives "base glyph + direction marker",
  // so the directions never collapse.
  $set = PlayerSpriteSet::fromArray(['sprites' => array_map(
    static fn(array $sprite): string => $sprite[0],
    directionalSprites()
  )]);

  expect($set->warnAboutCollapsedDirections())->toBeFalse()
    ->and($set->getSpriteForHeading(MovementHeading::EAST))->toBe(['🏃>'])
    ->and($set->getSpriteForHeading(MovementHeading::WEST))->toBe(['🏃']);
});

it('still reports genuinely duplicated sprites', function () {
  // Two directions authored with the same glyph is an authoring mistake no
  // derivation can resolve, so it must still be reported.
  $set = PlayerSpriteSet::fromArray(['sprites' => [
    'north' => '🚶', 'east' => '>', 'south' => '🚶', 'west' => '<',
  ]]);

  expect($set->warnAboutCollapsedDirections())->toBeTrue();

  $distinct = PlayerSpriteSet::fromArray(['sprites' => [
    'north' => '^', 'east' => '>', 'south' => 'v', 'west' => '<',
  ]]);

  expect($distinct->warnAboutCollapsedDirections())->toBeFalse()
    ->and($distinct->resolveHeading('>'))->toBe(MovementHeading::EAST)
    ->and($distinct->resolveHeading('<'))->toBe(MovementHeading::WEST);
});

it('never collapses a composite sprite into another direction when stabilizing', function () {
  // Without the composite-emoji opt-in the engine reduces ZWJ sequences to
  // their base glyph for terminals that cannot compose them. That is correct
  // for width stability, but it must never make two directions identical
  // while a project has opted in.
  $east = '🏃‍➡️';
  $west = '🏃';

  expect(TerminalText::stabilize($west))->toBe($west)
    ->and(TerminalText::displayWidth(TerminalText::stabilize($east)))->toBe(2);
});

it('measures every directional sprite as a stable two columns', function () {
  foreach (directionalSprites() as $direction => $sprite) {
    expect(TerminalText::displayWidth(TerminalText::stabilize($sprite[0])))
      ->toBe(2, "sprite for $direction should occupy two columns");
  }
});

/* Spawn data that names a heading */

/**
 * Builds a player carrying the given directional art, without booting a game.
 *
 * @param array<string, string[]> $sprites The directional sprite rows.
 * @return Player The player under test.
 */
function makePlayerWithSprites(array $sprites): Player
{
  $player = (new ReflectionClass(Player::class))->newInstanceWithoutConstructor();

  foreach ([
    'upSprite' => $sprites['north'],
    'rightSprite' => $sprites['east'],
    'downSprite' => $sprites['south'],
    'leftSprite' => $sprites['west'],
    'sprite' => $sprites['south'],
    'heading' => MovementHeading::SOUTH,
  ] as $property => $value) {
    (new ReflectionProperty(Player::class, $property))->setValue($player, $value);
  }

  return $player;
}

it('spawns facing the heading its map names', function () {
  $player = makePlayerWithSprites([
    'north' => ['▲'],
    'east' => ['▶'],
    'south' => ['▼'],
    'west' => ['◀'],
  ]);

  $player->setFacingSprite([MovementHeading::NORTH->value]);

  expect($player->heading)->toBe(MovementHeading::NORTH)
    ->and($player->sprite)->toBe(['▲']);

  $player->setFacingSprite(['west']);

  expect($player->heading)->toBe(MovementHeading::WEST)
    ->and($player->sprite)->toBe(['◀']);
});

it('still accepts spawn data that spells out the art', function () {
  $player = makePlayerWithSprites([
    'north' => ['▲'],
    'east' => ['▶'],
    'south' => ['▼'],
    'west' => ['◀'],
  ]);

  $player->setFacingSprite(['▶']);

  expect($player->heading)->toBe(MovementHeading::EAST)
    ->and($player->sprite)->toBe(['▶']);
});
