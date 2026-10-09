<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\PurchaseConfirmationMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\SelectShopMenuCommandMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopInventorySelectionMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopMerchandiseSelectionMode;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\EquipmentIcon;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Notification;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationCanvasPresentation;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationPlacement;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\ShopMenuPresentation;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

class ShopPresentationGameProbe extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

function shopPresentationText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(static fn($layer) => implode('', array_column($layer->runs, 'text')), $canvas->textLayers));
}

function shopPresentationPress(ShopState $state, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->execute();
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, InputManager::class, ActionHints::class, ConfigStore::class, Debug::class,
    ModalManager::class, AudioManager::class, NotificationManager::class, Time::class, EventManager::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-shop-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root]);
  Console::setTerminalOutputEnabled(false);
  Console::enterAlternateScreen();
  Console::syncDimensions(135, 36);
  putSceneAudioConfig(['vocab' => ['currency' => ['symbol' => 'G', 'name' => 'Gold']]]);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  $this->game = new ShopPresentationGameProbe();
  $audio = new class extends AudioManager {
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void {}
  };
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $audio);
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty($manager, 'game')->setValue($manager, $this->game);
  $this->scene = makeBareScene(GameScene::class);
  new ReflectionProperty($this->scene, 'sceneManager')->setValue($this->scene, $manager);
  $ui = makeBareScene(UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  $this->party = new Party();
  $this->party->credit(1400);
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, $this->party);
  $this->state = new ShopState(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $this->state);
  $this->item = new Item('Potion', 'Restore health.', '/', 101, quantity: 1, id: 'item.potion');
  $this->state->merchandise = [$this->item];
  InputManager::setBindings([
    'confirm' => ['keys' => [KeyCode::ENTER]], 'back' => ['keys' => [KeyCode::ESCAPE]],
    'cancel' => ['keys' => [KeyCode::C]], 'info' => ['keys' => [KeyCode::I]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]],
  ]);
  $this->runtime = null;
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
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
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('skins all existing shop panels through independent themes without changing their terminal content', function () {
  $before = [$this->state->mainPanel->getContent(), $this->state->detailPanel->getContent(), $this->party->accountBalance];
  $themes = [$this->theme, new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'colors' => ['panel' => [244, 237, 217], 'text' => [30, 35, 50]],
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 32, 'panelPadding' => 16]])];
  foreach ($themes as $theme) {
    $canvas = ShopMenuPresentation::compose($this->state, $theme);
    expect($canvas)->toBeInstanceOf(PresentationCanvas::class);
    expect(shopPresentationText($canvas))->toContain('Buy', 'Sell', 'Cancel', 'Gold', '1,400 G', 'Buy items from the shop.')
      ->not->toContain('Possession', 'Potion');
    foreach ([...$canvas->textLayers, ...$canvas->images] as $layer) {
      expect($layer->id)->not->toContain('-cursor');
    }
  }
  expect([$this->state->mainPanel->getContent(), $this->state->detailPanel->getContent(), $this->party->accountBalance])->toBe($before);
});

it('projects the live purchase prices possessions and description without owning inventory or selection', function () {
  $this->party->addItems(clone $this->item);
  $this->state->traderBuyRate = 1.25;
  shopPresentationPress($this->state, KeyCode::ENTER);
  $mode = $this->state->mode;
  $canvas = ShopMenuPresentation::compose($this->state, $this->theme);
  expect($mode)->toBeInstanceOf(ShopMerchandiseSelectionMode::class)
    ->and(shopPresentationText($canvas))->toContain('Potion', '126.25 G', 'Possession', 'Restore health.')
    ->and($this->state->detailPanel->possession)->toBe(1)
    ->and($this->party->accountBalance)->toBe(1400)
    ->and($this->state->mode)->toBe($mode)
    ->and($this->item->quantity)->toBe(1);
  $layers = array_column($canvas->textLayers, null, 'id');
  $row = $layers['shop-items-item-0-text'];
  expect($row->runs[1]->column)->toBeGreaterThan($row->runs[0]->column)
    ->and($row->runs[0]->text)->toBe('Potion')->and($row->runs[1]->text)->toBe('126.25 G');
});

