<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

beforeEach(function () {
  $this->savedConfig = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  $this->store = makeBareScene(ItemStore::class);
  $this->item = new Item('Current Tonic', 'Reward definition.', '!', 10, id: 'item.tonic', aliases: ['Old Tonic']);
  $this->store->set($this->item->id, $this->item);
  ConfigStore::put(ItemStore::class, $this->store);
});

afterEach(function () {
  foreach ($this->savedConfig as $key => $value) { new ReflectionProperty(ConfigStore::class, $key)->setValue(null, $value); }
});

it('describes stable and alias references with the same quantities as granting without changing definitions', function () {
  $references = ['item.tonic', ['item' => 'Old Tonic', 'quantity' => '3.9', 'price' => 999],
    ['item' => 'item.tonic', 'quantity' => null], ['item' => 'item.tonic', 'quantity' => 0], ['item' => 'item.tonic', 'quantity' => -2]];
  $quest = new Quest('rewards', 'Rewards', rewards: ['gold' => 20, 'experience' => 30, 'items' => $references]);
  $before = $this->store->get('item.tonic');
  expect($quest->describeRewards())->toBe('20 G, 30 EXP, Current Tonic, Current Tonic x3, Current Tonic')
    ->and(count($this->store->load($references)))->toBe(5)
    ->and($this->store->get('item.tonic'))->toEqual($before)
    ->and($quest->rewards['items'])->toBe($references);
});

it('bounds large-quantity snapshots by reward entry count without loading or instantiating items', function () {
  $store = new class extends ItemStore {
    public array $resolved = [];
    public function __construct() {}
    public function load(array $data): array { throw new LogicException('Display must not load rewards.'); }
    public function instantiate(string $itemName, int $quantity = 1, string $context = 'instantiating inventory content'): array
    {
      throw new LogicException('Display must not instantiate rewards.');
    }
    public function displayNameFor(string $reference, string $context = 'displaying inventory content'): string
    {
      $this->resolved[] = [$reference, $context];
      return parent::displayNameFor($reference, $context);
    }
  };
  $store->set($this->item->id, $this->item);
  ConfigStore::put(ItemStore::class, $store);
  $definitions = serialize(new ReflectionProperty($store, 'items')->getValue($store));
  $aliases = new ReflectionProperty($store, 'aliases')->getValue($store);
  $rewards = ['items' => [['item' => 'item.tonic', 'quantity' => PHP_INT_MAX, 'price' => 999],
    ['item' => 'Old Tonic', 'quantity' => 1000000000]]];
  $quest = new Quest('large', 'Large', rewards: $rewards);
  foreach (range(1, 3) as $snapshot) {
    expect($quest->describeRewards())->toBe('Current Tonic x' . PHP_INT_MAX . ', Current Tonic x1000000000')
      ->and(count($store->resolved))->toBe($snapshot * count($rewards['items']));
  }
  expect($store->resolved[0])->toBe(['item.tonic', 'describing rewards for quest "large"'])
    ->and($store->resolved[1][0])->toBe('Old Tonic')
    ->and(serialize(new ReflectionProperty($store, 'items')->getValue($store)))->toBe($definitions)
    ->and(new ReflectionProperty($store, 'aliases')->getValue($store))->toBe($aliases)
    ->and($quest->rewards)->toBe($rewards);
});

it('validates references before omitting zero or negative quantity rewards', function (int $quantity) {
  $valid = new Quest('zero', 'Zero', rewards: ['items' => [['item' => 'Old Tonic', 'quantity' => $quantity]]]);
  expect($valid->describeRewards())->toBe('')
    ->and($this->store->load($valid->rewards['items']))->toBe([])
    ->and(fn() => new Quest('missing', 'Missing', rewards: ['items' => [['item' => 'missing', 'quantity' => $quantity]]])->describeRewards())->toThrow(NotFoundException::class)
    ->and(fn() => new Quest('bad', 'Bad', rewards: ['items' => [['quantity' => $quantity]]])->describeRewards())->toThrow(RequiredFieldException::class);
})->with([0, -5]);

it('retains loader diagnostics rather than hiding invalid structured rewards', function () {
  expect(fn() => new Quest('bad', 'Bad', rewards: ['items' => [['quantity' => 2]]])->describeRewards())->toThrow(RequiredFieldException::class)
    ->and(fn() => new Quest('missing', 'Missing', rewards: ['items' => [['item' => 'missing']]])->describeRewards())->toThrow(NotFoundException::class);
  ConfigStore::remove(ItemStore::class);
  expect(new Quest('legacy', 'Legacy', rewards: ['items' => ['Legacy name']])->describeRewards())->toBe('Legacy name')
    ->and(fn() => new Quest('unloaded', 'Unloaded', rewards: ['items' => [['item' => 'item.tonic']]])->describeRewards())->toThrow(InvalidArgumentException::class);
});
