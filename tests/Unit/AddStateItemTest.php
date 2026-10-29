<?php

use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\AddStateEffect;
use Ichiloto\Engine\Entities\Effects\EffectFactory;
use Ichiloto\Engine\Entities\Effects\RemoveStateEffect;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\ItemRecord;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Entities\Stats;

final class StateItemRandom implements CombatRandomSource
{
  public int $calls = 0;
  public function __construct(private int $roll) {}
  public function nextInt(int $minimum, int $maximum): int { $this->calls++; return $this->roll; }
}

beforeEach(function () {
  $this->registry = new ReflectionProperty(StateRegistry::class, 'states');
  $this->previousStates = $this->registry->getValue();
  $this->registry->setValue(null, ['focus' => new State('focus', 'Focus', durationTurns: 1, persistsAfterBattle: true),
    'ward' => new State('ward', 'Ward', durationTurns: 2)]);
});
afterEach(function () { $this->registry->setValue(null, $this->previousStates); });

function makeStateItem(AddStateEffect $effect, int $quantity = 3): Item
{
  return new Item('Synthetic tonic', '', '', 0, quantity: $quantity, effects: [$effect], id: 'item.synthetic-state',
    scope: new \Ichiloto\Engine\Entities\Inventory\Items\ItemScope(\Ichiloto\Engine\Entities\Enumerations\ItemScopeSide::ALLY));
}
function makeStateItemTarget(string $name = 'Bearer', int $hp = 100): Character
{
  return new Character($name, 0, new Stats(currentHp: $hp, totalHp: 100, currentMp: 0));
}

it('round trips state item records and legacy object serialization without state-specific constructor metadata', function () {
  $effect = new AddStateEffect('Grant focus', '', 'focus', guaranteed: true);
  $data = ItemRecord::writeItem(makeStateItem($effect));
  expect($data['effects'][0])->toBe(['type' => 'add_state', 'name' => 'Grant focus', 'description' => '', 'stateId' => 'focus', 'guaranteed' => true]);
  $read = ItemRecord::readItem($data);
  expect(ItemRecord::writeItem($read))->toBe($data)
    ->and($read->effects[0]->stateId)->toBe('focus')
    ->and(EffectFactory::create(AddStateEffect::class, $effect->jsonSerialize())->jsonSerialize())->toBe($effect->jsonSerialize());
});

it('refuses invalid state item parameters before an inventory definition is admitted', function (array $values) {
  $data = ItemRecord::writeItem(makeStateItem(new AddStateEffect('Grant focus', '', 'focus')));
  $data['effects'][0] = array_replace($data['effects'][0], $values);
  expect(fn() => ItemRecord::readItem($data))->toThrow(InvalidArgumentException::class);
})->with(array_map(static fn(array $value): array => [$value], [
  ['stateId' => ''], ['chancePercent' => -1], ['chancePercent' => 101], ['chancePercent' => '50'],
  ['chancePercent' => 75, 'guaranteed' => true], ['guaranteed' => 'yes'], ['value' => 1],
]));

it('uses the injected battle roll and state resistance, records each outcome and consumes only one item for all targets', function () {
  $actor = makeStateItemTarget(); $ally = makeStateItemTarget('Ally'); $immune = makeStateItemTarget('Immune');
  $immune->setStateResistances(['focus' => 0]);
  $random = new StateItemRandom(51);
  $item = makeStateItem(new AddStateEffect('Grant focus', '', 'focus', 50));
  $inventory = new Inventory(); $inventory->addItems($item);
  $action = new ItemBattleAction($item, $inventory, $random);
  $action->execute($actor, [$actor, $ally, $immune]);
  expect($actor->hasState('focus'))->toBeFalse()->and($item->quantity)->toBe(2)
    ->and(array_column(array_merge(...array_column($action->lastResult->targets, 'secondaryOutcomes')), 'applied'))->toBe([false, false, false])
    ->and($random->calls)->toBe(2);
  $ally->setStateResistances(['focus' => 2]);
  $action->execute($actor, [$ally]);
  expect($ally->hasState('focus'))->toBeTrue()->and($item->quantity)->toBe(1)
    ->and($action->lastResult->targets[0]->secondaryOutcomes)->toBe([['type' => 'state', 'id' => 'focus', 'applied' => true]])
    ->and($actor->stats->currentMp)->toBe(0);
});