it('shows only eligible owned items in Sell with the shop sale rate', function () {
  $key = new Item('Key', 'Do not sell.', '/', 20, isKeyItem: true);
  $locked = new Item('Issued', 'Do not sell.', '/', 20, sellable: false);
  $held = clone $this->item;
  $held->quantity = 3;
  $this->party->addItems($key, $locked, $held);
  $this->state->shopMenu->setActiveItemByLabel('Sell');
  shopPresentationPress($this->state, KeyCode::ENTER);
  expect($this->state->mode)->toBeInstanceOf(ShopInventorySelectionMode::class)
    ->and(shopPresentationText(ShopMenuPresentation::compose($this->state, $this->theme)))
    ->toContain('Potion', '50.5 G', 'Possession', '3')->not->toContain('Issued', 'Do not sell.');
});

it('uses one equipment type icon in buying selling checkout and native terminal content', function (bool $selling) {
  writeTestPng($this->root . '/Sword.png', 18, 24);
  writeTestPng($this->root . '/Shield.png', 18, 24);
  $this->theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'icons' => ['weapon.sword' => 'Sword.png', 'slot.shield' => 'Shield.png']]);
  $gear = [new Weapon('First weapon', '', 'OLD-A', 5, equipmentType: WeaponType::SWORD, id: 'weapon.first'),
    new Weapon('Second weapon', '', 'OLD-B', 10, equipmentType: WeaponType::SWORD, id: 'weapon.second'),
    new Armor('First shield', '', 'OLD-C', 5, equipmentType: ArmorType::GENERAL_ARMOR,
      semanticSlot: EquipmentSlotType::SHIELD, id: 'shield.first'),
    new Armor('Second shield', '', 'OLD-D', 10, equipmentType: ArmorType::MAGIC_ARMOR,
      semanticSlot: EquipmentSlotType::SHIELD, id: 'shield.second')];
  $this->state->merchandise = $gear;
  if ($selling) {
    $this->party->addItems(...$gear);
    $this->state->shopMenu->setActiveItemByLabel('Sell');
  }
  shopPresentationPress($this->state, KeyCode::ENTER);
  $canvas = ShopMenuPresentation::compose($this->state, $this->theme);
  $images = array_column($canvas->images, null, 'id');
  foreach (['Sword.png', 'Sword.png', 'Shield.png', 'Shield.png'] as $index => $asset) {
    $parts = array_filter($images, fn($image) => str_starts_with($image->id, 'shop-items-item-' . $index . '-icon-'));
    expect($parts)->not->toBeEmpty()->and(array_unique(array_column($parts, 'asset')))->toBe([$asset]);
  }
  $terminal = implode('', $this->state->mainPanel->getContent());
  expect($terminal)->toContain(EquipmentIcon::getTerminalGlyph(WeaponType::SWORD) . ' First weapon',
    EquipmentIcon::getTerminalGlyph(WeaponType::SWORD) . ' Second weapon',
    EquipmentIcon::getTerminalGlyph(EquipmentSlotType::SHIELD) . ' First shield',
    EquipmentIcon::getTerminalGlyph(EquipmentSlotType::SHIELD) . ' Second shield')->not->toContain('OLD-A', 'OLD-B', 'OLD-C', 'OLD-D');
  expect(implode('', Console::snapshot()->rows))->toContain('First weapon', 'Second weapon')->not->toContain('OLD-A', 'OLD-B');
  shopPresentationPress($this->state, KeyCode::ENTER);
  $checkout = ShopMenuPresentation::compose($this->state, $this->theme);
  $parts = array_filter($checkout->images, fn($image) => str_starts_with($image->id, 'shop-checkout-item-icon-'));
  expect($parts)->not->toBeEmpty()->and(array_values(array_unique(array_column($parts, 'asset'))))->toBe(['Sword.png'])
    ->and(implode('', $this->state->mainPanel->getContent()))
    ->toContain(EquipmentIcon::getTerminalGlyph(WeaponType::SWORD))->not->toContain('OLD-A', 'OLD-B');
})->with([false, true]);

