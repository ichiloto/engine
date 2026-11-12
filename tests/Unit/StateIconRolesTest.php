<?php

use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;

it('lists the active and pending icon roles of every project state for theme authoring', function () {
  $states = [new State('synthetic-burn', 'Burn', ''), new State('synthetic-haste', 'Haste', '')];

  expect(BattlerConditions::listStateIconRoles($states))->toBe([
    'status.state.synthetic-burn', 'status.state.synthetic-burn.pending',
    'status.state.synthetic-haste', 'status.state.synthetic-haste.pending',
  ])
    ->and(BattlerConditions::listStateIconRoles(['synthetic-burn', 'synthetic-burn']))
    ->toBe(['status.state.synthetic-burn', 'status.state.synthetic-burn.pending'])
    ->and(BattlerConditions::listStateIconRoles([]))->toBe([]);
  expect(fn() => BattlerConditions::listStateIconRoles([' ']))
    ->toThrow(InvalidArgumentException::class, 'requires a state id');
});

it('draws condition badges from the same roles it lists, active and pending', function () {
  $bearer = new Character('Bearer', 1, new Stats(currentHp: 100, totalHp: 100));
  $active = new State('synthetic-burn', 'Burn', '', label: 'BRN');
  $pending = new State('synthetic-weaken', 'Weaken', '', label: 'WK');
  $bearer->addState($active, guaranteed: true);
  $bearer->addState($pending, givenByBearer: true, guaranteed: true, delayTurns: 1);

  $keys = array_column(BattlerConditions::getEntries($bearer), 'iconKey');
  $roles = BattlerConditions::listStateIconRoles([$active, $pending]);

  expect($keys)->toBe([BattlerConditions::getStateIconRole('synthetic-burn'),
    BattlerConditions::getStateIconRole('synthetic-weaken', pending: true)])
    ->and(array_diff($keys, $roles))->toBe([]);
});
