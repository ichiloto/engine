<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Actions\ChestOpeningAction;
use Ichiloto\Engine\Entities\Actions\FieldActionContext;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Tests\Support\PassiveGame;

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, ConfigStore::class, Debug::class, ModalManager::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = createTestDirectory('chest-reward-');
  Debug::configure(['log_directory' => $this->root]);
  $this->store = new class extends ItemStore { public function __construct() {} };
  ConfigStore::put(ItemStore::class, $this->store);
  $game = new PassiveGame();
  $this->audio = new class extends AudioManager {
    public array $sounds = [];
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void { $this->sounds[] = $sound; }
  };
  new ReflectionProperty($game, 'audioManager')->setValue($game, $this->audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $game);
  $this->alerts = new class extends ModalManager {
    public array $messages = [];
    public function __construct() {}
    public function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void {
      $this->messages[] = $message;
    }
  };
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->alerts);
  $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  $manager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
  new ReflectionProperty($manager, 'game')->setValue($manager, $game);
  new ReflectionProperty($scene, 'sceneManager')->setValue($scene, $manager);
  $this->party = new Party();
  $this->party->accountBalance = 42;
  $this->state = new GameState();
  new ReflectionProperty($scene, 'party')->setValue($scene, $this->party);
  new ReflectionProperty($scene, 'gameState')->setValue($scene, $this->state);
  $this->player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
  $this->context = new FieldActionContext($this->player, $scene, new Vector2(1, 1));
});

afterEach(function () {
  foreach ($this->saved as $class => $values) {
    foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('keeps unresolved chest rewards closed and unchanged with a player message and actionable diagnostic', function (mixed $reference) {
  $trigger = new ChestEventTrigger(new Rect(1, 1, 1, 1),
    ['loot' => $reference, 'lootType' => 'item'], mapId: 'synthetic/room', marker: 'C');
  $trigger->bind($this->state, $this->party);
  $action = new ChestOpeningAction($trigger);
  $this->player->availableAction = $action;
  $before = serialize([$this->state->toArray(), $this->party->inventory, $this->party->accountBalance]);
  $action->execute($this->context);
  expect(serialize([$this->state->toArray(), $this->party->inventory, $this->party->accountBalance]))->toBe($before)
    ->and($trigger->isComplete)->toBeFalse()
    ->and($this->player->availableAction)->toBe($action)
    ->and($this->audio->sounds)->toBe([])
    ->and($this->alerts->messages)->toBe([get_engine_message('chest.unavailable')]);
  expect(file_get_contents($this->root . '/error.log'))->toContain('synthetic/room', 'event "C"', '[chest.reward]');
  expect($this->alerts->messages[0])->not->toContain('Inventory', 'synthetic', $this->root);
  $restored = GameState::fromArray($this->state->toArray());
  expect($restored->isEventComplete('synthetic/room', 'C'))->toBeFalse();

  if (is_string($reference) && trim($reference) !== '') {
    $item = new Item('Current Tonic', 'Synthetic reward', '!', 1, id: 'item.tonic', aliases: [$reference]);
    $this->store->set($item->id, $item);
    $action->execute($this->context);
    $action->execute($this->context);
    expect($this->party->inventory->getQuantity('item.tonic'))->toBe(1)
      ->and($trigger->isComplete)->toBeTrue()
      ->and($this->audio->sounds)->toBe([SystemSound::ITEM_GET]);
    expect(GameState::fromArray($this->state->toArray())->isEventComplete('synthetic/room', 'C'))->toBeTrue();
  }
})->with([[''], [null], ['   '], ['Removed Tonic']]);

it('rejects unsupported reward categories before a matching inventory name can grant the wrong reward', function (LootType $type) {
  $item = new Item('Shared Name', 'Synthetic inventory name collision', '!', 1, id: 'item.shared');
  $this->store->set($item->id, $item);
  $trigger = new ChestEventTrigger(new Rect(1, 1, 1, 1),
    ['loot' => $item->name, 'lootType' => $type], mapId: 'synthetic/room', marker: 'C');
  $trigger->bind($this->state, $this->party);
  $action = new ChestOpeningAction($trigger);
  $this->player->availableAction = $action;
  $before = serialize([$this->state->toArray(), $this->party->inventory, $this->party->accountBalance]);
  $action->execute($this->context);
  expect(ChestOpeningAction::supportsLootType($type))->toBeFalse()
    ->and(serialize([$this->state->toArray(), $this->party->inventory, $this->party->accountBalance]))->toBe($before)
    ->and($trigger->isComplete)->toBeFalse()
    ->and($this->player->availableAction)->toBe($action)
    ->and($this->audio->sounds)->toBe([])
    ->and($this->alerts->messages)->toBe([get_engine_message('chest.unavailable')]);
})->with([LootType::EXPERIENCE, LootType::SKILL, LootType::SPELL]);

it('grants current inventory and quantity-based gold exactly once', function (bool $gold) {
  $item = new Item('Current Tonic', 'Synthetic reward', '!', 1, id: 'item.tonic');
  $this->store->set($item->id, $item);
  $trigger = new ChestEventTrigger(new Rect(1, 1, 1, 1),
    ['loot' => $gold ? null : $item->id, 'lootType' => $gold ? 'gold' : 'item', 'quantity' => 3],
    mapId: 'synthetic/room', marker: 'C');
  $trigger->bind($this->state, $this->party);
  $action = new ChestOpeningAction($trigger);
  $this->player->availableAction = $action;
  $action->execute($this->context);
  $action->execute($this->context);
  expect($this->party->accountBalance)->toBe($gold ? 45 : 42)
    ->and($this->party->inventory->getQuantity($item->id))->toBe($gold ? 0 : 3)
    ->and($trigger->isComplete)->toBeTrue()
    ->and($this->player->availableAction)->toBeNull()
    ->and($this->audio->sounds)->toBe([SystemSound::ITEM_GET]);
})->with([true, false]);

it('removes zero negative fractional and unknown rewards without completing or changing party state', function (array $data) {
  $item = new Item('Current Tonic', 'Synthetic reward', '!', 1, id: 'item.tonic');
  $this->store->set($item->id, $item);
  $trigger = new ChestEventTrigger(new Rect(1, 1, 1, 1), $data, mapId: 'synthetic/room', marker: 'C');
  $trigger->bind($this->state, $this->party);
  $action = new ChestOpeningAction($trigger);
  $this->player->availableAction = $action;
  $before = serialize([$this->state->toArray(), $this->party->inventory, $this->party->accountBalance]);
  $action->execute($this->context);
  expect(serialize([$this->state->toArray(), $this->party->inventory, $this->party->accountBalance]))->toBe($before)
    ->and($trigger->isComplete)->toBeFalse()->and($this->player->availableAction)->toBe($action)
    ->and($this->audio->sounds)->toBe([])->and($this->alerts->messages)->toBe([get_engine_message('chest.unavailable')]);
})->with([
  [['lootType' => 'gold', 'loot' => 0]],
  [['lootType' => 'gold', 'loot' => -10]],
  [['lootType' => 'gold', 'loot' => 1.5]],
  [['lootType' => 'item', 'loot' => 'item.tonic', 'quantity' => 0]],
  [['lootType' => 'item', 'loot' => 'item.tonic', 'quantity' => -1]],
  [['lootType' => 'ITEM', 'loot' => 'item.tonic']],
]);