it('measures checkout wrapping with the equipment icon gutter before placing quantity controls', function () {
  $this->state->merchandise = [new Weapon(str_repeat('Long equipment name ', 4), '', 'OLD', 5, equipmentType: WeaponType::SWORD)];
  shopPresentationPress($this->state, KeyCode::ENTER);
  shopPresentationPress($this->state, KeyCode::ENTER);
  $canvas = ShopMenuPresentation::compose($this->state, $this->theme);
  $layers = array_column($canvas->textLayers, null, 'id');
  $identity = $layers['shop-checkout-item-text'];
  $quantity = $layers['shop-checkout-quantity-text'];
  expect($identity->grid->rows)->toBeGreaterThan(1)
    ->and($quantity->bounds->y)->toBeGreaterThanOrEqual($identity->bounds->y + $identity->bounds->height);
});

it('keeps every selected stock record visible on a long graphical list without truncation', function () {
  $items = [];
  foreach (range(0, 69) as $index) {
    $items[] = new Item(sprintf('Stock %03d', $index), 'Description.', '/', $index + 1, id: 'stock.' . $index);
  }
  $this->state->merchandise = $items;
  $this->state->setMode(new ShopMerchandiseSelectionMode($this->state));
  foreach (range(0, 69) as $index) {
    $this->state->mainPanel->activeItemIndex = $index;
    $canvas = ShopMenuPresentation::compose($this->state, $this->theme);
    expect(shopPresentationText($canvas))->toContain(sprintf('Stock %03d', $index), '/ 70');
    foreach ($canvas->textLayers as $layer) { $layer->bounds->assertWithin($canvas->width, $canvas->height); }
  }
  expect($this->state->mainPanel->items)->toBe($items)->and($this->state->mainPanel->activeItemIndex)->toBe(69);
});

it('shows the quantity and exact rounded transaction total for purchases and sales', function (bool $selling) {
  $mode = new PurchaseConfirmationMode($this->state);
  $mode->previousMode = $selling ? new ShopInventorySelectionMode($this->state) : new ShopMerchandiseSelectionMode($this->state);
  $mode->item = $this->item;
  $this->state->setMode($mode);
  $mode->quantity = 3;
  $expected = $selling ? 152 : 303;
  expect($mode->totalPrice)->toBe($expected)
    ->and(shopPresentationText(ShopMenuPresentation::compose($this->state, $this->theme)))
    ->toContain('Potion', 'Quantity', 'Total', $expected . ' G')
    ->and($this->party->accountBalance)->toBe(1400);
})->with([false, true]);

it('shows bounded quantity chevrons beside aligned values using the live shop limits', function (bool $alternateTheme) {
  if ($alternateTheme) {
    writeTestPng($this->root . '/Chevron.png', 24, 24);
    $this->theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
      'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 32, 'panelPadding' => 16],
      'icons' => ['navigation.up' => 'Chevron.png', 'navigation.down' => 'Chevron.png']]);
  }
  shopPresentationPress($this->state, KeyCode::ENTER);
  shopPresentationPress($this->state, KeyCode::ENTER);
  $mode = $this->state->mode;
  $terminal = $this->state->mainPanel->getContent();
  $assertArrows = function (float $up, float $down) use ($alternateTheme): void {
    $canvas = ShopMenuPresentation::compose($this->state, $this->theme);
    $arrows = array_column($alternateTheme ? $canvas->images : $canvas->textLayers, null, 'id');
    $suffix = $alternateTheme ? '-1-1' : '';
    expect($arrows['shop-quantity-up' . $suffix]->opacity)->toBe($up)->and($arrows['shop-quantity-down' . $suffix]->opacity)->toBe($down);
    $layers = array_column($canvas->textLayers, null, 'id');
    $quantity = $layers['shop-checkout-quantity-text'];
    $total = $layers['shop-checkout-total-text'];
    expect($quantity->runs[0]->text)->toBe('Quantity')->and($total->runs[0]->text)->toBe('Total');
    $right = static fn($layer) => $layer->x + ($layer->runs[1]->column + mb_strlen($layer->runs[1]->text)) * $layer->grid->cellWidth;
    expect($right($quantity))->toBe($right($total));
    foreach (['shop-quantity-up', 'shop-quantity-down'] as $id) {
      $arrow = $arrows[$id . $suffix];
      $bounds = $alternateTheme ? $arrow->destination : $arrow->bounds;
      expect($bounds->x)->toBeGreaterThan($right($quantity));
      $bounds->assertWithin($canvas->width, $canvas->height);
    }
  };
  $assertArrows(1.0, 0.35);
  expect($this->state->mainPanel->getContent())->toBe($terminal);
  shopPresentationPress($this->state, KeyCode::UP);
  expect($mode->quantity)->toBe(2);
  $assertArrows(1.0, 1.0);
  $mode->quantity = 13; // The next Potion would exceed the 1,400 G balance.
  $assertArrows(0.35, 1.0);
  shopPresentationPress($this->state, KeyCode::UP);
  expect($mode->quantity)->toBe(13);
  $mode->quantity = 99;
  $assertArrows(0.35, 1.0);
  $mode->quantity = 2;
  $this->state->detailPanel->possession = 97;
  $assertArrows(0.35, 1.0);
  $mode->previousMode = new ShopInventorySelectionMode($this->state);
  $this->state->detailPanel->possession = 2;
  $assertArrows(0.35, 1.0);
})->with([false, true]);

