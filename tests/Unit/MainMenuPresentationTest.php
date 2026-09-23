<?php

declare(strict_types=1);

use Assegai\Collections\ItemList;
use Assegai\Collections\Stack;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Menu\Commands\OpenSummonsMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenQuestsMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenRecordsMenuCommand;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuItemInterface;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuCharacterSelectionMode;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuCommandSelectionMode;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuConfigMode;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuPartyOrderMode;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ActionHintProvider;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\SaveMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuLayout;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowLayout;
use Ichiloto\Engine\UI\Presentation\MenuRowPainter;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

class MainMenuPresentationGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

class MainMenuPresentationProbe extends MainMenuState
{
  public bool $saveAllowed = true;
  protected bool $canSave { get => $this->saveAllowed; }
  public function canvas(MenuPresentationCatalog $theme, float $time = 0): ?PresentationCanvas { return $this->composeMenuCanvas($theme, $time); }
}

function mainMenuPresentationPng(string $path, int $width, int $height): void
{
  $chunk = static fn(string $type, string $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xAD\xBE\xCF\xFF", $width), $height))) . $chunk('IEND', ''));
}

function mainMenuPresentationTheme(bool $alternative): array
{
  $theme = ['schema' => 'ichiloto.menu/1', 'icons' => ['unknown' => 'cursor.png'], 'cursor' => 'cursor.png',
    'portraits' => ['actor.0' => 'portrait.png', 'actor.1' => 'portrait.png', 'actor.2' => 'portrait.png', 'actor.3' => 'portrait.png']];
  if (!$alternative) { return $theme; }
  $art = ['asset' => 'surface.png', 'cuts' => [3, 4, 3, 4]];
  return [...$theme, 'colors' => ['text' => [43, 30, 10], 'background' => [237, 230, 210], 'panel' => [245, 235, 225]],
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34, 'panelPadding' => 16, 'sectionGap' => 8, 'portraitSize' => 88],
    'rowMetrics' => ['padding' => 24, 'iconWidth' => 32, 'iconHeight' => 12, 'cursorWidth' => 10, 'cursorHeight' => 20,
      'cursorInset' => 6, 'cursorTravel' => 3, 'cursorPeriod' => 2],
    'rowArtwork' => ['normal' => $art, 'selected' => $art, 'focus' => ['asset' => 'focus.png'],
      'command.selected' => $art, 'command.focus' => ['asset' => 'focus.png']],
    'frames' => ['panel' => $art, 'quiet' => $art, 'portrait' => $art]];
}

function mainMenuPresentationText(PresentationCanvas $frame): array
{
  $text = [];
  foreach ($frame->textLayers as $layer) {
    $runs = array_column(array_filter($layer->runs, fn($run) => $run->foreground !== null), 'text');
    if ($runs !== []) { $text[$layer->id] = implode('', $runs); }
  }
  return $text;
}

function mainMenuPresentationFrameBounds(PresentationCanvas $frame, string $id): CanvasRectangle
{
  $layers = array_column($frame->textLayers, null, 'id');
  if (isset($layers[$id])) { return $layers[$id]->clipRect; }
  $pieces = array_filter($frame->images, fn($image) => str_starts_with($image->id, $id . '-'));
  $boxes = array_column($pieces, 'destination');
  $x = min(array_column($boxes, 'x'));
  $y = min(array_column($boxes, 'y'));
  return new CanvasRectangle($x, $y,
    max(array_map(fn($box) => $box->x + $box->width, $boxes)) - $x,
    max(array_map(fn($box) => $box->y + $box->height, $boxes)) - $y);
}

function mainMenuPresentationRuntime(string $root, array $caps): RendererRuntime
{
  $transport = new FakeRendererTransport();
  $transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $root,
    requiredCapabilities: $caps), $transport);
  $runtime->start('Main Menu test', 135, 36);
  return $runtime;
}

function mainMenuPresentationKey(KeyCode $key, MainMenuState $state): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->getPresentationMode()->update();
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, InputManager::class, ActionHints::class, ConfigStore::class, Debug::class, ModalManager::class, AudioManager::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-main-menu-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/Data/Presentation', 0777, true);
  foreach (['portrait', 'cursor', 'surface', 'focus'] as $name) { mainMenuPresentationPng($this->root . '/' . $name . '.png', 73, 41); }
  Debug::configure(['log_directory' => $this->root . '/logs']);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(\Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog::class, new SceneAudioConfigStub());
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['vocab' => ['currency' => ['name' => 'Tokens', 'symbol' => 'T']]]));
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  $this->game = new MainMenuPresentationGame();
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, null);
  $audio = new class extends AudioManager {
    public function __construct() {}
    public function playSystemSound(\Ichiloto\Engine\Audio\Enumerations\SystemSound $sound): void {}
  };
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty($manager, 'game')->setValue($manager, $this->game);
  $this->scene = makeBareScene(GameScene::class);
  new ReflectionProperty($this->scene, 'sceneManager')->setValue($this->scene, $manager);
  new ReflectionProperty($this->scene, 'gameState')->setValue($this->scene, new GameState());
  $ui = makeBareScene(\Ichiloto\Engine\UI\UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  $this->party = new Party();
  $this->party->location = new PartyLocation('Owner Location', 'Owner Region');
  $this->party->accountBalance = 4321;
  for ($i = 0; $i < 4; $i++) {
    $this->party->addMember(new Character('Actor ' . $i, 0, new Stats(totalHp: 901 + $i, totalMp: 123 + $i), actorId: 'actor.' . $i));
  }
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, $this->party);
  $this->state = new MainMenuPresentationProbe(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $this->state);
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'cancel' => ['keys' => [KeyCode::C]]]);
  $this->state->enter();
  $this->runtime = null;
  ob_start();
});

