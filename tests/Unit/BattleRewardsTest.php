<?php

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Battle\Presentation\BattleProgression;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Progression\ExperienceAwarder;

it('detaches battle progression from live gameplay objects while preserving the legacy outcome API', function () {
  $character = new Character('Snapshot', 0, new Stats(currentHp: 100));
  $award = ExperienceAwarder::award($character, 10);
  $rewards = new BattleRewards(10, 3, [$award]);
  $result = new BattleResult('Victory', rewards: $rewards);
  $character->addExperience(10000);
  expect($result->outcome())->toBe('victory')
    ->and($rewards->progression[0])->toBeInstanceOf(BattleProgression::class)
    ->and(property_exists($rewards->progression[0], 'character'))->toBeFalse()
    ->and($rewards->progression[0]->before->experience)->toBe(0)
    ->and($rewards->progression[0]->after->experience)->toBe(10);
});

it('coalesces rewards by stable definition rather than display name without retaining mutable items', function () {
  $first = new Item('Same Name', 'One', '', 0, quantity: 2, id: 'one');
  $second = new Item('Same Name', 'Two', '', 0, quantity: 3, id: 'two');
  $rows = BattleRewards::snapshotItems([$first, clone $first, $second]);
  $first->quantity = 99;
  expect($rows)->toHaveCount(2)
    ->and(array_column($rows, 'quantity', 'id'))->toBe(['one' => 4, 'two' => 3]);
});