it('removes content avoidance and overlays achievements above the unchanged live shop until dismissal', function (bool $noticeBeforeMenu) {
  mkdir($this->root . '/Data/Presentation', 0777, true);
  writeTestPng($this->root . '/Panel.png', 48, 48);
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE, '<?php return ' . var_export([
    'schema' => 'ichiloto.menu/1', 'showInputHints' => false, 'frames' => ['panel' => ['asset' => 'Panel.png']],
  ], true) . ';');
  $caps = [...MenuPresentationCatalog::CAPABILITIES, RendererSessionConfig::CANVAS_OVERLAY];
  $transport = new FakeRendererTransport();
  $transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $this->root,
    cellWidth: 10, cellHeight: 20, requiredCapabilities: $caps), $transport);
  $this->runtime->start('Isolated Shop notification', 135, 36);
  $this->game->useRendererRuntime($this->runtime);
  Time::setElapsedTime(0);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(NotificationManager::class, 'instance')->setValue(null, null);
  $notifications = NotificationManager::getInstance($this->game);
  $notice = new Notification($this->game, NotificationChannel::ACHIEVEMENT, 'First purchase', 'Achievement unlocked',
    1.0, animationDuration: 0);
  $notifications->notify($notice);
  $fieldBounds = null;
  if ($noticeBeforeMenu) {
    $this->runtime->present(null, $notifications);
    $frames = RetainedFrameState::replay($transport->sent);
    $fieldLayers = array_column(end($frames)['canvas']['textLayers'], null, 'id');
    $fieldBounds = $fieldLayers[$notice->getPresentationId() . '-title']['clipRect'];
  }
  shopPresentationPress($this->state, KeyCode::ENTER);
  shopPresentationPress($this->state, KeyCode::ENTER);
  $this->state->mode->quantity = 5;
  $this->runtime->present($this->scene, $notifications);
  $frames = RetainedFrameState::replay($transport->sent);
  $canvas = end($frames)['canvas'];
  $text = implode('', array_merge(...array_map(static fn($layer) => array_column($layer['runs'], 'text'), $canvas['textLayers'])));
  expect($text)->toContain('First purchase', 'Achievement unlocked', 'Quantity', '505 G', 'Possession');
  $base = $this->state->getPresentationCanvas();
  $panels = array_column($canvas['images'], null, 'id');
  $toast = $panels[$notice->getPresentationId() . '-frame-1-1'];
  $layers = array_column($canvas['textLayers'], null, 'id');
  $toastBounds = new CanvasRectangle(...array_values($layers[$notice->getPresentationId() . '-title']['clipRect']));
  expect($toastBounds->x)->toBe(882.0)->and($toastBounds->y)->toBe(24.0)
    ->and($toast['layer'])->toBeGreaterThan(max([...array_column($base->images, 'layer'), ...array_column($base->textLayers, 'layer')]))
    ->and($notice->getPresentationOpacity())->toBe(1.0);
  if ($noticeBeforeMenu) { expect($layers[$notice->getPresentationId() . '-title']['clipRect'])->toBe($fieldBounds); }
  foreach ($base->textLayers as $layer) {
    if (str_starts_with($layer->id, 'shop-checkout-') || str_starts_with($layer->id, 'shop-commands-')
      || $layer->id === 'shop-details-possession-text' || $layer->id === 'shop-balance-balance-text') {
      expect($layers[$layer->id]['origin'])->toBe(['x' => $layer->x, 'y' => $layer->y])
        ->and($layers[$layer->id]['runs'])->toBe(array_map(static fn($run) => $run->toArray(), $layer->runs));
    }
  }
  Time::setElapsedTime(1.01);
  $notifications->update();
  $this->runtime->present($this->scene, $notifications);
  $frames = RetainedFrameState::replay($transport->sent);
  expect(array_column(end($frames)['canvas']['images'], 'id'))->not->toContain($notice->getPresentationId() . '-frame-1-1')
    ->and($this->state->mode->quantity)->toBe(5)->and($this->party->accountBalance)->toBe(1400);
})->with([false, true]);