afterEach(function () {
  $this->runtime?->shutdown();
  ob_end_clean();
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('projects all current fields and commands through two themes with independent unique portraits', function () {
  $frames = [];
  foreach ([false, true] as $alternative) {
    $frame = $this->state->canvas(new MenuPresentationCatalog($this->root, mainMenuPresentationTheme($alternative)));
    $frames[] = $frame;
    $text = mainMenuPresentationText($frame);
    $layers = array_column($frame->textLayers, null, 'id');
    foreach ($this->state->mainMenu->getItems() as $index => $command) {
      $label = $layers['main-command-' . $index . '-text'];
      expect($text[$label->id])->toBe($command->getLabel())
        ->and($label->x + $label->grid->columns * $label->grid->cellWidth / 2)->toBe($label->clipRect->x + $label->clipRect->width / 2);
    }
    expect($text['main-info-value'])->toBe($this->state->mainMenu->getActiveItem()->getDescription())
      ->and($text['main-money-title'])->toBe('Tokens')->and($text['main-money-value'])->toBe('4,321 T')
      ->and($text['main-location-value'])->toBe('Owner LocationOwner Region')
      ->and($text['main-time-value'])->toBe($this->state->getPresentationSummaries()['time']['lines'][0]);
    foreach ($this->party->members as $index => $member) {
      expect($text['main-party-' . $index . '-identity-text'])->toBe($member->name)
        ->and($text['main-party-' . $index . '-role'])->toBe('Role: ' . $member->role->name)
        ->and($text['main-party-' . $index . '-resources-hp-text'])->toBe('HP' . $member->effectiveStats->currentHp . ' / ' . $member->effectiveStats->totalHp)
        ->and($text['main-party-' . $index . '-resources-mp-text'])->toBe('MP' . $member->effectiveStats->currentMp . ' / ' . $member->effectiveStats->totalMp);
    }
    $ids = [...array_column($frame->images, 'id'), ...array_column($frame->textLayers, 'id')];
    expect(count(array_unique($ids)))->toBe(count($ids))
      ->and(count(array_filter($frame->images, fn($image) => $image->asset === 'portrait.png')))->toBe(4)
      ->and($layers)->not->toHaveKey('main-command-0-separator');
    $rules = array_filter($frame->textLayers, fn($layer) => str_contains($layer->id, '-resources-') && str_ends_with($layer->id, '-separator'));
    expect(array_sum(array_map(fn($layer) => count($layer->runs), $rules)))->toBe(12);
    foreach ($frame->images as $image) { $image->destination->assertWithin(1350, 720); }
  }
  expect(mainMenuPresentationText($frames[0]))->toBe(mainMenuPresentationText($frames[1]));
});

it('centers the main menu on both axes in the same envelope as other menus', function (bool $alternative) {
  $data = mainMenuPresentationTheme($alternative);
  $data['frames'] ??= array_fill_keys(['panel', 'quiet'], ['asset' => 'surface.png', 'cuts' => [3, 4, 3, 4]]);
  $frame = $this->state->canvas(new MenuPresentationCatalog($this->root, $data));
  $header = mainMenuPresentationFrameBounds($frame, 'main-info');
  $location = mainMenuPresentationFrameBounds($frame, 'main-location');
  $help = mainMenuPresentationFrameBounds($frame, 'main-help');
  $host = MenuLayout::getBounds($frame->width, $frame->height);
  expect($header->x)->toBe($host->x)->toBe(($frame->width - $header->width) / 2)
    ->and($header->y)->toBe($host->y)
    ->and($location->x)->toBe($header->x)
    ->and($help->x + $help->width)->toBe($header->x + $header->width)
    ->and($help->y + $help->height)->toBe($frame->height - $header->y);
})->with([false, true]);

it('preserves unoccupied frames and supports absent portrait mappings', function (int $count) {
  $members = array_slice($this->party->members->toArray(), 0, $count);
  new ReflectionProperty($this->party, 'members')->setValue($this->party, new ItemList(\Ichiloto\Engine\Entities\Interfaces\CharacterInterface::class, $members));
  $this->state->characterSelectionMenu->refreshMembers();
  $frame = $this->state->canvas(new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']));
  $layers = array_column($frame->textLayers, null, 'id');
  expect($frame->images)->toBeEmpty();
  for ($i = 0; $i < 4; $i++) {
    expect($layers)->toHaveKey('main-party-' . $i . '-frame');
    if ($i >= $count) { expect($layers)->not->toHaveKey('main-party-' . $i . '-identity-text')->not->toHaveKey('main-party-' . $i . '-identity-separator'); }
  }
})->with([0, 1]);

it('shares the bottom row with Location and meets the fourth character card with or without hints', function (bool $alternative, bool $hints) {
  $data = mainMenuPresentationTheme($alternative);
  $data['showInputHints'] = $hints;
  $data['frames'] ??= ['panel' => ['asset' => 'surface.png', 'cuts' => [3, 4, 3, 4]]];
  $theme = new MenuPresentationCatalog($this->root, $data);
  foreach ([4, 1] as $count) {
    $members = array_slice($this->party->members->toArray(), 0, $count);
    new ReflectionProperty($this->party, 'members')->setValue($this->party,
      new ItemList(\Ichiloto\Engine\Entities\Interfaces\CharacterInterface::class, $members));
    $this->state->characterSelectionMenu->refreshMembers();
    $frame = $this->state->canvas($theme);
    $location = mainMenuPresentationFrameBounds($frame, 'main-location');
    $help = mainMenuPresentationFrameBounds($frame, 'main-help');
    $lastCard = mainMenuPresentationFrameBounds($frame, 'main-party-3-frame');
    expect($help->y)->toBe($location->y)->toBe($lastCard->y + $lastCard->height)
      ->and($help->height)->toBe($location->height)
      ->and($help->y + $help->height)->toBe($frame->height - MenuLayout::getBounds()->y);
  }
})->with([false, true])->with([false, true]);

it('grows both bottom panels together when either contains wrapped content', function (bool $alternative, bool $hints, string $taller) {
  $data = mainMenuPresentationTheme($alternative);
  $data['showInputHints'] = $hints;
  $data['frames'] ??= ['panel' => ['asset' => 'surface.png', 'cuts' => [3, 4, 3, 4]]];
  $theme = new MenuPresentationCatalog($this->root, $data);
  if ($taller === 'location') {
    new ReflectionProperty($this->state, 'locationDetailPanel')->getValue($this->state)
      ->setLocation(str_repeat('Extended location name ', 6), 'Owner Region');
  }
  else { $this->state->characterSelectionMenu->setHelpText(str_repeat('Extended help message ', 20)); }
  $frame = $this->state->canvas($theme);
  $location = mainMenuPresentationFrameBounds($frame, 'main-location');
  $help = mainMenuPresentationFrameBounds($frame, 'main-help');
  expect($help->y)->toBe($location->y)
    ->and($help->height)->toBe($location->height)->toBeGreaterThan(80)
    ->and($help->y + $help->height)->toBe($frame->height - MenuLayout::getBounds()->y);
  $layers = array_column($frame->textLayers, null, 'id');
  foreach (['main-location-value' => $location, 'main-help-text' => $help] as $id => $box) {
    $text = $layers[$id];
    expect($text->y)->toBeGreaterThanOrEqual($box->y)
      ->and($text->y + $text->grid->rows * $theme->metrics->cellHeight)->toBeLessThanOrEqual($box->y + $box->height);
  }
})->with([false, true])->with([false, true])->with(['location', 'help']);

it('follows owner character focus across large parties without changing active or reserve membership', function (bool $alternative) {
  for ($i = 4; $i < 12; $i++) { $this->party->addMember(new Character('Actor ' . $i, 0, new Stats(), actorId: 'actor.' . $i)); }
  $before = $this->party->members->toArray();
  $battlers = $this->party->battlers->toArray();
  $this->state->setMode(new MainMenuCharacterSelectionMode($this->state));
  for ($i = 0; $i < 11; $i++) { $this->state->characterSelectionMenu->selectNext(); }
  $frame = $this->state->canvas(new MenuPresentationCatalog($this->root, mainMenuPresentationTheme($alternative)));
  expect(mainMenuPresentationText($frame)['main-party-11-identity-text'])->toBe('Actor 11')
    ->and(mainMenuPresentationText($frame)['main-party-range'])->toEndWith('/ 12')
    ->and($this->party->members->toArray())->toBe($before)->and($this->party->battlers->toArray())->toBe($battlers)
    ->and($this->state->characterSelectionMenu->getActivePanelIndex())->toBe(11);
  $this->state->characterSelectionMenu->selectNext();
  expect($this->state->characterSelectionMenu->getActivePanelIndex())->toBe(0);
})->with([false, true]);

it('highlights the focused character with the command selection treatment and clears it on return', function () {
  $theme = new MenuPresentationCatalog($this->root, mainMenuPresentationTheme(false));
  $menu = $this->state->mainMenu;
  $menu->setActiveItemByLabel('Status');
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  $this->state->characterSelectionMenu->selectNext();
  $frame = $this->state->canvas($theme);
  $layers = array_column($frame->textLayers, null, 'id');
  $character = $layers['main-party-1-identity-selected'];
  $command = $layers['main-command-' . $menu->activeIndex . '-selected'];
  expect($character->runs[0]->background)->toEqual($command->runs[0]->background)
    ->and($character->clipRect->height)->toBeGreaterThan(100)
    ->and($character->clipRect->width)->toBeGreaterThan(700)
    ->and($layers)->not->toHaveKey('main-party-0-identity-selected')
    ->and($this->state->characterSelectionMenu->getMarkedPanelIndex())->toBeNull();
  $portrait = array_values(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'main-party-1-portrait') && $image->asset === 'portrait.png'));
  expect($portrait)->toHaveCount(1)
    ->and($character->layer)->toBeLessThan($portrait[0]->layer);
  mainMenuPresentationKey(KeyCode::C, $this->state);
  $layers = array_column($this->state->canvas($theme)->textLayers, null, 'id');
  expect($this->state->getPresentationMode())->toBeInstanceOf(MainMenuCommandSelectionMode::class)
    ->and($layers)->not->toHaveKey('main-party-1-identity-selected')
    ->and($layers)->toHaveKey('main-command-' . $menu->activeIndex . '-selected');
});

it('fills the complete padded card interior without a name cursor or persistent helpers', function () {
  mainMenuPresentationPng($this->root . '/surface.png', 96, 96);
  $data = mainMenuPresentationTheme(false);
  $data['showInputHints'] = false;
  $data['frames'] = ['panel' => ['asset' => 'surface.png', 'cuts' => [24, 24, 24, 24], 'borderWidths' => [8, 8, 8, 8]]];
  $theme = new MenuPresentationCatalog($this->root, $data);
  $this->state->mainMenu->setActiveItemByLabel('Status');
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  $frame = $this->state->canvas($theme);
  $text = array_column($frame->textLayers, null, 'id');
  $images = array_column($frame->images, null, 'id');
  $fill = $text['main-party-0-identity-selected'];
  $name = $text['main-party-0-identity-text'];
  $card = mainMenuPresentationFrameBounds($frame, 'main-party-0-frame');
  expect($fill->clipRect->toArray())->toBe(['x' => $card->x + 8, 'y' => $card->y + 8,
    'width' => $card->width - 16, 'height' => $card->height - 16])
    ->and($fill->clipRect->y)->toBeLessThanOrEqual($name->y)
    ->and($images['main-party-0-frame-0-1-backing']->layer)->toBeLessThan($fill->layer)
    ->and($images['main-party-0-frame-0-1-border']->layer)->toBeGreaterThan($fill->layer)
    ->and($images['main-party-0-frame-0-0']->layer)->toBeGreaterThan($fill->layer)
    ->and(array_filter(array_keys($images), fn($id) => str_contains($id, '-identity-cursor')))->toBeEmpty()
    ->and($text)->not->toHaveKey('main-hints')
    ->and(mainMenuPresentationText($frame)['main-help-text'])->toBe('Select a character.')
    ->and(implode(' ', mainMenuPresentationText($frame)))->toContain('Controls');
});

it('keeps owner source mark destination focus and command selection independent while swapping party members', function (bool $alternative) {
  $theme = new MenuPresentationCatalog($this->root, mainMenuPresentationTheme($alternative));
  $menu = $this->state->mainMenu;
  $menu->setActiveItemByLabel('Order');
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  expect($this->state->getPresentationMode())->toBeInstanceOf(MainMenuPartyOrderMode::class);
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  $this->state->characterSelectionMenu->focusPanelByIndex(3);
  $before = $this->party->members->toArray();
  $frame = $this->state->canvas($theme);
  $ids = [...array_column($frame->images, 'id'), ...array_column($frame->textLayers, 'id')];
  foreach (['main-party-0-identity-selected', 'main-party-3-identity-selected', 'main-party-3-identity-focus', 'main-command-' . $menu->activeIndex . '-selected'] as $prefix) {
    expect(array_filter($ids, fn($id) => str_starts_with($id, $prefix)))->not->toBeEmpty();
  }
  expect(array_filter($ids, fn($id) => str_starts_with($id, 'main-party-0-identity-focus')))->toBeEmpty()
    ->and(array_filter($ids, fn($id) => str_starts_with($id, 'main-command-' . $menu->activeIndex . '-focus')))->toBeEmpty()
    ->and(mainMenuPresentationText($frame)['main-help-text'])->toBe($this->state->characterSelectionMenu->getHelpText());
  $name = array_column($frame->textLayers, null, 'id')['main-party-3-identity-text'];
  expect(array_filter($frame->images, fn($image) => str_contains($image->id, '-identity-cursor')))->toBeEmpty()
    ->and($this->party->members->toArray())->toBe($before);
  $layers = [...$frame->images, ...$frame->textLayers];
  foreach ([0, 3] as $index) {
    $prefix = 'main-party-' . $index;
    $treatments = array_filter($layers, fn($layer) => str_starts_with($layer->id, $prefix . '-identity-')
      && (str_contains($layer->id, '-selected') || str_contains($layer->id, '-focus') || str_contains($layer->id, '-normal')));
    $portrait = array_values(array_filter($frame->images, fn($image) => str_starts_with($image->id, $prefix . '-portrait-') && $image->asset === 'portrait.png'))[0];
    foreach ($treatments as $treatment) {
      expect($treatment->layer)->toBeLessThan($portrait->layer)->toBeLessThan($name->layer)
        ->toBeLessThan(array_column($frame->textLayers, null, 'id')[$prefix . '-resources-hp-text']->layer);
    }
    if ($alternative) {
      $border = array_values(array_filter($frame->images, fn($image) => $image->id === $prefix . '-frame-0-0'))[0];
      expect($border->layer)->toBeGreaterThan(max(array_column($treatments, 'layer')))
        ->toBeLessThan($portrait->layer)->toBeLessThan($name->layer);
      $portraitFrame = array_filter($frame->images, fn($image) => str_starts_with($image->id, $prefix . '-portrait-frame-'));
      expect($portraitFrame)->not->toBeEmpty();
      foreach ($portraitFrame as $piece) {
        expect($piece->layer)->toBeGreaterThan($border->layer)->toBeLessThan($portrait->layer);
      }
    }
  }
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  expect($this->party->members->toArray())->toBe([$before[3], $before[1], $before[2], $before[0]])
    ->and($this->party->battlers->toArray())->toBe([$before[3], $before[1], $before[2]])
    ->and($this->state->characterSelectionMenu->getMarkedPanelIndex())->toBeNull();
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  mainMenuPresentationKey(KeyCode::C, $this->state);
  expect($this->state->getPresentationMode())->toBeInstanceOf(MainMenuPartyOrderMode::class)
    ->and($this->state->characterSelectionMenu->getMarkedPanelIndex())->toBeNull();
  mainMenuPresentationKey(KeyCode::C, $this->state);
  expect($this->state->getPresentationMode())->toBeInstanceOf(MainMenuCommandSelectionMode::class)
    ->and($menu->getActiveItem()->getLabel())->toBe('Order');
})->with([false, true]);

it('preserves character selection and routes the real selected actor through the existing command', function () {
  $target = new class(new SceneStateContext($this->scene)) extends EquipmentMenuState {
    public function enter(): void {}
  };
  new ReflectionProperty($this->scene, 'equipmentMenuState')->setValue($this->scene, $target);
  $this->state->mainMenu->setActiveItemByLabel('Equipment');
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  $this->state->characterSelectionMenu->selectNext();
  $this->state->canvas(new MenuPresentationCatalog($this->root, mainMenuPresentationTheme(true)));
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  expect($target->character)->toBe($this->party->members->toArray()[1]);
});

it('renders all thirteen actual command variants centered and honors disabled owner actions', function () {
  $menu = $this->state->mainMenu;
  $commands = $menu->getItems()->toArray();
  array_splice($commands, 4, 0, [new OpenSummonsMenuCommand($menu), new OpenQuestsMenuCommand($menu), new OpenRecordsMenuCommand($menu)]);
  $menu->setItems(new ItemList(MenuItemInterface::class, $commands));
  $this->state->setMode(new MainMenuCommandSelectionMode($this->state));
  $menu->setActiveItemByLabel('Equipment');
  $menu->getActiveItem()->disable();
  foreach ([false, true] as $alternative) {
    $frame = $this->state->canvas(new MenuPresentationCatalog($this->root, mainMenuPresentationTheme($alternative)));
    $labels = array_filter(array_keys(mainMenuPresentationText($frame)), fn($id) => preg_match('/^main-command-\d+-text$/', $id));
    expect($labels)->toHaveCount(13);
    expect(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'main-command-' . $menu->activeIndex . '-cursor')))->toBeEmpty();
  }
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  expect($this->state->getPresentationMode())->toBeInstanceOf(MainMenuCommandSelectionMode::class);
});

