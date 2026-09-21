<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\DiscardItemMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectIemMenuCommandMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectItemTargetMode;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ConfirmModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Modal\QuantityModal;
use Ichiloto\Engine\UI\Modal\QuantityPresentation;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

final class ItemQuantityGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

/** Drives the real blocking modal loop, without a terminal, native process or audio. */
final class ItemQuantityInput implements InputSourceInterface
{
  public array $frames = [];
  public ?Closure $beforePoll = null;

  private array $keys = [];

  public function __construct(private ModalManager $manager, array $keys)
  {
    foreach ($keys as $index => $key) {
      if ($index > 0) { $this->keys[] = null; }
      $this->keys[] = $key;
    }
  }

  public function poll(): ?KeyCode
  {
    if ($this->keys === []) { throw new RuntimeException('Unexpected extra input poll.'); }
    $key = array_shift($this->keys);
    if ($key === null) { return null; }
    $modal = $this->manager->currentModal;
    if ($modal !== null) {
      $this->frames[] = ['type' => $modal::class, 'snapshot' => $modal->getModalPresentation(),
        'text' => implode("\n", array_map(TerminalText::stripAnsi(...), Console::getBuffer()))];
    }
    ($this->beforePoll ?? static fn() => null)($modal);
    return $key;
  }

  public function reset(bool $drainBufferedInput = false): void
  {
    if ($drainBufferedInput) { $this->keys = []; }
  }
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, Timers::class, EventManager::class,
    ModalManager::class, AudioManager::class, QuestManager::class, ActionHints::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  foreach ([EventManager::class, ModalManager::class] as $class) {
    new ReflectionProperty($class, 'instance')->setValue(null, null);
  }
  new ReflectionProperty(QuestManager::class, 'current')->setValue(null, null);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  ConfigStore::remove(\Ichiloto\Engine\Util\Stores\ItemStore::class);
  ActionHints::useProvider(null);
  Timers::setFrameTick(null);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  $this->game = new ItemQuantityGame();
  $audio = new class extends AudioManager {
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void {}
  };
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $audio);
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $sceneManager = makeBareScene(SceneManager::class);
  new ReflectionProperty($sceneManager, 'game')->setValue($sceneManager, $this->game);
  $scene = makeBareScene(GameScene::class);
  new ReflectionProperty($scene, 'sceneManager')->setValue($scene, $sceneManager);
  $this->party = new Party();
  $this->actor = new Character('First Actor', 0, new Stats(totalHp: 500));
  $this->actor->stats->currentHp = 100;
  $this->party->addMember($this->actor);
  new ReflectionProperty($scene, 'party')->setValue($scene, $this->party);
  $this->state = new ItemMenuState(new SceneStateContext($scene));
  $this->manager = ModalManager::getInstance($this->game);
  InputManager::setBindings([
    'confirm' => ['keys' => [KeyCode::ENTER]], 'back' => ['keys' => [KeyCode::ESCAPE]],
    'cancel' => ['keys' => [KeyCode::ESCAPE, KeyCode::C]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]],
  ]);
  ob_start();
  $this->state->initializeMenuUI();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

function quantityItemSetup(ItemMenuState $state, int $quantity, bool $discard = false): Item
{
  $item = new Item('Potion', 'Restores HP.', '', 10, quantity: $quantity, id: 'potion',
    effects: [new HPRecoveryEffect('Heal', 'Heal', 50, 100, ValueBasis::ACTUAL)]);
  $state->getGameScene()->party->inventory->addItems($item);
  $state->selectionPanel->setItems([$item]);
  $state->selectionPanel->focus();
  $state->setMode($discard ? new DiscardItemMode($state) : new SelectItemTargetMode($state));
  return $item;
}

function quantityItemRun(ItemMenuState $state, ModalManager $manager, array $keys, ?Closure $beforePoll = null): ItemQuantityInput
{
  $input = new ItemQuantityInput($manager, [KeyCode::ENTER, ...$keys]);
  $input->beforePoll = $beforePoll;
  InputManager::setInputSource($input);
  InputManager::handleInput();
  $state->execute();
  expect($manager->currentModal)->toBeNull()->and(Input::isButtonDown('confirm'))->toBeFalse();
  return $input;
}