it('removes notification space reservation without changing shop proportions or information paging', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 32, 'panelPadding' => 16],
    'notifications' => ['width' => 400, 'maxWidth' => 480, 'margin' => 32, 'padding' => 20]]);
  $notice = new Notification($this->game, NotificationChannel::ACHIEVEMENT, 'Achievement unlocked', 'Errand Runner', animationDuration: 0);
  $notice->open();
  foreach ([$this->theme, $theme] as $skin) {
    $anchor = NotificationCanvasPresentation::compose($notice, $skin, 1350, 720)->bounds;
    for ($mode = 0; $mode < 3; $mode++) {
      $description = str_repeat('Complete description with values 500 G and S-Mana x2. ', 8);
      $this->state->infoPanel->setText($description);
      $base = ShopMenuPresentation::compose($this->state, $skin);
      $areas = NotificationPlacement::getProtectedAreas($base, null, [], new RendererGridConfig(135, 36, 10, 20));
      $surface = NotificationCanvasPresentation::compose($notice, $skin, 1350, 720, $areas);
      expect($surface)->not->toBeNull()->and($surface->bounds)->toEqual($anchor)
        ->and(shopPresentationText($base))->toContain('Complete description', '500 G', 'S-Mana x2', 'Lines 1-2 /');
      $header = \Ichiloto\Engine\UI\Presentation\MenuInfoPanel::getHeaderBounds($skin,
        \Ichiloto\Engine\UI\Presentation\MenuLayout::getBounds());
      expect($header->height)->toBe((float)\Ichiloto\Engine\UI\Presentation\MenuInfoPanel::getHeight($skin))
        ->and($header->width)->toBe(1100.0);
      $this->state->menuInfoText->advance();
      $page = ShopMenuPresentation::compose($this->state, $skin);
      expect(shopPresentationText($page))->toContain('Lines 3-4 /');
      $next = NotificationCanvasPresentation::compose($notice, $skin, 1350, 720,
        NotificationPlacement::getProtectedAreas($page, null, [], new RendererGridConfig(135, 36, 10, 20)));
      expect($next->bounds)->toEqual($anchor);
      if ($mode < 2) { shopPresentationPress($this->state, KeyCode::ENTER); }
    }
    shopPresentationPress($this->state, KeyCode::C);
    shopPresentationPress($this->state, KeyCode::ESCAPE);
  }
});

it('removes long notices from both field and shop toast surfaces instead of growing or relocating them', function () {
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', "First\nSecond\nThird\nFourth", animationDuration: 0);
  $notice->open();
  $base = ShopMenuPresentation::compose($this->state, $this->theme);
  $areas = NotificationPlacement::getProtectedAreas($base, null, [], new RendererGridConfig(135, 36, 10, 20));
  expect(fn() => NotificationCanvasPresentation::compose($notice, $this->theme, 1350, 720))
    ->toThrow(\Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationContentOverflow::class)
    ->and(fn() => NotificationCanvasPresentation::compose($notice, $this->theme, 1350, 720, $areas))
    ->toThrow(\Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationContentOverflow::class)
    ->and($notice->getContentText())->toBe("First\nSecond\nThird\nFourth");
});

it('keeps checkout and cancellation under the existing mode and transaction owners', function () {
  shopPresentationPress($this->state, KeyCode::ENTER);
  shopPresentationPress($this->state, KeyCode::ENTER);
  expect($this->state->mode)->toBeInstanceOf(PurchaseConfirmationMode::class);
  $this->state->mode->quantity = 2;
  shopPresentationPress($this->state, KeyCode::C);
  expect($this->state->mode)->toBeInstanceOf(ShopMerchandiseSelectionMode::class)
    ->and($this->party->accountBalance)->toBe(1400)->and($this->party->inventory->all->count())->toBe(0);
  shopPresentationPress($this->state, KeyCode::ENTER);
  $this->state->mode->quantity = 2;
  shopPresentationPress($this->state, KeyCode::ENTER);
  expect($this->state->mode)->toBeInstanceOf(ShopMerchandiseSelectionMode::class)
    ->and($this->party->accountBalance)->toBe(1198)->and($this->state->detailPanel->possession)->toBe(2)
    ->and(shopPresentationText(ShopMenuPresentation::compose($this->state, $this->theme)))->toContain('1,198 G');
  shopPresentationPress($this->state, KeyCode::ESCAPE);
  expect(shopPresentationText(ShopMenuPresentation::compose($this->state, $this->theme)))->not->toContain('Possession');
});

