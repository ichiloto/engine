<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Stats\EntityStatCapPolicy;
use Ichiloto\Engine\Entities\Stats\PermanentGrowthLedger;
use Ichiloto\Engine\Entities\Stats\PermanentStatModifier;
use Ichiloto\Engine\Entities\Stats\StatKey;
use Ichiloto\Engine\Entities\Stats\StatResolver;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

it('stacks inventory by stable definition id rather than mutable display text', function () {
  $inventory = new Inventory();
  $inventory->addItems(
    new Item('Old Potion Name', '', '!', 10, id: 'item.potion.small'),
    new Item('Renamed Potion', '', '!', 20, id: 'item.potion.small'),
  );

  expect($inventory->all->count())->toBe(1)
    ->and($inventory->getQuantityById('item.potion.small'))->toBe(2)
    ->and($inventory->all->toArray()[0]->name)->toBe('Old Potion Name');
});

it('keeps broad armor classes out of the wrong semantic slot', function () {
  $character = new Character('Tester', 0, new Stats());
  $shield = new Armor(
    'Test Shield',
    '',
    'O',
    10,
    parameterChanges: new ParameterChanges(defence: 2),
    id: 'equipment.test-shield',
    semanticSlot: EquipmentSlotType::SHIELD,
  );

  $bodySlot = $character->equipment[3];
  $shieldSlot = $character->equipment[1];
  $bodySlot->equipment = $shield;
  $shieldSlot->equipment = $shield;

  expect($character->equipment[1]->equipment)->toBe($shield)
    ->and($character->equipment[2]->equipment)->toBeNull()
    ->and($character->equipment[3]->equipment)->toBeNull();
});

it('grants permanent growth idempotently and rejects conflicting id reuse', function () {
  $ledger = new PermanentGrowthLedger();
  $entry = new PermanentStatModifier('growth.one', StatKey::ATTACK, 3, 'reward', 'test-source');

  expect($ledger->grant($entry))->toBeTrue()
    ->and($ledger->grant($entry))->toBeFalse()
    ->and($ledger->totalFor(StatKey::ATTACK))->toBe(3)
    ->and(fn() => $ledger->grant(new PermanentStatModifier(
      'growth.one',
      StatKey::ATTACK,
      4,
      'reward',
      'test-source',
    )))->toThrow(InvalidArgumentException::class, 'conflicting content');
});

it('exposes every stat layer cap loss and headroom', function () {
  $result = StatResolver::resolve(
    StatKey::MAX_HP,
    natural: 9_000,
    actorNatural: 250,
    permanent: 500,
    equipment: 500,
    caps: EntityStatCapPolicy::player(),
  );

  expect($result->uncappedValue)->toBe(10_250)
    ->and($result->effectiveValue)->toBe(9_999)
    ->and($result->capLoss)->toBe(251)
    ->and($result->remainingHeadroom)->toBe(0)
    ->and(EntityStatCapPolicy::enemy()->capFor(StatKey::MAX_HP))->toBeGreaterThan(9_999);
});

it('preserves current resources and zero through growth class and equipment recalculation', function () {
  $character = new Character(
    'Tester',
    0,
    new Stats(currentHp: 0, currentMp: 4, totalHp: 100, totalMp: 10),
    actorNaturalAdjustments: [StatKey::MAX_HP->value => 25],
  );
  $beforeMp = $character->stats->currentMp;

  $character->grantPermanentGrowth(new PermanentStatModifier(
    'growth.hp',
    StatKey::MAX_HP,
    50,
    'reward',
    'test-source',
  ));

  expect($character->stats->currentHp)->toBe(0)
    ->and($character->stats->currentMp)->toBe($beforeMp)
    ->and($character->resolveStat(StatKey::MAX_HP)->actorNatural)->toBe(25)
    ->and($character->resolveStat(StatKey::MAX_HP)->permanent)->toBe(50);
});

it('round trips acquired growth while keeping equipment out of the ledger', function () {
  $character = new Character('Tester', 0, new Stats(currentHp: 80, totalHp: 100));
  $character->grantPermanentGrowth(new PermanentStatModifier(
    'growth.defence',
    StatKey::DEFENCE,
    2,
    'reward',
    'test-source',
  ));

  /** @var Character $restored */
  $restored = unserialize(serialize($character), ['allowed_classes' => true]);

  expect($restored->permanentGrowth->all())->toHaveCount(1)
    ->and($restored->permanentGrowth->totalFor(StatKey::DEFENCE))->toBe(2);
});

it('persists inventory by definition id and genuine mutable state only', function () {
  $previous = ConfigStore::has(ItemStore::class)
    ? ConfigStore::get(ItemStore::class)
    : null;
  $store = new class extends ItemStore {
    public function __construct()
    {
    }
  };
  $definition = new Item(
    'Current Display Name',
    'Static catalogue description must not enter a version four save.',
    '!',
    75,
    id: 'item.reference-test',
  );
  $store->set($definition->id, $definition);
  ConfigStore::put(ItemStore::class, $store);

  try {
    $owned = clone $definition;
    $owned->quantity = 3;
    $serialized = serialize($owned);
    /** @var Item $restored */
    $restored = unserialize($serialized, ['allowed_classes' => true]);

    expect($serialized)->toContain('item.reference-test')
      ->and($serialized)->not->toContain('Static catalogue description')
      ->and($restored->id)->toBe('item.reference-test')
      ->and($restored->name)->toBe('Current Display Name')
      ->and($restored->quantity)->toBe(3);
  } finally {
    ConfigStore::remove(ItemStore::class);

    if ($previous instanceof ItemStore) {
      ConfigStore::put(ItemStore::class, $previous);
    }
  }
});

it('returns safe catalogue instances rather than mutable singletons', function () {
  $store = new class extends ItemStore {
    public function __construct()
    {
    }
  };
  $definition = new Item('Potion', 'Current definition', '!', 10, id: 'item.safe-copy');
  $store->set($definition->id, $definition);

  $first = $store->get('item.safe-copy');
  $second = $store->get('Potion');
  assert($first instanceof Item && $second instanceof Item);
  $first->quantity = 9;
  $first->price = 999;

  expect($second)->not->toBe($first)
    ->and($second->quantity)->toBe(1)
    ->and($second->price)->toBe(10);
});