it('requires a real confirmation before using a single unit and consumes its input only once', function () {
  $item = quantityItemSetup($this->state, 1);
  $mode = $this->state->mode;
  $input = quantityItemRun($this->state, $this->manager, [KeyCode::ENTER, KeyCode::ENTER], function ($modal) use ($item) {
    if ($modal instanceof ConfirmModal) { expect($item->quantity)->toBe(1)->and($this->actor->stats->currentHp)->toBe(100); }
  });
  expect(array_column($input->frames, 'type'))->toBe([ConfirmModal::class, AlertModal::class])
    ->and($input->frames[0]['snapshot']->message)->toBe('Use Potion x 1 on First Actor?')
    ->and($item->quantity)->toBe(0)->and($this->party->inventory->all->count())->toBe(0)
    ->and($this->actor->stats->currentHp)->toBe(150)->and($this->state->mode)->toBe($mode);
  $this->state->execute();
  expect($this->actor->stats->currentHp)->toBe(150);
});

it('cancels either a semantic action or the Cancel choice without using a single unit', function (array $keys) {
  $item = quantityItemSetup($this->state, 1);
  quantityItemRun($this->state, $this->manager, $keys);
  expect($item->quantity)->toBe(1)->and($this->actor->stats->currentHp)->toBe(100)
    ->and($this->state->selectionPanel->activeItem)->toBe($item)
    ->and($this->state->targetSelectionPanel->activeCharacter)->toBe($this->actor);
})->with([[ [KeyCode::ESCAPE] ], [ [KeyCode::RIGHT, KeyCode::ENTER] ]]);

it('selects stack amount in a modal then separately confirms its exact use', function () {
  $item = quantityItemSetup($this->state, 3);
  $input = quantityItemRun($this->state, $this->manager, [KeyCode::UP, KeyCode::ENTER, KeyCode::ENTER, KeyCode::ENTER], function ($modal) use ($item) {
    if ($modal instanceof QuantityModal || $modal instanceof ConfirmModal) {
      expect($item->quantity)->toBe(3)->and($this->actor->stats->currentHp)->toBe(100)
        ->and(implode('', $this->state->infoPanel->getContent()))->toContain('Restores HP.')->not->toContain('Use Potion');
    }
  });
  expect(array_column($input->frames, 'type'))->toBe([QuantityModal::class, QuantityModal::class, ConfirmModal::class, AlertModal::class])
    ->and($input->frames[0]['snapshot']->quantity->value)->toBe(1)
    ->and($input->frames[1]['snapshot']->quantity->value)->toBe(2)
    ->and($input->frames[1]['snapshot']->message)->toBe('Use Potion on First Actor')
    ->and($input->frames[1]['text'])->toContain('Quantity: 02 / 3', 'Continue')
    ->and($input->frames[2]['snapshot']->message)->toBe('Use Potion x 2 on First Actor?')
    ->and($item->quantity)->toBe(1)->and($this->actor->stats->currentHp)->toBe(200);
});

it('cancels stack selection or its confirmation without changing items or target', function (bool $discard, array $keys) {
  $item = quantityItemSetup($this->state, 3, $discard);
  $mode = $this->state->mode;
  quantityItemRun($this->state, $this->manager, $keys);
  expect($item->quantity)->toBe(3)->and($this->actor->stats->currentHp)->toBe(100)
    ->and($this->state->mode)->toBe($mode)->and($this->state->selectionPanel->activeItem)->toBe($item);
})->with([false, true])->with([[ [KeyCode::ESCAPE] ], [ [KeyCode::UP, KeyCode::ENTER, KeyCode::ESCAPE] ]]);

it('discards only the confirmed quantity and retains the selected remainder', function () {
  $item = quantityItemSetup($this->state, 5, true);
  $input = quantityItemRun($this->state, $this->manager, [KeyCode::UP, KeyCode::ENTER, KeyCode::ENTER]);
  expect(array_column($input->frames, 'type'))->toBe([QuantityModal::class, QuantityModal::class, ConfirmModal::class])
    ->and($input->frames[2]['snapshot']->message)->toBe('Discard Potion x 2?')
    ->and($item->quantity)->toBe(3)->and($this->state->selectionPanel->activeItem)->toBe($item)
    ->and($this->state->mode)->toBeInstanceOf(DiscardItemMode::class);
  $this->state->execute();
  expect($item->quantity)->toBe(3);
});

