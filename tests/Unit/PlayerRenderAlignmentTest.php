<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\TerminalText;

/*
|--------------------------------------------------------------------------
| Sprite anchoring
|--------------------------------------------------------------------------
|
| The engine-wide contract: a sprite is anchored to its own tile. Its first
| column is that tile's column, and a glyph wider than one cell (every emoji
| is two) overhangs to the right. Anything that erases a sprite must clear
| that full width.
|
| Shifting wide sprites left to "centre" them broke the contract: a player
| standing on the first walkable tile beside a wall was drawn on top of the
| wall.
|
*/

it('anchors every sprite to its own tile regardless of width', function () {
  foreach ([['@'], ['😀'], ['🏃'], ['🧍']] as $sprite) {
    $player = makePlayerForRenderTest($sprite, new Rect(0, 0, 1, 1), MovementHeading::EAST);
    $erased = eraseFootprintTiles($player, new Vector2(7, 3), $sprite);

    // The leftmost tile touched is the player's own tile: standing beside a
    // wall must never paint over it.
    expect(min(array_column($erased, 0)))
      ->toBe(7, sprintf('sprite %s should start at its own tile column', $sprite[0]));
  }
});

it('erases the full width a wide sprite covers', function () {
  $player = makePlayerForRenderTest(['😀'], new Rect(0, 0, 1, 1), MovementHeading::EAST);

  // A two-column glyph anchored at tile 7 covers columns 7 and 8, so both
  // have to be restored or half the sprite stays on the map.
  expect(spriteFootprintWidth($player, ['😀']))->toBe(2)
    ->and(spriteFootprintWidth($player, ['@']))->toBe(1);
});

it('measures the same width the console will advance', function () {
  // The erase width and the terminal's cursor advance must agree, otherwise
  // erasing leaves a gap or clobbers a neighbouring tile.
  foreach (['@', '😀', '🏃', '🧍', '🚶'] as $glyph) {
    $player = makePlayerForRenderTest([$glyph], new Rect(0, 0, 1, 1), MovementHeading::EAST);

    expect(spriteFootprintWidth($player, [$glyph]))
      ->toBe(max(1, TerminalText::displayWidth($glyph)), "width mismatch for $glyph");
  }
});

/**
 * Creates a lightweight player instance for render alignment tests.
 *
 * @param string[] $sprite The active sprite rows.
 * @param Rect $shape The player collision shape.
 * @param MovementHeading $heading The current heading.
 * @return Player
 */
function makePlayerForRenderTest(array $sprite, Rect $shape, MovementHeading $heading): Player
{
  $player = (new ReflectionClass(Player::class))->newInstanceWithoutConstructor();

  setPlayerProperty($player, 'sprite', $sprite);
  setPlayerProperty($player, 'shape', $shape);
  setPlayerProperty($player, 'heading', $heading);

  return $player;
}

/**
 * Runs the real erase footprint against a recording scene and returns the
 * [x, y] tiles it restored.
 *
 * @param Player $player The player under test.
 * @param Vector2 $position The world position being erased.
 * @param string[] $sprite The sprite rows.
 * @return array<int, array{0: int, 1: int}> The erased tiles.
 */
function eraseFootprintTiles(Player $player, Vector2 $position, array $sprite): array
{
  $scene = new RecordingScene();

  setPlayerProperty($player, 'scene', $scene);
  (new ReflectionMethod(Player::class, 'eraseSpriteFootprint'))->invoke($player, $position, $sprite);

  return $scene->erased;
}

/**
 * Returns the width of the sprite's erase footprint.
 *
 * @param Player $player The player under test.
 * @param string[] $sprite The sprite rows.
 * @return int The footprint width in columns.
 */
function spriteFootprintWidth(Player $player, array $sprite): int
{
  $method = new ReflectionMethod(Player::class, 'getSpriteDisplayWidth');
  $shape = (new ReflectionProperty(Player::class, 'shape'))->getValue($player);

  return max($shape->getWidth(), $method->invoke($player, $sprite));
}

/**
 * Sets a protected player property.
 *
 * @param Player $player The player under test.
 * @param string $property The property name.
 * @param mixed $value The value to assign.
 * @return void
 */
function setPlayerProperty(Player $player, string $property, mixed $value): void
{
  (new ReflectionProperty(Player::class, $property))->setValue($player, $value);
}

/**
 * A scene that records the tiles it was asked to restore, so the real erase
 * footprint can be asserted without standing up a game.
 */
class RecordingScene implements SceneInterface
{
  /**
   * @var array<int, array{0: int, 1: int}> The tiles restored so far.
   */
  public array $erased = [];
  public Ichiloto\Engine\Rendering\Camera $camera;
  public string $name = 'recording';

  public function renderBackgroundTile(int $x, int $y): void
  {
    $this->erased[] = [$x, $y];
  }

  public function getGame(): Ichiloto\Engine\Core\Game
  {
    throw new RuntimeException('Not needed for erase-footprint assertions.');
  }

  public function getBackgroundMusic(): ?string { return null; }
  public function getRootGameObjects(): array { return []; }

  public function getUI(): Ichiloto\Engine\UI\UIManager
  {
    throw new RuntimeException('Not needed for erase-footprint assertions.');
  }

  public function isStarted(): bool { return true; }
  public function start(): void {}
  public function stop(): void {}
  public function resume(): void {}
  public function suspend(): void {}
  public function update(): void {}
  public function render(): void {}
  public function erase(): void {}
}