it('keeps a self-granted duration for the next turn and ticks other recipients on their next completed turn', function () {
  $actor = makeStateItemTarget(); $other = makeStateItemTarget('Other');
  $item = makeStateItem(new AddStateEffect('Grant focus', '', 'focus', guaranteed: true));
  $random = new StateItemRandom(100);
  $actor->setStateResistances(['focus' => 0]);
  new ItemBattleAction($item, random: $random)->execute($actor, [$actor, $other]);
  expect($random->calls)->toBe(0)->and($actor->states[0]->awaitsNextTurn)->toBeTrue()
    ->and($other->states[0]->awaitsNextTurn)->toBeFalse();
  new Turn($actor)->resolveEndStateTicks();
  expect($actor->hasState('focus'))->toBeTrue();
  new Turn($other)->resolveEndStateTicks();
  expect($other->hasState('focus'))->toBeFalse();
  new Turn($actor)->resolveEndStateTicks();
  expect($actor->hasState('focus'))->toBeFalse();
});

it('never adds a state to the fallen or resets an existing duration and clears the previous per-target result', function () {
  $actor = makeStateItemTarget(); $fallen = makeStateItemTarget('Fallen', 0);
  $effect = new AddStateEffect('Grant focus', '', 'focus', guaranteed: true);
  $action = new ItemBattleAction(makeStateItem($effect));
  $action->execute($actor, [$actor, $actor, $fallen]);
  expect(array_column(array_merge(...array_column($action->lastResult->targets, 'secondaryOutcomes')), 'applied'))->toBe([true, false, false])
    ->and($actor->states)->toHaveCount(1)->and($fallen->states)->toBeEmpty()
    ->and($effect->lastApplied)->toBeFalse();
});

it('previews possible field use without rolling or changing live state and then refuses duplicate use', function () {
  $target = makeStateItemTarget();
  $effect = new AddStateEffect('Grant focus', '', 'focus', 1);
  $item = makeStateItem($effect);
  for ($i = 0; $i < 20; $i++) { expect($target->getItemUseRefusal($item))->toBeNull(); }
  expect($target->states)->toBeEmpty()->and($effect->lastApplied)->toBeFalse()->and($item->quantity)->toBe(3);
  $target->setStateResistances(['focus' => 0]);
  expect($target->getItemUseRefusal($item))->not->toBeNull();
  $guaranteed = makeStateItem(new AddStateEffect('Grant focus', '', 'focus', guaranteed: true));
  expect($target->applyItem($guaranteed, 3))->toBe(1)->and($guaranteed->quantity)->toBe(2)
    ->and($target->getItemUseRefusal($guaranteed))->not->toBeNull()
    ->and($target->states[0]->awaitsNextTurn)->toBeFalse();
  $restored = unserialize(serialize($target));
  expect($restored->states[0]->state->id)->toBe('focus')->and($restored->states[0]->remainingTurns)->toBe(1);
});

it('keeps field no-effect detection sequential when a later effect removes the state just granted', function () {
  $target = makeStateItemTarget();
  $item = new Item('Canceling effects', '', '', 0, effects: [new AddStateEffect('Grant focus', '', 'focus'),
    new RemoveStateEffect('Remove focus', '', ['focus'])]);
  expect($target->getItemUseRefusal($item))->not->toBeNull()->and($target->states)->toBeEmpty();
});


it('defers state expiry when an item is applied during the recipient’s interrupted turn', function () {
  $actor = makeStateItemTarget('Responder'); $recipient = makeStateItemTarget('Turn owner');
  $reactions = new \Ichiloto\Engine\Battle\BattleReactions();
  $reactions->beginTurn($recipient);
  $action = new ItemBattleAction(makeStateItem(new AddStateEffect('Grant focus', '', 'focus', guaranteed: true)));
  $action->executeWithReactions($actor, [$recipient], $reactions);
  new Turn($recipient)->resolveEndStateTicks();
  expect($recipient->hasState('focus'))->toBeTrue();
  new Turn($recipient)->resolveEndStateTicks();
  expect($recipient->hasState('focus'))->toBeFalse();
});