it('clamps discard to available stock and returns to commands when regular items run out', function () {
  $item = quantityItemSetup($this->state, 3, true);
  $key = new Item('Key', 'Keep', '', 0, isKeyItem: true);
  $this->party->inventory->addItems($key);
  quantityItemRun($this->state, $this->manager, [KeyCode::RIGHT, KeyCode::ENTER, KeyCode::ENTER, KeyCode::ENTER]);
  expect($item->quantity)->toBe(0)->and($key->quantity)->toBe(1)
    ->and($this->party->inventory->all->toArray())->toBe([$key])
    ->and($this->state->mode)->toBeInstanceOf(SelectIemMenuCommandMode::class)
    ->and(implode('', $this->state->infoPanel->getContent()))->toContain($this->state->itemMenu->getActiveItem()->getDescription());
});

it('rejects key items and equipment even when a stale panel exposes them', function (bool $discard, bool $equipment) {
  quantityItemSetup($this->state, 3, $discard);
  $protected = $equipment ? makeBareScene(Weapon::class) : new Item('Key', 'Keep', '', 0, isKeyItem: true);
  if ($equipment) {
    foreach (['id' => 'weapon', 'name' => 'Weapon', 'description' => 'Keep', 'quantity' => 3] as $name => $value) {
      new ReflectionProperty($protected, $name)->setValue($protected, $value);
    }
  }
  $this->party->inventory->addItems($protected);
  $this->state->selectionPanel->setItems([$protected]);
  $before = $protected->quantity;
  $input = quantityItemRun($this->state, $this->manager, [KeyCode::ENTER]);
  expect(array_column($input->frames, 'type'))->toBe([AlertModal::class])
    ->and($protected->quantity)->toBe($before)->and($this->actor->stats->currentHp)->toBe(100);
})->with([false, true])->with([false, true]);

it('rejects stock that shrinks during quantity selection before opening confirmation', function (bool $discard) {
  $item = quantityItemSetup($this->state, 3, $discard);
  $input = quantityItemRun($this->state, $this->manager, [KeyCode::UP, KeyCode::ENTER, KeyCode::ENTER], function ($modal) use ($item) {
    if ($modal instanceof QuantityModal && $modal->quantity === 2) { $item->quantity = 1; }
  });
  expect(array_column($input->frames, 'type'))->not->toContain(ConfirmModal::class)
    ->and($item->quantity)->toBe(1)->and($this->actor->stats->currentHp)->toBe(100);
})->with([false, true]);

it('does not apply a stale confirmation to a replacement stack with the same identity', function (bool $discard) {
  $item = quantityItemSetup($this->state, 1, $discard);
  $replacement = new Item('Potion', 'Replacement', '', 10, quantity: 9, id: 'potion');
  quantityItemRun($this->state, $this->manager, [KeyCode::ENTER, KeyCode::ENTER], function ($modal) use ($item, $replacement) {
    if ($modal instanceof ConfirmModal) {
      $this->party->inventory->all->remove($item);
      $this->party->inventory->addItems($replacement);
    }
  });
  expect($replacement->quantity)->toBe(9)->and($item->quantity)->toBe(1)
    ->and($this->actor->stats->currentHp)->toBe(100);
})->with([false, true]);

it('rejects a confirmed amount when the same stack shrinks while the dialog is open', function (bool $discard) {
  $item = quantityItemSetup($this->state, 3, $discard);
  quantityItemRun($this->state, $this->manager, [KeyCode::UP, KeyCode::ENTER, KeyCode::ENTER, KeyCode::ENTER], function ($modal) use ($item) {
    if ($modal instanceof ConfirmModal) { $item->quantity = 1; }
  });
  expect($item->quantity)->toBe(1)->and($this->actor->stats->currentHp)->toBe(100);
})->with([false, true]);

it('rejects an empty stack before either selection or confirmation', function (bool $discard) {
  $item = quantityItemSetup($this->state, 0, $discard);
  $input = quantityItemRun($this->state, $this->manager, [KeyCode::ENTER]);
  expect(array_column($input->frames, 'type'))->toBe([AlertModal::class])
    ->and($item->quantity)->toBe(0)->and($this->actor->stats->currentHp)->toBe(100);
})->with([false, true]);