it('wraps live labels and semantic hints without overlapping records or descriptions', function (bool $alternative) {
  $theme = new MenuPresentationCatalog($this->root, mainMenuPresentationTheme($alternative));
  $label = str_repeat('Long command ', 4);
  $this->state->mainMenu->getActiveItem()->setLabel($label);
  $description = str_repeat('Owner description ', 8);
  $this->state->infoPanel->setText($description);
  $member = $this->party->members->toArray()[0];
  new ReflectionProperty($member, 'name')->setValue($member, str_repeat('Long actor ', 7));
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::PAGE_UP, KeyCode::PAGE_DOWN, KeyCode::LEFT, KeyCode::RIGHT]],
    'cancel' => ['keys' => [KeyCode::SHIFT_TAB, KeyCode::UP, KeyCode::DOWN, KeyCode::PAGE_UP, KeyCode::PAGE_DOWN, KeyCode::BACKSPACE, KeyCode::ENTER]]]);
  $controlLabel = str_repeat('Visible control ', 3) . 'One';
  ActionHints::useProvider(new class($controlLabel) implements ActionHintProvider {
    public function __construct(private string $label) {}
    public function controlForAction(string $action): ?ControlHint { return new ControlHint('profile', $action, $this->label); }
  });
  $source = new FakeInputSource(KeyCode::ENTER);
  InputManager::setInputSource($source);
  $frame = $this->state->canvas($theme);
  $text = mainMenuPresentationText($frame);
  expect($text['main-command-0-text'])->toBe($label)->and($text['main-info-value'])->toBe($description)
    ->and($text['main-party-0-identity-text'])->toBe($member->name)
    ->and($text['main-hints'])->toContain(' ' . $controlLabel . ' : Confirm', ' ' . $controlLabel . ' : Cancel')->not->toContain('ESC')
    ->and($source->keys)->toBe([KeyCode::ENTER]);
  $layers = array_column($frame->textLayers, null, 'id');
  $hints = $layers['main-hints'];
  expect($hints->grid->rows)->toBeGreaterThan(1)
    ->and($layers['main-help-text']->y + $layers['main-help-text']->grid->rows * $theme->metrics->cellHeight)->toBeLessThanOrEqual($hints->y);
  foreach ($frame->textLayers as $layer) {
    if (preg_match('/^main-party-\d+/', $layer->id)) {
      expect($layer->clipRect->y + $layer->clipRect->height)->toBeLessThanOrEqual($layers['main-help-text']->y);
    }
  }
  ActionHints::useProvider(null);
  InputManager::setBindings([]);
  expect(mainMenuPresentationText($this->state->canvas($theme))['main-hints'])->toBe('Unbound: Confirm  Unbound: Cancel');
})->with([false, true]);

