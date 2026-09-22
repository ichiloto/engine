<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\DiscardItemMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectIemMenuCommandMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectItemTargetMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\UseItemMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\ViewKeyItemsMode;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Presentation\ItemMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

class ItemMenuGameProbe extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

class ItemMenuAlerts extends ModalManager
{
  public array $messages = [];
  public bool $confirmation = false;
  public ?int $amount = null;
  public ?Closure $onAlert = null;
  public function __construct() { $this->modals = new \Assegai\Collections\Stack(\Ichiloto\Engine\UI\Interfaces\ModalInterface::class); }
  public function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void
  {
    $this->messages[] = $message;
    ($this->onAlert ?? static fn() => null)();
  }
  public function confirm(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): bool { return $this->confirmation; }
  public function selectQuantity(string $message, int $maximum, string $title = '', int $initial = 1, int $width = DEFAULT_DIALOG_WIDTH): ?int { return $this->amount; }
}

function itemMenuPress(ItemMenuState $state, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->execute();
}

function itemMenuText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(fn($layer) => implode('', array_column($layer->runs, 'text')), $canvas->textLayers));
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, InputManager::class, ActionHints::class, ConfigStore::class, Debug::class, ModalManager::class,
    \Ichiloto\Engine\Audio\AudioManager::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-items-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root]);
  new ReflectionProperty(\Ichiloto\Engine\Audio\AudioManager::class, 'instance')->setValue(null, null);
  $this->alerts = new ItemMenuAlerts();
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->alerts);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  $this->game = $game = new ItemMenuGameProbe();
  $audio = new class extends \Ichiloto\Engine\Audio\AudioManager {
    public function __construct() {}
    public function playSystemSound(\Ichiloto\Engine\Audio\Enumerations\SystemSound $sound): void {}
  };
  new ReflectionProperty($game, 'audioManager')->setValue($game, $audio);
  $this->runtime = null;
  new ReflectionProperty(Console::class, 'game')->setValue(null, $game);
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty($manager, 'game')->setValue($manager, $game);
  $scene = makeBareScene(GameScene::class);
  new ReflectionProperty($scene, 'sceneManager')->setValue($scene, $manager);
  $ui = makeBareScene(\Ichiloto\Engine\UI\UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($scene, 'uiManager')->setValue($scene, $ui);
  $this->party = new Party();
  $this->actor = new Character('First Actor', 0, new Stats(totalHp: 500, totalMp: 100));
  $this->actor->stats->currentHp = 100;
  $this->actor->stats->currentMp = 100;
  $this->party->addMember($this->actor);
  $this->party->addMember(new Character('Second Actor', 0, new Stats()));
  new ReflectionProperty($scene, 'party')->setValue($scene, $this->party);
  $this->state = new ItemMenuState(new SceneStateContext($scene));
  new ReflectionProperty($scene, 'state')->setValue($scene, $this->state);
  InputManager::setBindings([
    'confirm' => ['keys' => [KeyCode::ENTER]], 'back' => ['keys' => [KeyCode::ESCAPE]], 'cancel' => ['keys' => [KeyCode::C]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]],
  ]);
  ob_start();
  $this->state->enter();
  $this->theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false]);
});

afterEach(function () {
  $this->runtime?->shutdown();
  ob_end_clean();
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

function itemMenuInventory(ItemMenuState $state, int $count, bool $keys = false): array
{
  $items = [];
  for ($i = 0; $i < $count; $i++) {
    $items[] = new Item(sprintf('Item %03d', $i), 'Exact description ' . $i, '/', 10, quantity: $i % 98 + 1,
      isKeyItem: $keys, id: ($keys ? 'key.' : 'item.') . $i);
  }
  $state->getGameScene()->party->inventory->addItems(...$items);
  $state->selectionPanel->setItems($items);
  return $items;
}

it('renders the live item commands and inventory through different reusable themes without mutation', function () {
  $items = itemMenuInventory($this->state, 3);
  $themes = [$this->theme, new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'colors' => ['panel' => [244, 237, 217], 'text' => [30, 35, 50]],
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 32, 'panelPadding' => 16]])];
  foreach ($themes as $theme) {
    $canvas = ItemMenuPresentation::compose($this->state, $theme);
    expect($canvas)->toBeInstanceOf(PresentationCanvas::class);
    $text = itemMenuText($canvas);
    foreach (['Use', 'Sort', 'Discard', 'Key Items', 'Item 000', 'Item 002'] as $label) { expect($text)->toContain($label); }
    expect($text)->not->toContain('Enter', 'Escape');
    foreach ($canvas->textLayers as $layer) { expect($layer->id)->not->toContain('command-0-cursor'); }
  }
  expect($this->state->selectionPanel->items)->toBe($items)->and($items[0]->quantity)->toBe(1);
});

