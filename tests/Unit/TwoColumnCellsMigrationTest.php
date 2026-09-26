<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\SaveCompatibility\TwoColumnCellsMigration;
use Ichiloto\Engine\Scenes\Game\GameConfig;

it('places a one-column save in the two-column cell that now holds it', function (int $column, int $cell) {
  $config = new GameConfig(
    mapId: 'field',
    party: new Party(),
    playerPosition: new Vector2($column, 9),
    playerShape: new Rect(0, 0, 1, 1),
    playerHeading: MovementHeading::EAST,
  );

  $migrated = new TwoColumnCellsMigration()->migrate(['slot' => 1, 'config' => $config]);

  expect($migrated['slot'])->toBe(1)
    ->and($migrated['config']->playerPosition->x)->toEqual($cell)
    ->and($migrated['config']->playerPosition->y)->toEqual(9)
    ->and($migrated['config']->mapId)->toBe('field');
})->with([[0, 0], [1, 0], [7, 3], [8, 4]]);

it('leaves a payload without a field configuration unchanged', function () {
  expect(new TwoColumnCellsMigration()->migrate(['slot' => 2, 'config' => null]))
    ->toBe(['slot' => 2, 'config' => null]);
});