it('refreshes compact hints and profile glyphs on the real four-party menu without changing its actions', function (bool $alternative) {
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::SPACE, KeyCode::ENTER]],
    'cancel' => ['keys' => [KeyCode::C, KeyCode::c, KeyCode::ESCAPE]]]);
  $data = mainMenuPresentationTheme($alternative);
  $data['icons']['input.profile-one.confirm'] = 'surface.png';
  $data['icons']['input.profile-two.confirm'] = 'focus.png';
  $theme = new MenuPresentationCatalog($this->root, $data);
  $this->state->mainMenu->setActiveItemByLabel('Order');
  $frame = $this->state->canvas($theme);
  expect(mainMenuPresentationText($frame)['main-hints'])->toBe(' Enter : Confirm   Escape : Cancel');
  $provider = new class implements ActionHintProvider {
    public function __construct(public string $family = 'profile-one') {}
    public function controlForAction(string $action): ?ControlHint { return new ControlHint($this->family, $action, 'Primary ' . $action); }
  };
  $mode = $this->state->getPresentationMode();
  $bindings = InputManager::getBindings();
  $source = new FakeInputSource(KeyCode::ENTER);
  InputManager::setInputSource($source);
  ActionHints::useProvider($provider);
  foreach (['profile-one' => 'surface.png', 'profile-two' => 'focus.png'] as $family => $asset) {
    $provider->family = $family;
    $frame = $this->state->canvas($theme);
    expect(count($frame->textLayers))->toBeLessThanOrEqual(64)
      ->and(mainMenuPresentationText($frame))->toHaveKeys(['main-party-0-identity-text', 'main-party-3-identity-text'])
      ->and(mainMenuPresentationText($frame)['main-hints'])->toBe(': Confirm   Primary cancel : Cancel')
      ->and(array_column(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'main-hints-control-')), 'asset'))->toBe([$asset])
      ->and($this->state->getPresentationMode())->toBe($mode)->and(InputManager::getBindings())->toBe($bindings)
      ->and($source->keys)->toBe([KeyCode::ENTER]);
  }
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  expect($this->state->getPresentationMode())->toBeInstanceOf(MainMenuPartyOrderMode::class);
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  mainMenuPresentationKey(KeyCode::ESCAPE, $this->state);
  expect($this->state->characterSelectionMenu->getMarkedPanelIndex())->toBeNull()
    ->and($this->state->getPresentationMode())->toBeInstanceOf(MainMenuPartyOrderMode::class);
  ActionHints::useProvider(null);
  InputManager::setBinding('cancel', [KeyCode::Q]);
  expect(mainMenuPresentationText($this->state->canvas($theme))['main-hints'])->toBe(' Enter : Confirm   Q : Cancel');
})->with([false, true]);