it('keeps every item reachable using terminal page slicing and graphical selection following', function () {
  itemMenuInventory($this->state, 70);
  $this->state->setMode(new UseItemMode($this->state));
  $panel = $this->state->selectionPanel;
  $size = $panel->pageSize;
  expect($panel->totalPages)->toBe((int)ceil(70 / $size));
  itemMenuPress($this->state, KeyCode::RIGHT);
  expect($panel->activeIndex)->toBe($size)->and($panel->page)->toBe(2);
  expect(implode('', $panel->getContent()))->toContain(sprintf('Item %03d', $size))->not->toContain('Item 000');
  expect(count($panel->getContent()))->toBe($size);
  for ($index = 0; $index < 70; $index++) {
    $panel->activeIndex = $index;
    $canvas = ItemMenuPresentation::compose($this->state, $this->theme);
    expect(itemMenuText($canvas))->toContain(sprintf('Item %03d', $index));
    expect(implode('', $panel->getContent()))->toContain(sprintf('Item %03d', $index));
  }
  $panel->selectNext();
  expect($panel->activeIndex)->toBe(0)->and($panel->page)->toBe(1);
  $panel->selectPrevious();
  expect($panel->activeIndex)->toBe(69)->and($panel->page)->toBe($panel->totalPages);
});

it('normalizes keyed input and clamps selection after a changing or empty inventory', function () {
  $items = itemMenuInventory($this->state, 30);
  $panel = $this->state->selectionPanel;
  $panel->activeIndex = 29;
  $panel->setItems([12 => $items[0], 99 => $items[1]]);
  expect($panel->items)->toBe([$items[0], $items[1]])->and($panel->activeIndex)->toBe(1)->and($panel->page)->toBe(1);
  $panel->setItems([]);
  $panel->selectNext();
  $panel->selectPrevious();
  $panel->changePage(1);
  $panel->focus();
  expect($panel->activeIndex)->toBe(-1)->and($panel->activeItem)->toBeNull()->and($panel->totalPages)->toBe(1);
  expect(ItemMenuPresentation::compose($this->state, $this->theme))->toBeInstanceOf(PresentationCanvas::class);
});

it('shares page navigation with read-only key items without use or discard', function () {
  $items = itemMenuInventory($this->state, 50, true);
  $this->state->setMode(new ViewKeyItemsMode($this->state));
  itemMenuPress($this->state, KeyCode::RIGHT);
  $index = $this->state->selectionPanel->activeIndex;
  expect($index)->toBe($this->state->selectionPanel->pageSize);
  itemMenuPress($this->state, KeyCode::ENTER);
  expect($this->state->mode)->toBeInstanceOf(ViewKeyItemsMode::class)
    ->and($this->party->inventory->keyItems->count())->toBe(50);
  expect(itemMenuText(ItemMenuPresentation::compose($this->state, $this->theme)))->toContain($items[$index]->name, $items[$index]->description);
});

it('opens the read-only empty key view while rejecting empty regular inventory actions', function () {
  itemMenuPress($this->state, KeyCode::ENTER);
  expect($this->state->mode)->toBeInstanceOf(SelectIemMenuCommandMode::class)->and($this->alerts->messages)->toHaveCount(1);
  $this->state->itemMenu->setActiveItemByLabel('Key Items');
  itemMenuPress($this->state, KeyCode::ENTER);
  expect($this->state->mode)->toBeInstanceOf(ViewKeyItemsMode::class)
    ->and(itemMenuText(ItemMenuPresentation::compose($this->state, $this->theme)))->toContain('No key items yet.');
});

it('leaves the Info panel as description while shared quantity and confirmation owners decide use', function () {
  $item = new Item('Potion', 'Restore HP', '/', 10, quantity: 3,
    effects: [new HPRecoveryEffect('Heal', 'Heal', 50, 100, ValueBasis::ACTUAL)]);
  $this->party->inventory->addItems($item);
  $this->state->selectionPanel->setItems([$item]);
  $this->state->setMode(new UseItemMode($this->state));
  itemMenuPress($this->state, KeyCode::ENTER);
  expect($this->state->mode)->toBeInstanceOf(SelectItemTargetMode::class);
  $before = ItemMenuPresentation::compose($this->state, $this->theme);
  expect(itemMenuText($before))->toContain('First Actor', '100 / 500', '100 / 100');
  itemMenuPress($this->state, KeyCode::ENTER);
  $quantity = ItemMenuPresentation::compose($this->state, $this->theme);
  expect(itemMenuText($quantity))->toContain('Restore HP')->not->toContain('Use Potion x', 'Up/Down', 'C/Esc');
  expect($item->quantity)->toBe(3)->and($this->actor->stats->currentHp)->toBe(100);
  $this->alerts->amount = 2;
  itemMenuPress($this->state, KeyCode::ENTER);
  expect($item->quantity)->toBe(3)->and($this->actor->stats->currentHp)->toBe(100);
  $this->alerts->confirmation = true;
  $alertSeen = false;
  $this->alerts->onAlert = function () use (&$alertSeen) {
    $alertSeen = true;
    expect(itemMenuText(ItemMenuPresentation::compose($this->state, $this->theme)))
      ->toContain('Restore HP')->not->toContain('Use Potion x', 'Up/Down', 'C/Esc');
  };
  itemMenuPress($this->state, KeyCode::ENTER);
  expect($alertSeen)->toBeTrue();
  expect($item->quantity)->toBe(1)->and($this->actor->stats->currentHp)->toBe(200);
  expect(itemMenuText(ItemMenuPresentation::compose($this->state, $this->theme)))->toContain('200 / 500');
});

