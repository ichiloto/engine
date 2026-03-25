<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Field\Player;

it('applies a width-aware horizontal render offset for wide player sprites', function () {
  $player = makePlayerForRenderOffsetTest(
    ['😀'],
    new Rect(0, 0, 1, 1),
    MovementHeading::EAST
  );

  expect(invokePlayerHorizontalRenderOffset($player, ['😀']))->toBe(1)
    ->and(invokePlayerHorizontalRenderOffset(setPlayerHeading($player, MovementHeading::NORTH), ['😀']))->toBe(1)
    ->and(invokePlayerHorizontalRenderOffset(setPlayerHeading($player, MovementHeading::WEST), ['😀']))->toBe(1);
});

it('keeps single-width player sprites anchored to their logical tile', function () {
  $player = makePlayerForRenderOffsetTest(
    ['@'],
    new Rect(0, 0, 1, 1),
    MovementHeading::EAST
  );

  expect(invokePlayerHorizontalRenderOffset($player, ['@']))->toBe(0);
});

/**
 * Creates a lightweight player instance for render-offset tests.
 *
 * @param string[] $sprite The active sprite rows.
 * @param Rect $shape The player collision shape.
 * @param MovementHeading $heading The current heading.
 * @return Player
 */
function makePlayerForRenderOffsetTest(array $sprite, Rect $shape, MovementHeading $heading): Player
{
  $player = (new ReflectionClass(Player::class))->newInstanceWithoutConstructor();

  setPlayerProperty($player, 'sprite', $sprite);
  setPlayerProperty($player, 'shape', $shape);
  setPlayerProperty($player, 'heading', $heading);

  return $player;
}

/**
 * Updates the player's heading for a render-offset assertion.
 *
 * @param Player $player The player under test.
 * @param MovementHeading $heading The heading to assign.
 * @return Player
 */
function setPlayerHeading(Player $player, MovementHeading $heading): Player
{
  setPlayerProperty($player, 'heading', $heading);

  return $player;
}

/**
 * Invokes the protected player render-offset calculator.
 *
 * @param Player $player The player under test.
 * @param string[] $sprite The sprite rows to inspect.
 * @return int
 */
function invokePlayerHorizontalRenderOffset(Player $player, array $sprite): int
{
  $method = new ReflectionMethod(Player::class, 'getHorizontalRenderOffset');

  return $method->invoke($player, $sprite);
}

/**
 * Writes a protected player property used by the test.
 *
 * @param Player $player The player under test.
 * @param string $propertyName The property name to write.
 * @param mixed $value The value to assign.
 * @return void
 */
function setPlayerProperty(Player $player, string $propertyName, mixed $value): void
{
  $property = new ReflectionProperty(Player::class, $propertyName);
  $property->setValue($player, $value);
}