it('contains replacement portraits using current PNG facts and preserves valid unique instances', function () {
  $theme = new MenuPresentationCatalog($this->root, mainMenuPresentationTheme(true));
  $before = $this->state->canvas($theme);
  mainMenuPresentationPng($this->root . '/portrait.png', 23, 137);
  clearstatcache();
  $after = $this->state->canvas($theme);
  $portraits = array_values(array_filter($after->images, fn($image) => $image->asset === 'portrait.png'));
  expect($portraits)->toHaveCount(4)->and(mainMenuPresentationText($before))->toBe(mainMenuPresentationText($after));
  foreach ($portraits as $portrait) {
    expect($portrait->destination->width / $portrait->destination->height)->toEqualWithDelta(23 / 137, 0.000001)
      ->and($portrait->destination->width)->toBeLessThanOrEqual($portrait->clipRect->width)
      ->and($portrait->destination->height)->toBeLessThanOrEqual($portrait->clipRect->height);
  }
});

it('uses shared Config when themed and overlays alerts without changing the active menu or consuming input', function () {
  expect($this->scene->getPresentationCanvas())->toBeNull();
  $this->runtime = mainMenuPresentationRuntime($this->root, MenuPresentationCatalog::CAPABILITIES);
  $this->game->useRendererRuntime($this->runtime);
  expect($this->scene->getPresentationCanvas())->toBeNull();
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE, '<?php return ' . var_export(mainMenuPresentationTheme(true), true) . ';');
  $this->state->enter();
  $this->state->mainMenu->setActiveItemByLabel('Config');
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  $configFrame = $this->scene->getPresentationCanvas();
  $configRuns = array_merge(...array_map(fn($layer) => array_column($layer->runs, 'text'), $configFrame->textLayers));
  expect($this->state->getPresentationMode())->toBeInstanceOf(MainMenuConfigMode::class)
    ->and($configRuns)->toContain('Config', 'Volume', '75%', 'Sets the master volume for music, sound effects and voice.', 'Cancel');
  $config = $this->state->getPresentationMode()->getConfigMenu();
  InputManager::setBindings([...InputManager::getBindings(), 'down' => ['keys' => [KeyCode::DOWN]]]);
  mainMenuPresentationKey(KeyCode::DOWN, $this->state);
  expect($config->selection->getActiveIndex())->toBe(1);
  $this->state->render();
  expect($this->state->getPresentationMode()->getConfigMenu())->toBe($config)
    ->and($config->selection->getActiveIndex())->toBe(1);
  mainMenuPresentationKey(KeyCode::C, $this->state);
  expect($this->state->mainMenu->getActiveItem()->getLabel())->toBe('Config')
    ->and($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class)
    ->and(mainMenuPresentationText($this->scene->getPresentationCanvas()))->not->toHaveKey('config-title');
  $this->state->setMode(new MainMenuPartyOrderMode($this->state));
  mainMenuPresentationKey(KeyCode::ENTER, $this->state);
  $this->state->characterSelectionMenu->selectNext();
  $before = mainMenuPresentationText($this->scene->getPresentationCanvas());
  $mode = $this->state->getPresentationMode();
  $modal = makeBareScene(ModalManager::class);
  $stack = new Stack(ModalInterface::class);
  new ReflectionProperty($modal, 'modals')->setValue($modal, $stack);
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $modal);
  $alert = new AlertModal($this->game, 'Equipment optimized!', 'Equipment');
  new ReflectionProperty($alert, 'isShowing')->setValue($alert, true);
  $stack->push($alert);
  $source = new FakeInputSource(KeyCode::C);
  InputManager::setInputSource($source);
  $overlay = $this->scene->getPresentationCanvas();
  expect($overlay)->toBeInstanceOf(PresentationCanvas::class)
    ->and(mainMenuPresentationText($overlay)['menu-modal-message'])->toBe('Equipment optimized!');
  foreach ($before as $id => $text) {
    expect(mainMenuPresentationText($overlay)[$id])->toBe($text);
  }
  $stack->pop();
  expect(mainMenuPresentationText($this->scene->getPresentationCanvas()))->toBe($before)
    ->and($this->state->getPresentationMode())->toBe($mode)->and($source->keys)->toBe([KeyCode::C])
    ->and($this->state->characterSelectionMenu->getMarkedPanelIndex())->toBe(0)
    ->and($this->state->characterSelectionMenu->getActivePanelIndex())->toBe(1);
});