it('pages complete descriptions inside one fixed two-line Info panel', function () {
  itemMenuInventory($this->state, 1);
  $description = implode("\n", ['First source line', 'Second source line', 'Third source line', 'Fourth source line']);
  $this->state->infoPanel->setText($description);
  $canvas = ItemMenuPresentation::compose($this->state, $this->theme);
  expect(itemMenuText($canvas))->toContain('First source line', 'Second source line', 'Lines 1-2 / 4')
    ->not->toContain('Third source line');
  $bounds = array_column($canvas->textLayers, null, 'id')['items-description']->clipRect;
  $this->state->menuInfoText->advance();
  $next = ItemMenuPresentation::compose($this->state, $this->theme);
  expect(itemMenuText($next))->toContain('Third source line', 'Fourth source line', 'Lines 3-4 / 4')
    ->and(array_column($next->textLayers, null, 'id')['items-description']->clipRect)->toEqual($bounds)
    ->and($this->state->menuInfoText->lastPage->source)->toBe($description);
});

it('uses the ordinary terminal path when no renderer is selected', function () {
  expect($this->state->getPresentationCanvas())->toBeNull();
  itemMenuInventory($this->state, 30);
  $this->state->setMode(new DiscardItemMode($this->state));
  itemMenuPress($this->state, KeyCode::RIGHT);
  expect($this->state->selectionPanel->activeIndex)->toBe($this->state->selectionPanel->pageSize);
  itemMenuPress($this->state, KeyCode::ESCAPE);
  expect($this->state->mode)->toBeInstanceOf(SelectIemMenuCommandMode::class);
});

it('returns to the same inventory selection after cancelling target selection', function () {
  itemMenuInventory($this->state, 40);
  $this->state->setMode(new UseItemMode($this->state));
  $this->state->selectionPanel->activeIndex = 30;
  itemMenuPress($this->state, KeyCode::ENTER);
  itemMenuPress($this->state, KeyCode::ESCAPE);
  expect($this->state->mode)->toBeInstanceOf(UseItemMode::class)
    ->and($this->state->selectionPanel->activeIndex)->toBe(30);
});

it('does not leak key items into the discard list after confirming removal', function () {
  itemMenuInventory($this->state, 2);
  $key = new Item('Key', 'Do not discard', '!', 0, isKeyItem: true);
  $this->party->inventory->addItems($key);
  $this->state->setMode(new DiscardItemMode($this->state));
  itemMenuPress($this->state, KeyCode::ENTER);
  expect($this->state->selectionPanel->items)->toHaveCount(2);
  $this->alerts->confirmation = true;
  itemMenuPress($this->state, KeyCode::ENTER);
  expect($this->state->selectionPanel->items)->toHaveCount(1)
    ->and($this->party->inventory->keyItems->count())->toBe(1)
    ->and($this->state->selectionPanel->items[0]->isKeyItem)->toBeFalse();
});

it('uses menu-only renderer capabilities and retains its canvas beneath alerts', function (bool $supported) {
  mkdir($this->root . '/Data/Presentation', 0777, true);
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE,
    '<?php return ' . var_export(['schema' => 'ichiloto.menu/1', 'showInputHints' => false], true) . ';');
  $caps = $supported ? MenuPresentationCatalog::CAPABILITIES : [];
  $transport = new FakeRendererTransport();
  $transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $this->root,
    requiredCapabilities: $caps), $transport);
  $this->runtime->start('Isolated Items', 135, 36);
  $this->game->useRendererRuntime($this->runtime);
  itemMenuInventory($this->state, 30);
  $mode = $this->state->mode;
  if (!$supported) {
    expect($this->state->getPresentationCanvas())->toBeNull()->and($this->state->mode)->toBe($mode);
    expect(file_get_contents($this->root . '/error.log'))->toContain('Menu renderer lacks');
    return;
  }
  $before = $this->state->getPresentationCanvas();
  expect($before)->toBeInstanceOf(PresentationCanvas::class);
  $modal = new \Ichiloto\Engine\UI\Modal\AlertModal($this->game, 'Used item.', '');
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $this->alerts);
  $this->alerts->open($modal);
  $during = $this->state->getPresentationCanvas();
  expect($during)->toBeInstanceOf(PresentationCanvas::class)
    ->and(itemMenuText($during))->toContain('Used item.', 'Item 000');
  $modal->hide();
  expect($this->state->getPresentationCanvas())->toEqual($before);
})->with([true, false]);