it('keeps long descriptions reachable through semantic Info and resets reading on a mode change', function () {
  $description = "First\nSecond\nThird\nFourth";
  $this->state->infoPanel->setText($description);
  expect(shopPresentationText(ShopMenuPresentation::compose($this->state, $this->theme)))
    ->toContain('First', 'Second', 'Lines 1-2 / 4')->not->toContain('Third');
  shopPresentationPress($this->state, KeyCode::I);
  expect(shopPresentationText(ShopMenuPresentation::compose($this->state, $this->theme)))
    ->toContain('Third', 'Fourth', 'Lines 3-4 / 4');
  shopPresentationPress($this->state, KeyCode::ENTER);
  expect(shopPresentationText(ShopMenuPresentation::compose($this->state, $this->theme)))->toContain('Restore health.');
});

it('returns safely to an empty sale list after selling the final eligible stack', function () {
  $held = clone $this->item;
  $this->party->addItems($held);
  $this->state->shopMenu->setActiveItemByLabel('Sell');
  shopPresentationPress($this->state, KeyCode::ENTER);
  shopPresentationPress($this->state, KeyCode::ENTER);
  shopPresentationPress($this->state, KeyCode::ENTER);
  expect($this->state->mode)->toBeInstanceOf(ShopInventorySelectionMode::class)
    ->and($this->state->mainPanel->activeItem)->toBeNull()
    ->and($this->state->sellableItems)->toBe([])->and($this->party->accountBalance)->toBe(1451)
    ->and(shopPresentationText(ShopMenuPresentation::compose($this->state, $this->theme)))
    ->toContain('1,451 G')->not->toContain('Potion', 'Possession');
});

it('retains the terminal shop when no graphical renderer is selected', function () {
  expect($this->state)->toBeInstanceOf(CanvasProviderInterface::class)
    ->and($this->state->getPresentationCanvas())->toBeNull();
  shopPresentationPress($this->state, KeyCode::ENTER);
  expect(implode('', $this->state->mainPanel->getContent()))->toContain('Potion', '101 G');
  shopPresentationPress($this->state, KeyCode::ENTER);
  expect(implode('', $this->state->mainPanel->getContent()))->toContain('Potion', 'x', '101 G');
});

it('uses the shared renderer lifecycle and preserves the shop beneath supported alerts', function (bool $supported) {
  mkdir($this->root . '/Data/Presentation', 0777, true);
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE,
    '<?php return ' . var_export(['schema' => 'ichiloto.menu/1', 'showInputHints' => false], true) . ';');
  $caps = $supported ? MenuPresentationCatalog::CAPABILITIES : [];
  $transport = new FakeRendererTransport();
  $transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $this->root,
    requiredCapabilities: $caps), $transport);
  $this->runtime->start('Isolated Shop', 135, 36);
  $this->game->useRendererRuntime($this->runtime);
  $mode = $this->state->mode;
  if (!$supported) {
    expect($this->state->getPresentationCanvas())->toBeNull()->and($this->state->mode)->toBe($mode)
      ->and(file_get_contents($this->root . '/error.log'))->toContain('Menu renderer lacks');
    return;
  }
  $before = $this->state->getPresentationCanvas();
  $manager = ModalManager::getInstance($this->game);
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $manager);
  $modal = new AlertModal($this->game, 'Not enough Gold!', '');
  $manager->open($modal);
  expect($this->state->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class)
    ->and(shopPresentationText($this->state->getPresentationCanvas()))->toContain('Buy', 'Sell', '1,400 G', 'Not enough Gold!');
  $modal->hide();
  expect($this->state->getPresentationCanvas())->toEqual($before);
})->with([true, false]);