it('connects in-game Save to the shared themed canvas and modal lifecycle without writing saves', function (bool $alternative) {
  $slots = [new SaveSlot(1, '', false, 'Rest Point', 'Actor 0', 6, 3600),
    ...array_map(static fn($index) => SaveSlot::empty($index, ''), range(2, 5))];
  $manager = $this->createMock(SaveManager::class);
  $manager->expects($this->atLeastOnce())->method('getSaveSlots')->with(5)->willReturn($slots);
  $manager->expects($this->never())->method('save');
  new ReflectionProperty($this->scene->sceneManager, 'saveManager')->setValue($this->scene->sceneManager, $manager);
  $state = new SaveMenuState(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $state);
  expect($state)->toBeInstanceOf(CanvasProviderInterface::class);
  $state->enter();
  expect($this->scene->getPresentationCanvas())->toBeNull();
  $terminal = new ReflectionProperty($state, 'helpWindow')->getValue($state);
  expect(implode(' ', $terminal->getContent()))->toContain('Choose a file.', 'Enter saves. Esc returns.');

  $themeData = mainMenuPresentationTheme($alternative);
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE, '<?php return ' . var_export($themeData, true) . ';');
  $this->runtime = mainMenuPresentationRuntime($this->root, MenuPresentationCatalog::CAPABILITIES);
  $this->game->useRendererRuntime($this->runtime);
  $state->enter();
  $frame = $this->scene->getPresentationCanvas();
  expect($frame)->not->toBeNull(new ReflectionProperty($state, 'menuPresentationError')->getValue($state) ?? '');
  $text = mainMenuPresentationText($frame);
  expect($text['save-title'])->toBe('Save')
    ->and($text['save-prompt-text'])->toBe('Which file would you like to save to?')
    ->and($text['save-slot-1-location-text'])->toBe('Rest Point')
    ->and($text['save-slot-1-duration'])->toBe('01:00:00');
  $ids = [...array_column($frame->textLayers, 'id'), ...array_column($frame->images, 'id')];
  expect(array_filter($ids, static fn($id) => str_starts_with($id, 'save-slot-1-location-selected')))->not->toBeEmpty();
  $theme = new MenuPresentationCatalog($this->root, $themeData);
  foreach (['increase' => 'Saved to File 1.', 'decrease' => 'Save unavailable.'] as $role => $status) {
    new ReflectionProperty($state, 'statusMessage')->setValue($state, $status);
    new ReflectionProperty($state, 'statusColor')->setValue($state, $role);
    $frame = $this->scene->getPresentationCanvas();
    $layer = array_find($frame->textLayers, static fn($layer) => $layer->id === 'save-info-status');
    expect(mainMenuPresentationText($frame)['save-info-status'])->toBe($status)
      ->and($layer->runs[0]->foreground)->toEqual($theme->colors[$role]);
  }

  $modals = makeBareScene(ModalManager::class);
  $stack = new Stack(ModalInterface::class);
  new ReflectionProperty($modals, 'modals')->setValue($modals, $stack);
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $modals);
  $alert = new AlertModal($this->game, 'Saved to File 1.', 'Save Complete');
  new ReflectionProperty($alert, 'isShowing')->setValue($alert, true);
  $stack->push($alert);
  $overlay = implode(' ', mainMenuPresentationText($this->scene->getPresentationCanvas()));
  expect($overlay)->toContain('Save Complete', 'Saved to File 1.', 'OK', 'Rest Point');
  $stack->pop();
  $state->resume();
  expect(mainMenuPresentationText($this->scene->getPresentationCanvas())['save-info-status'])->toBe('Save unavailable.');
  $state->enter();
  expect(mainMenuPresentationText($this->scene->getPresentationCanvas())['save-info-description'])->toBe('Choose a file.');
})->with([false, true]);