it('rejects a target that leaves the party while confirmation is open', function () {
  $item = quantityItemSetup($this->state, 1);
  quantityItemRun($this->state, $this->manager, [KeyCode::ENTER, KeyCode::ENTER], function ($modal) {
    if ($modal instanceof ConfirmModal) { $this->party->members->remove($this->actor); }
  });
  expect($item->quantity)->toBe(1)->and($this->actor->stats->currentHp)->toBe(100);
});

it('keeps quantity metadata readonly and optional for ordinary modals', function () {
  $quantity = new QuantityPresentation(1, 3, 2);
  expect(fn() => $quantity->value = 3)->toThrow(Error::class);
  expect(new ModalPresentation('', 'Confirm?', ['OK', 'Cancel'], 0)->quantity)->toBeNull();
});

it('rejects invalid quantity snapshots', function (int $minimum, int $maximum, int $value) {
  expect(fn() => new QuantityPresentation($minimum, $maximum, $value))->toThrow(InvalidArgumentException::class);
})->with([[-1, 3, 1], [3, 1, 2], [1, 3, 0], [1, 3, 4]]);

it('uses remapped semantic axes and actions in the real quantity modal and cleans up its stack', function () {
  InputManager::setBindings(['up' => ['keys' => [KeyCode::W]], 'right' => ['keys' => [KeyCode::D]],
    'left' => ['keys' => [KeyCode::A]], 'down' => ['keys' => [KeyCode::S]],
    'confirm' => ['keys' => [KeyCode::Q]], 'cancel' => ['keys' => [KeyCode::X]]]);
  $input = new ItemQuantityInput($this->manager, [KeyCode::W, KeyCode::D, KeyCode::D, KeyCode::A, KeyCode::S, KeyCode::Q]);
  InputManager::setInputSource($input);
  expect($this->manager->selectQuantity('Choose amount', 14, initial: 4))->toBe(3)
    ->and(array_map(fn($frame) => $frame['snapshot']->quantity->value, $input->frames))->toBe([4, 5, 14, 14, 4, 3])
    ->and($input->frames[0]['text'])->toContain('W: +1', 'Quantity: 04 / 14')
    ->and($this->manager->currentModal)->toBeNull();
});

it('removes the quantity modal from its manager even when input fails', function () {
  InputManager::setInputSource(new ItemQuantityInput($this->manager, []));
  expect(fn() => $this->manager->selectQuantity('Choose amount', 3))->toThrow(RuntimeException::class, 'Unexpected extra input poll.')
    ->and($this->manager->currentModal)->toBeNull();
});

it('accepts a separately bound Back action without requiring a Cancel alias', function () {
  InputManager::setBindings(['back' => ['keys' => [KeyCode::X]]]);
  InputManager::setInputSource(new ItemQuantityInput($this->manager, [KeyCode::X]));
  expect($this->manager->selectQuantity('Choose amount', 3))->toBeNull()
    ->and($this->manager->currentModal)->toBeNull();
});

it('keeps long Unicode labels and the entire quantity range reachable in terminal rows', function () {
  $message = "Use Caf\u{00e9} \u{6f22}\u{5b57} item on the selected actor";
  $input = new ItemQuantityInput($this->manager, [KeyCode::ENTER]);
  $input->beforePoll = function ($modal) use ($message) {
    expect($modal)->toBeInstanceOf(QuantityModal::class);
    $lines = new ReflectionProperty($modal, 'content')->getValue($modal);
    foreach ($lines as $line) {
      expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual(18)
        ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
    expect(implode('', $lines))->toBe($message . sprintf('Quantity: %019d / %d', 1, PHP_INT_MAX));
  };
  InputManager::setInputSource($input);
  expect($this->manager->selectQuantity($message, PHP_INT_MAX, width: 20))->toBe(1);
});

it('restores an existing modal owner after the quantity modal closes', function () {
  $parent = new AlertModal($this->game, 'Parent', '');
  $this->manager->open($parent);
  InputManager::setInputSource(new ItemQuantityInput($this->manager, [KeyCode::ESCAPE]));
  expect($this->manager->selectQuantity('Choose amount', 3))->toBeNull()
    ->and($this->manager->currentModal)->toBe($parent)->and($parent->isShowing())->toBeTrue();
  $parent->hide();
});
