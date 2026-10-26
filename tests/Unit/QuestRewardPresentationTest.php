<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Progression\ExperienceAwardResult;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Modal\ModalManager;

class QuestCompletionPresentationProbe extends QuestManager
{
  public array $notices = [];
  public int $grants = 0;

  public function __construct(Game $game, private array $results, Quest $quest)
  {
    $this->game = $game;
    $this->gameScene = new class extends GameScene {
      public function __construct() { $this->gameState = new GameState(); }
    };
    $this->log = new QuestLog();
    $this->log->accept($quest->id, 1);
  }

  public function finishQuest(Quest $quest, bool $quiet = false): void { $this->completeQuest($quest, $quiet); }
  public function advanceObjective(Quest $quest, int $count): void { $this->applyProgress($quest, 0, $count); }
  protected function grantRewards(Quest $quest): array { $this->grants++; return $this->results; }
  protected function notifyQuest(string $title, string $text, NotificationDuration $duration, ?string $presentationRole = null): void
  {
    $this->notices[] = [$title, $text, $presentationRole];
  }
}

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

it('keeps reward granting strict while zero-quantity display is best effort', function (int $quantity) {
  $valid = new Quest('zero', 'Zero', rewards: ['items' => [['item' => 'Old Tonic', 'quantity' => $quantity]]]);
  expect($valid->describeRewards())->toBe('')
    ->and($this->store->load($valid->rewards['items']))->toBe([])
    ->and(new Quest('missing', 'Missing', rewards: ['items' => [['item' => 'missing', 'quantity' => $quantity]]])->describeRewards())->toBe('')
    ->and(new Quest('bad', 'Bad', rewards: ['items' => [['quantity' => $quantity]]])->describeRewards())->toBe('')
    ->and(fn() => $this->store->load([['item' => 'missing', 'quantity' => $quantity]]))->toThrow(NotFoundException::class);
})->with([0, -5]);

it('describes invalid rewards without crashing and keeps valid rewards visible', function () {
  expect(new Quest('bad', 'Bad', rewards: ['items' => [['quantity' => 2]]])->describeRewards())->toBe('Unknown item x2')
    ->and(new Quest('missing', 'Missing', rewards: ['items' => [['item' => 'missing'], 'item.tonic']])->describeRewards())
    ->toBe('missing (unavailable), Current Tonic');
  ConfigStore::remove(ItemStore::class);
  expect(new Quest('legacy', 'Legacy', rewards: ['items' => ['Legacy name']])->describeRewards())->toBe('Legacy name')
    ->and(new Quest('unloaded', 'Unloaded', rewards: ['items' => [['item' => 'item.tonic']]])->describeRewards())->toBe('item.tonic');
});

it('removes per-level toasts and retains quest reward details in one acknowledged summary', function (bool $quiet) {
  $saved = new ReflectionProperty(ModalManager::class, 'instance')->getValue();
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };
  $quest = new Quest('completion', 'A named quest', rewards: ['gold' => 200, 'experience' => 1000]);
  $results = array_map(fn($name) => new ExperienceAwardResult(new Character($name, 0, new Stats()), 1000, 1, 4,
    learnedAbilities: ['Dual Slash'], learnedMagic: ['Burn 1']), ['Hero', 'Friend']);
  $manager = new QuestCompletionPresentationProbe($game, $results, $quest);
  try {
    $manager->finishQuest($quest, $quiet);
    $manager->finishQuest($quest, $quiet);
    $modals = ModalManager::getInstance($game);
    $pending = new ReflectionProperty($modals, 'pendingAlerts')->getValue($modals);
    expect($manager->grants)->toBe(1)
      ->and($manager->notices)->toBe($quiet ? [] : [['Quest Complete', 'A named quest', 'quest.complete']]);
    if ($quiet) { expect($pending)->toBe([]); }
    else {
      expect($pending)->toHaveCount(1)->and($pending[0]['title'])->toBe('A named quest')
        ->and($pending[0]['message'])->toBe("Rewards: 200 G, 1000 EXP\nHero reached level 4. Learned Dual Slash, Burn 1.\nFriend reached level 4. Learned Dual Slash, Burn 1.");
    }
  } finally { new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $saved); }
})->with([false, true]);

it('keeps progress notices terse while retaining complete objective prose in the quest definition', function () {
  $game = new class extends Game { public function __construct() {} public function __destruct() {} };
  $description = str_repeat('This complete objective description belongs in the journal. ', 6);
  $quest = new Quest('progress', 'Named quest', objectives: [new \Ichiloto\Engine\Quests\QuestObjective(
    \Ichiloto\Engine\Quests\QuestObjectiveType::DEFEAT, 'Rat', 20, $description)]);
  $manager = new QuestCompletionPresentationProbe($game, [], $quest);
  $manager->advanceObjective($quest, 2);
  expect($manager->notices)->toBe([['Quest Updated', "Named quest\nProgress 2/20", null]])
    ->and($quest->objectives[0]->description)->toBe($description);
});