it('diagnoses unrenderable content and recovers without mutating the owner', function () {
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE, '<?php return ' . var_export(mainMenuPresentationTheme(false), true) . ';');
  $this->runtime = mainMenuPresentationRuntime($this->root, MenuPresentationCatalog::CAPABILITIES);
  $this->game->useRendererRuntime($this->runtime);
  $mode = $this->state->getPresentationMode();
  $this->state->infoPanel->setText(str_repeat('Long description ', 1000));
  expect($this->scene->getPresentationCanvas())->toBeNull()->and($this->state->getPresentationMode())->toBe($mode);
  $this->state->infoPanel->setText('Recovered description');
  expect($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class);
  expect(file_get_contents($this->root . '/logs/error.log'))->toContain('Menu presentation degraded to terminal');
});

it('keeps default row output identical while separate record treatments retain the same name cursor geometry', function (bool $alternative) {
  $theme = new MenuPresentationCatalog($this->root, mainMenuPresentationTheme($alternative));
  $row = new MenuRow('actor', 'Actor Name', selected: true, focused: true);
  $layout = new MenuRowLayout(new CanvasRectangle(250, 100, 600, 40), rowHeight: 40,
    cellWidth: $theme->metrics->cellWidth, cellHeight: $theme->metrics->cellHeight);
  $normal = MenuRowPainter::compose(1350, 720, 'test', [$row], $layout, $theme->rows, $theme->icons, reducedMotion: true);
  $explicit = MenuRowPainter::compose(1350, 720, 'test', [$row], $layout, $theme->rows, $theme->icons,
    reducedMotion: true, recordBounds: $layout->viewport);
  expect($explicit)->toEqual($normal);
  $full = MenuRowPainter::compose(1350, 720, 'test', [$row], $layout, $theme->rows, $theme->icons,
    reducedMotion: true, recordBounds: new CanvasRectangle(200, 90, 700, 150));
  expect(array_column($full->textLayers, null, 'id')['test-actor-text'])->toEqual(array_column($normal->textLayers, null, 'id')['test-actor-text']);
  $cursor = fn(PresentationCanvas $frame) => array_values(array_filter($frame->images, fn($image) => str_contains($image->id, '-cursor')));
  expect($cursor($full))->toEqual($cursor($normal));
  $separators = array_column($full->textLayers, null, 'id');
  expect($separators['test-actor-separator']->clipRect->y)->toBe(239.0);
})->with([false, true]);

it('retains source-specific terminal fields and owner Save availability', function () {
  $this->state->render();
  $terminal = TerminalText::stripAnsi(implode("\n", Console::getBuffer()));
  foreach (['Actor 0', 'Role:', 'Lv', 'HP', 'MP', 'Play Time', 'Tokens', '4,321 T', 'Owner Location', 'Owner Region', 'Equipment', 'Save'] as $text) {
    expect($terminal)->toContain($text);
  }
  $this->state->saveAllowed = false;
  $this->state->enter();
  $this->state->render();
  expect($this->state->mainMenu->getItemByLabel('Save'))->toBeNull();
  $native = mainMenuPresentationText($this->state->canvas(new MenuPresentationCatalog($this->root, mainMenuPresentationTheme(true))));
  expect(array_values($native))->not->toContain('Save');
});

it('keeps reduced-motion character focus stationary without moving content', function () {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => true]]));
  $this->state->setMode(new MainMenuCharacterSelectionMode($this->state));
  $theme = new MenuPresentationCatalog($this->root, mainMenuPresentationTheme(true));
  expect($this->state->canvas($theme, 0)->toArray())->toBe($this->state->canvas($theme, 0.5)->toArray());
});

it('compacts neutral fills and separators without changing coverage opacity or content', function (bool $rulesOnly) {
  $theme = new MenuPresentationCatalog($this->root, mainMenuPresentationTheme(false));
  $view = new MenuCanvas($theme, width: 400, height: 500);
  for ($i = 0; $i < ($rulesOnly ? 0 : 30); $i++) {
    $view->frame('frame-' . $i, new CanvasRectangle(0, $i * 10, 100, 10));
  }
  for ($i = 0; $i < ($rulesOnly ? 32 : 20); $i++) {
    $view->rows('row-' . $i, [new MenuRow('value', 'Record')], new MenuRowLayout(new CanvasRectangle(100, $i * 14, 200, 14),
      rowHeight: 14, cellWidth: 8, cellHeight: 12));
  }
  $before = new ReflectionProperty($view, 'text')->getValue($view);
  expect(count($before))->toBeGreaterThan(64);
  $after = $view->finish()->textLayers;
  $pixels = static function (array $layers): array {
    $result = [];
    foreach ($layers as $layer) {
      foreach ($layer->runs as $run) {
        if ($run->background === null) { continue; }
        $clip = $layer->clipRect;
        $left = max($clip->x, $layer->x + $run->column * $layer->grid->cellWidth);
        $top = max($clip->y, $layer->y + $run->row * $layer->grid->cellHeight);
        $right = min($clip->x + $clip->width, $layer->x + ($run->column + mb_strlen($run->text)) * $layer->grid->cellWidth);
        $bottom = min($clip->y + $clip->height, $layer->y + ($run->row + 1) * $layer->grid->cellHeight);
        $color = json_encode([$run->background->toArray(), $layer->opacity]);
        for ($y = $top; $y < $bottom; $y++) {
          for ($x = $left; $x < $right; $x++) { $result[$layer->layer * 200000 + (int)$y * 400 + (int)$x] = $color; }
        }
      }
    }
    ksort($result);
    return $result;
  };
  expect(count($after))->toBeLessThanOrEqual(64)->and($pixels($after))->toBe($pixels($before));
})->with([false, true]);

it('fits four actors and thirteen commands with framed art and neutral row treatments', function () {
  $data = mainMenuPresentationTheme(false);
  $data['frames'] = array_fill_keys(['panel', 'quiet', 'portrait'], ['asset' => 'surface.png', 'cuts' => [24, 24, 24, 24]]);
  $theme = new MenuPresentationCatalog($this->root, $data);
  $menu = $this->state->mainMenu;
  $commands = $menu->getItems()->toArray();
  array_splice($commands, 4, 0, [new OpenSummonsMenuCommand($menu), new OpenQuestsMenuCommand($menu), new OpenRecordsMenuCommand($menu)]);
  $menu->setItems(new ItemList(MenuItemInterface::class, $commands));
  foreach ([new MainMenuCommandSelectionMode($this->state), new MainMenuCharacterSelectionMode($this->state),
    new MainMenuPartyOrderMode($this->state)] as $mode) {
    $this->state->setMode($mode);
    if ($mode instanceof MainMenuPartyOrderMode) {
      mainMenuPresentationKey(KeyCode::ENTER, $this->state);
      $this->state->characterSelectionMenu->selectNext();
    }
    $frame = $this->state->canvas($theme);
    expect(count($frame->textLayers))->toBeLessThanOrEqual(64)
      ->and(mainMenuPresentationText($frame))->toHaveKeys(['main-party-0-identity-text', 'main-party-1-identity-text',
        'main-party-2-identity-text', 'main-party-3-identity-text', 'main-command-12-text']);
  }
});


it('replaces only the unavailable main menu portrait and retains other cards and frames', function () {
  $theme = mainMenuPresentationTheme(true);
  $theme['portraits']['actor.0'] = 'broken.png';
  file_put_contents($this->root . '/broken.png', 'invalid PNG');
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE, '<?php return ' . var_export($theme, true) . ';');
  $this->runtime = mainMenuPresentationRuntime($this->root, MenuPresentationCatalog::CAPABILITIES);
  $this->game->useRendererRuntime($this->runtime);
  $mode = $this->state->getPresentationMode();
  $frame = $this->scene->getPresentationCanvas();
  expect($frame)->toBeInstanceOf(PresentationCanvas::class)
    ->and(array_column($frame->images, 'asset'))->toContain('portrait.png', 'surface.png')->not->toContain('broken.png')
    ->and(array_filter($frame->images, fn($image) => $image->asset === 'portrait.png'))->toHaveCount(3)
    ->and(implode('', mainMenuPresentationText($frame)))->toContain('Items')
    ->and($this->state->getPresentationMode())->toBe($mode)
    ->and(file_get_contents($this->root . '/logs/warning.log'))->toContain('broken.png');
  mainMenuPresentationPng($this->root . '/broken.png', 33, 55);
  expect(array_column($this->scene->getPresentationCanvas()->images, 'asset'))->toContain('broken.png');
});
