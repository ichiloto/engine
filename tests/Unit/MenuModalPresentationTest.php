<?php

declare(strict_types=1);

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ConfirmModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Modal\PromptModal;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuCanvasTextBatch;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\UI\Presentation\MenuRowLayout;
use Ichiloto\Engine\UI\Presentation\MenuRowPainter;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

class ModalMenuGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

class ModalMenuMainState extends MainMenuState
{
  protected bool $canSave { get => true; }
}

function modalMenuPng(string $path): void
{
  $chunk = static fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 43, 61, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xAD\xBE\xCF\xFF", 43), 61))) . $chunk('IEND', ''));
}

function modalMenuTheme(bool $art): array
{
  $theme = ['schema' => 'ichiloto.menu/1', 'cursor' => 'art.png',
    'portraits' => array_fill_keys(['actor.0', 'actor.1', 'actor.2', 'actor.3'], 'art.png')];
  if (!$art) { return $theme; }
  $frame = ['asset' => 'art.png', 'cuts' => [3, 4, 3, 4]];
  return [...$theme, 'colors' => ['text' => [40, 30, 20], 'panel' => [230, 220, 200]],
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34, 'panelPadding' => 16, 'sectionGap' => 8, 'portraitSize' => 88],
    'frames' => ['panel' => $frame, 'quiet' => $frame],
    'rowArtwork' => ['command.normal' => $frame, 'command.selected' => $frame, 'command.focus' => ['asset' => 'art.png']]];
}

/** Foreground paint positions must survive backing/rule batching unchanged. */
function modalMenuText(PresentationCanvas $canvas): array
{
  $text = [];
  foreach ($canvas->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      if ($run->foreground !== null) {
        $text[] = [$layer->id, $layer->x + $run->column * $layer->grid->cellWidth,
          $layer->y + $run->row * $layer->grid->cellHeight, $run->text];
      }
    }
  }
  return $text;
}

function modalMenuForeground(array $layers): array
{
  $paint = [];
  foreach ($layers as $layer) {
    if (str_starts_with($layer->id, 'menu-modal-')) { continue; }
    foreach ($layer->runs as $run) {
      if ($run->foreground === null) { continue; }
      $paint[] = [$layer->layer, $layer->x + $run->column * $layer->grid->cellWidth,
        $layer->y + $run->row * $layer->grid->cellHeight, $run->text, $run->foreground->toArray(),
        $run->background?->toArray(), $layer->opacity, $layer->grid->cellWidth, $layer->grid->cellHeight];
    }
  }
  sort($paint);
  return $paint;
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, ConfigStore::class, InputManager::class, ActionHints::class, Timers::class,
    ModalManager::class, EventManager::class, AudioManager::class, Debug::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Timers::setFrameTick(null);
  ActionHints::useProvider(null);
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  $this->root = sys_get_temp_dir() . '/ichiloto-menu-modal-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/Data/Presentation', 0777, true);
  modalMenuPng($this->root . '/art.png');
  Debug::configure(['log_directory' => $this->root . '/logs']);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  ConfigStore::put(\Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog::class, new SceneAudioConfigStub());
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  $this->game = new ModalMenuGame();
  $audio = new class extends AudioManager {
    public array $sounds = [];
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void { $this->sounds[] = $sound; }
  };
  $this->audio = $audio;
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $audio);
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $this->manager = ModalManager::getInstance($this->game);
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $this->manager);
  $sceneManager = makeBareScene(SceneManager::class);
  new ReflectionProperty($sceneManager, 'game')->setValue($sceneManager, $this->game);
  new ReflectionProperty($this->game, 'sceneManager')->setValue($this->game, $sceneManager);
  $this->scene = makeBareScene(GameScene::class);
  new ReflectionProperty($this->scene, 'sceneManager')->setValue($this->scene, $sceneManager);
  new ReflectionProperty($sceneManager, 'currentScene')->setValue($sceneManager, $this->scene);
  new ReflectionProperty($this->scene, 'gameState')->setValue($this->scene, new GameState());
  $ui = makeBareScene(UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($ui, 'uiElements')->setValue($ui, new ItemList(UIElementInterface::class));
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  $this->party = new Party();
  $this->party->location = new PartyLocation('Current Location', 'Current Region');
  for ($i = 0; $i < 4; $i++) { $this->party->addMember(new Character('Actor ' . $i, 0, new Stats(), actorId: 'actor.' . $i)); }
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, $this->party);
  $this->state = new ModalMenuMainState(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $this->state);
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'cancel' => ['keys' => [KeyCode::ESCAPE]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]]]);
  $this->state->enter();
  foreach (['Additional One', 'Additional Two'] as $label) {
    $this->state->mainMenu->addItem(new class($this->state->mainMenu, $label, 'Current command.') extends \Ichiloto\Engine\Core\Menu\MenuItem {});
  }
  $this->runtime = null;
  $this->startCanvas = function (bool $art = false, bool $showInputHints = true): void {
    file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE, '<?php return '
      . var_export([...modalMenuTheme($art), 'showInputHints' => $showInputHints], true) . ';');
    $this->transport = new FakeRendererTransport();
    $caps = MenuPresentationCatalog::CAPABILITIES;
    $this->transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
    $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $this->root,
      requiredCapabilities: $caps), $this->transport);
    $this->runtime->start('Modal fixture', 135, 36);
    $this->game->useRendererRuntime($this->runtime);
    // Exercise the same presentation callback that Game installs for blocking modal waits.
    Timers::setFrameTick(static function (): void {}, fn() => new ReflectionMethod(Game::class, 'presentBlockedFrame')->invoke($this->game));
  };
  ob_start();
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

it('layers supported modals above the complete four-party menu through either theme', function (bool $art, string $type) {
  ($this->startCanvas)($art);
  $base = $this->scene->getPresentationCanvas();
  $modal = match ($type) {
    'alert' => new AlertModal($this->game, 'Equipment optimized!', 'Equipment'),
    'confirm' => new ConfirmModal($this->game, 'Confirm this action.', 'Confirmation'),
    'select' => new SelectModal($this->game, 'Choose where to go.', ['To Title', 'Exit'], 'Quit', default: 1),
  };
  $this->manager->open($modal);
  MenuModalPresentation::compose($base, $modal->getModalPresentation(), MenuPresentationCatalog::load($this->root));
  $frame = $this->scene->getPresentationCanvas();
  expect($frame)->toBeInstanceOf(PresentationCanvas::class)->and(count($frame->textLayers))->toBeLessThanOrEqual(64);
  $modalText = array_filter($frame->textLayers, fn($text) => str_starts_with($text->id, 'menu-modal-'));
  $modalImages = array_filter($frame->images, fn($image) => str_starts_with($image->id, 'menu-modal-'));
  $maxBase = max([...array_column($base->textLayers, 'layer'), ...array_column($base->images, 'layer')]);
  expect(min(array_column($modalText, 'layer')))->toBeGreaterThan($maxBase);
  expect(min([PHP_INT_MAX, ...array_column($modalImages, 'layer')]))->toBeGreaterThan($maxBase);
  expect(modalMenuForeground($frame->textLayers))->toBe(modalMenuForeground($base->textLayers));
  foreach ($base->images as $image) {
    $retained = array_find($frame->images, fn($candidate) => $candidate->id === $image->id);
    expect($retained)->not->toBeNull();
    if (!str_contains($image->id, 'cursor')) { expect($retained)->toEqual($image); }
  }
  foreach ($modalText as $text) {
    if (!preg_match('/menu-modal-choice-\d+-text/', $text->id)) { continue; }
    expect($text->x + $text->bounds->width / 2)->toEqualWithDelta($text->clipRect->x + $text->clipRect->width / 2, 0.000001);
  }
  expect(array_filter(array_column($modalText, 'id'), fn($id) => str_contains($id, 'separator')))->toBeEmpty();
  expect(array_filter([...array_column($modalText, 'id'), ...array_column($frame->images, 'id')],
    fn($id) => str_starts_with($id, 'menu-modal-choice-' . $modal->getModalPresentation()->activeIndex . '-selected')))->not->toBeEmpty();
  $modal->hide();
  $source = new FakeInputSource(KeyCode::TAB);
  InputManager::setInputSource($source);
  expect($this->manager->currentModal)->toBe($modal)
    ->and(modalMenuText($this->scene->getPresentationCanvas()))->toBe(modalMenuText($base));
  new ReflectionMethod(Game::class, 'presentBlockedFrame')->invoke($this->game);
  expect(end($this->transport->sent)->payload)->toHaveKey('canvas')
    ->and(json_encode(end($this->transport->sent)->payload))->not->toContain('menu-modal-')
    ->and($source->keys)->toBe([KeyCode::TAB]);
})->with([false, true])->with(['alert', 'confirm', 'select']);

it('follows the existing selected index through many long choices without dropping the current choice', function (bool $art) {
  ($this->startCanvas)($art);
  $base = $this->scene->getPresentationCanvas();
  $theme = MenuPresentationCatalog::load($this->root);
  $choices = array_map(fn($i) => $i . ' ' . str_repeat('Long option ', 10), range(0, 59));
  foreach ([0, 30, 59] as $active) {
    $frame = MenuModalPresentation::compose($base, new ModalPresentation('Choose', str_repeat('Complete message ', 8), $choices, $active, true), $theme);
    $layer = array_find($frame->textLayers, fn($text) => $text->id === 'menu-modal-choice-' . $active . '-text');
    expect($layer)->not->toBeNull()->and(implode('', array_column($layer->runs, 'text')))->toBe($choices[$active])
      ->and(count($frame->textLayers))->toBeLessThanOrEqual(64)
      ->and(array_column($frame->textLayers, 'id'))->toContain('menu-modal-choice-range');
  }
})->with([false, true]);

it('submits the real Optimize alert through the blocked render frame and restores the same equipment owner', function (bool $art, bool $showInputHints) {
  $equipment = new EquipmentMenuState(new SceneStateContext($this->scene));
  $equipment->character = $this->party->members->toArray()[0];
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $equipment);
  $equipment->enter();
  ($this->startCanvas)($art, $showInputHints);
  $equipment->equipmentMenu->setActiveItemByIndex(1);
  $source = new FakeInputSource(null, KeyCode::ENTER, KeyCode::TAB);
  InputManager::setInputSource($source);
  $equipment->equipmentMenu->getActiveItem()->execute($equipment->equipmentMenuContext);
  $frames = array_filter($this->transport->sent, fn($message) => isset($message->payload['canvas']));
  expect($frames)->not->toBeEmpty()->and($this->manager->currentModal)->toBeNull()
    ->and($this->scene->state)->toBe($equipment)->and($equipment->equipmentMenu->activeIndex)->toBe(1)
    ->and($source->keys)->toBe([KeyCode::TAB]);
  foreach ($frames as $message) {
    $text = json_encode($message->payload['canvas']['textLayers']);
    expect($text)->toContain('Equipment optimized!', 'menu-modal-choice-0-text', 'equipment-identity');
    if (!$showInputHints) { expect($text)->not->toContain('menu-modal-hints'); }
  }
  new ReflectionMethod(Game::class, 'presentBlockedFrame')->invoke($this->game);
  expect(json_encode(end($this->transport->sent)->payload))->not->toContain('menu-modal-')->toContain('equipment-identity');
})->with([false, true])->with([false, true]);

it('cancels the real Quit selection through the same input path without exiting or consuming the next input', function () {
  ($this->startCanvas)();
  $this->state->mainMenu->setActiveItemByLabel('Quit');
  $source = new FakeInputSource(null, KeyCode::ESCAPE, KeyCode::TAB);
  InputManager::setInputSource($source);
  $this->state->mainMenu->getActiveItem()->execute();
  expect($source->keys)->toBe([KeyCode::TAB])->and($this->game->hasStopped())->toBeFalse()
    ->and($this->manager->currentModal)->toBeNull()->and($this->state->mainMenu->getActiveItem()->getLabel())->toBe('Quit');
  $frames = array_filter($this->transport->sent, fn($message) => isset($message->payload['canvas']));
  expect($frames)->not->toBeEmpty();
  foreach ($frames as $message) { expect(json_encode($message->payload))->toContain('To Title', 'Exit', 'menu-modal-choice-0-text'); }
});

it('honors safe default selection indexes and live semantic cancel bindings without a hardcoded C alias', function () {
  ($this->startCanvas)();
  $source = new FakeInputSource(null, KeyCode::ENTER);
  InputManager::setInputSource($source);
  expect($this->manager->select('Discard progress?', ['Proceed', 'Cancel'], 'Confirm', default: 1))->toBe(1);
  $frame = end($this->transport->sent)->payload['canvas'];
  expect(array_filter(array_column($frame['textLayers'], 'id'), fn($id) => str_starts_with($id, 'menu-modal-choice-1-focus')))->not->toBeEmpty();
  InputManager::setBinding('cancel', [KeyCode::Q]);
  $source = new FakeInputSource(KeyCode::C, KeyCode::ESCAPE, KeyCode::Q, KeyCode::TAB);
  InputManager::setInputSource($source);
  expect($this->manager->select('Cancel after rebinding', ['One', 'Two']))->toBe(-1)
    ->and($source->keys)->toBe([KeyCode::TAB]);
});

it('keeps the presentation snapshot detached from mutable caller references and the real choice owner', function () {
  $label = 'Cancel';
  $snapshot = new ModalPresentation('Title', 'Message', ['Proceed', &$label], 1, true);
  $label = 'Changed';
  expect($snapshot->choices)->toBe(['Proceed', 'Cancel'])->and($snapshot->activeIndex)->toBe(1);
  $modal = new SelectModal($this->game, 'Choose', ['One', 'Two'], default: 1);
  $snapshot = $modal->getModalPresentation();
  $modal->setActiveButton(0);
  expect($snapshot->activeIndex)->toBe(1)->and($modal->getModalPresentation()->activeIndex)->toBe(0);
});

it('uses the Select owner navigation for large-choice viewport changes without reading extra input', function (bool $art) {
  ($this->startCanvas)($art);
  MenuModalPresentation::compose($this->scene->getPresentationCanvas(),
    new ModalPresentation('Choose', 'Choose', array_map(fn($i) => 'Choice ' . $i, range(0, 59)), 31, true),
    MenuPresentationCatalog::load($this->root));
  $source = new FakeInputSource(KeyCode::DOWN, null, KeyCode::ENTER, KeyCode::TAB);
  InputManager::setInputSource($source);
  expect($this->manager->select('Choose', array_map(fn($i) => 'Choice ' . $i, range(0, 59)), default: 30))->toBe(31)
    ->and($source->keys)->toBe([KeyCode::TAB]);
  $frame = end($this->transport->sent)->payload['canvas'];
  expect(array_column($frame['textLayers'], 'id'))->toContain('menu-modal-choice-31-text', 'menu-modal-choice-range')
    ->and(count($frame['textLayers']))->toBeLessThanOrEqual(64);
})->with([false, true]);

it('preserves confirm button outcomes and gives a shared confirm-cancel key one outcome', function (array $keys, bool $expected) {
  ($this->startCanvas)();
  InputManager::setInputSource(new FakeInputSource(null, ...$keys));
  expect($this->manager->confirm('Proceed?', 'Confirm'))->toBe($expected)->and($this->manager->currentModal)->toBeNull();
  $this->audio->sounds = [];
  InputManager::setBinding('cancel', [KeyCode::ENTER]);
  InputManager::setInputSource(new FakeInputSource(KeyCode::ENTER));
  expect($this->manager->confirm('One outcome', 'Confirm'))->toBeTrue()->and($this->audio->sounds)->toBe([SystemSound::CONFIRM]);
})->with([[[KeyCode::ENTER], true], [[KeyCode::RIGHT, KeyCode::ENTER], false], [[KeyCode::ESCAPE], false]]);

it('diagnoses unsupported or oversized modals instead of disguising them as generic alerts', function (string $type) {
  ($this->startCanvas)();
  $modal = match ($type) {
    'prompt' => new PromptModal($this->game, 'Edit value', 'Prompt', 'Current'),
    'text' => new TextBoxModal($this->game, 'Typed dialogue', 'Speaker'),
    'long' => new AlertModal($this->game, str_repeat('Complete long message ', 2000), 'Long'),
  };
  $this->manager->open($modal);
  expect($this->scene->getPresentationCanvas())->toBeNull()->and(is_dir($this->root . '/logs'))->toBeTrue();
  $logs = implode('', array_map(file_get_contents(...), glob($this->root . '/logs/*')));
  expect($logs)->toContain('Menu presentation degraded to terminal', $type === 'long' ? 'finite menu viewport' : 'no supported menu canvas');
  new ReflectionMethod(Game::class, 'presentBlockedFrame')->invoke($this->game);
  expect(end($this->transport->sent)->payload)->not->toHaveKey('canvas')->toHaveKey('textLayers');
  $modal->hide();
  new ReflectionMethod(Game::class, 'presentBlockedFrame')->invoke($this->game);
  expect(end($this->transport->sent)->payload)->toHaveKey('canvas');
})->with(['prompt', 'text', 'long']);

it('sizes compact dialogs from current text and wraps long content at the same bounded font size', function (bool $art) {
  ($this->startCanvas)($art);
  $theme = MenuPresentationCatalog::load($this->root);
  $base = $this->scene->getPresentationCanvas();
  $small = MenuModalPresentation::compose($base, new ModalPresentation('Alert', 'Equipment optimized!', ['OK'], 0), $theme);
  $message = str_repeat('A complete longer message. ', 8);
  $large = MenuModalPresentation::compose($base, new ModalPresentation('Long', $message, ['OK'], 0), $theme);
  $smallBox = array_find($small->textLayers, fn($layer) => $layer->id === 'menu-modal-backing')->clipRect;
  $largeBox = array_find($large->textLayers, fn($layer) => $layer->id === 'menu-modal-backing')->clipRect;
  expect($smallBox->width)->toBeLessThan($largeBox->width)->toBeLessThan(500)
    ->and($largeBox->width)->toBeLessThanOrEqual($base->width * 2 / 3)
    ->and($smallBox->x + $smallBox->width / 2)->toBe($base->width / 2.0);
  $text = array_find($large->textLayers, fn($layer) => $layer->id === 'menu-modal-message');
  expect(implode('', array_column($text->runs, 'text')))->toBe($message)
    ->and($text->grid->cellWidth)->toBe($theme->metrics->cellWidth)->and($text->grid->cellHeight)->toBe($theme->metrics->cellHeight);
})->with([false, true]);

it('marks only single-confirmation Alert snapshots for the acknowledgement layout', function () {
  expect(new AlertModal($this->game, 'Complete', 'Alert')->getModalPresentation()->singleConfirmation)->toBeTrue()
    ->and(new ConfirmModal($this->game, 'Proceed?', 'Confirm')->getModalPresentation()->singleConfirmation)->toBeFalse()
    ->and(new SelectModal($this->game, 'Choose', ['Only choice'])->getModalPresentation()->singleConfirmation)->toBeFalse();
  expect(fn() => new ModalPresentation('Invalid', 'Message', ['Yes', 'No'], 0, singleConfirmation: true))
    ->toThrow(InvalidArgumentException::class);
});

it('centers the Alert message on stage and its acknowledgement label in the bottom-right half', function (bool $art, bool $showInputHints, string $message) {
  $theme = new MenuPresentationCatalog($this->root, [...modalMenuTheme($art), 'showInputHints' => $showInputHints]);
  $snapshot = new AlertModal($this->game, $message, 'Alert')->getModalPresentation();
  $frame = MenuModalPresentation::compose(new PresentationCanvas(1350, 720), $snapshot, $theme);
  $layer = fn(string $id) => array_find($frame->textLayers, fn($text) => $text->id === $id);
  $box = $layer('menu-modal-backing')->clipRect;
  $title = $layer('menu-modal-title');
  $text = $layer('menu-modal-message');
  $button = $layer('menu-modal-choice-0-text');
  $m = $theme->metrics;
  $contentWidth = $box->width - 2 * $m->panelPadding;
  $bodyTop = $title->y + $title->bounds->height + $m->sectionGap;
  $bodyBottom = $button->clipRect->y - $m->sectionGap;
  $hints = $layer('menu-modal-hints');
  $footer = $showInputHints ? $hints->bounds->height + $m->sectionGap : 0;
  expect($button->clipRect->x)->toBe($box->x + $box->width / 2)
    ->and($button->clipRect->width)->toBe($contentWidth / 2)
    ->and($button->clipRect->y + $button->clipRect->height + $footer)->toBe($box->y + $box->height - $m->panelPadding)
    ->and($button->x + $button->bounds->width / 2)->toBe($button->clipRect->x + $button->clipRect->width / 2)
    ->and($text->y + $text->bounds->height / 2)->toBe(($bodyTop + $bodyBottom) / 2)
    ->and($bodyBottom - $bodyTop)->toBeGreaterThanOrEqual(3 * $m->cellHeight)
    ->and(implode('', array_column($text->runs, 'text')))->toBe($message)
    ->and($text->grid->cellWidth)->toBe($m->cellWidth)->and($text->grid->cellHeight)->toBe($m->cellHeight)
    ->and(count($frame->textLayers))->toBeLessThanOrEqual(64);
  foreach ($text->runs as $run) {
    expect($text->x + ($run->column + mb_strlen($run->text) / 2) * $m->cellWidth)
      ->toEqualWithDelta($box->x + $box->width / 2, $m->cellWidth / 2);
  }
  if (!$showInputHints) {
    expect($hints)->toBeNull()->and(array_filter(array_column($frame->images, 'id'),
      fn($id) => str_starts_with($id, 'menu-modal-hints')))->toBeEmpty();
  }
})->with([false, true])->with([false, true])->with(['Equipment optimized!', str_repeat('A complete longer message. ', 8)]);

it('keeps a long acknowledgement label complete and centered inside the bounded half-width button', function (bool $art) {
  $theme = new MenuPresentationCatalog($this->root, [...modalMenuTheme($art), 'showInputHints' => false]);
  $label = str_repeat('Acknowledge current result ', 4);
  $frame = MenuModalPresentation::compose(new PresentationCanvas(1350, 720),
    new ModalPresentation('Alert', 'Complete', [$label], 0, singleConfirmation: true), $theme);
  $box = array_find($frame->textLayers, fn($text) => $text->id === 'menu-modal-backing')->clipRect;
  $button = array_find($frame->textLayers, fn($text) => $text->id === 'menu-modal-choice-0-text');
  expect(implode('', array_column($button->runs, 'text')))->toBe($label)
    ->and($box->width)->toBeLessThanOrEqual(900)->and($box->height)->toBeLessThan(720)
    ->and($button->clipRect->width)->toBe(($box->width - 2 * $theme->metrics->panelPadding) / 2)
    ->and($button->y)->toBeGreaterThanOrEqual($button->clipRect->y)
    ->and($button->y + $button->bounds->height)->toBeLessThanOrEqual($button->clipRect->y + $button->clipRect->height);
  foreach ($button->runs as $run) {
    expect($button->x + ($run->column + mb_strlen($run->text) / 2) * $button->grid->cellWidth)
      ->toEqualWithDelta($button->clipRect->x + $button->clipRect->width / 2, $button->grid->cellWidth / 2);
  }
})->with([false, true]);

it('collapses only the hidden hint footer without changing Confirm or Select content layout', function (bool $art, string $type) {
  $snapshot = match ($type) {
    'confirm' => new ConfirmModal($this->game, "First line\nSecond line", 'Confirm')->getModalPresentation(),
    'select' => new SelectModal($this->game, "First line\nSecond line", ['One', 'Two'], 'Select')->getModalPresentation(),
    'single-select' => new SelectModal($this->game, "First line\nSecond line", ['Only choice'], 'Select')->getModalPresentation(),
  };
  $shownTheme = new MenuPresentationCatalog($this->root, modalMenuTheme($art));
  $hiddenTheme = new MenuPresentationCatalog($this->root, [...modalMenuTheme($art), 'showInputHints' => false]);
  $base = new PresentationCanvas(1350, 720);
  $shown = MenuModalPresentation::compose($base, $snapshot, $shownTheme);
  $hidden = MenuModalPresentation::compose($base, $snapshot, $hiddenTheme);
  $layer = fn(PresentationCanvas $frame, string $id) => array_find($frame->textLayers, fn($text) => $text->id === $id);
  $shownBox = $layer($shown, 'menu-modal-backing')->clipRect;
  $hiddenBox = $layer($hidden, 'menu-modal-backing')->clipRect;
  $hints = $layer($shown, 'menu-modal-hints');
  expect($shownBox->height - $hiddenBox->height)->toBe($hints->bounds->height + $shownTheme->metrics->sectionGap)
    ->and($layer($hidden, 'menu-modal-hints'))->toBeNull();
  foreach (['menu-modal-title', 'menu-modal-message', ...array_map(fn($index) => 'menu-modal-choice-' . $index . '-text', array_keys($snapshot->choices))] as $id) {
    $before = $layer($shown, $id);
    $after = $layer($hidden, $id);
    expect($after->x)->toBe($before->x)->and($after->bounds->width)->toBe($before->bounds->width)
      ->and($after->y - $hiddenBox->y)->toBe($before->y - $shownBox->y)->and($after->runs)->toEqual($before->runs);
  }
  $message = $layer($hidden, 'menu-modal-message');
  expect(array_column($message->runs, 'column'))->toBe([0, 0]);
  $contentWidth = $hiddenBox->width - 2 * $hiddenTheme->metrics->panelPadding;
  $button = $layer($hidden, 'menu-modal-choice-0-text');
  expect($button->clipRect->x)->toBe($hiddenBox->x + $hiddenTheme->metrics->panelPadding)
    ->and($button->clipRect->width)->toBe($snapshot->vertical ? $contentWidth : ($contentWidth - $hiddenTheme->metrics->sectionGap) / 2);
})->with([false, true])->with(['confirm', 'select', 'single-select']);

it('reserves moving cursors for vertical choice lists rather than alert or confirmation buttons', function (bool $art) {
  $theme = new MenuPresentationCatalog($this->root, modalMenuTheme($art));
  $base = new PresentationCanvas(1350, 720);
  foreach ([new ModalPresentation('', 'Complete.', ['OK'], 0, singleConfirmation: true),
    new ModalPresentation('Confirm', 'Continue?', ['Cancel', 'Continue'], 0)] as $snapshot) {
    $first = MenuModalPresentation::compose($base, $snapshot, $theme, 0);
    $next = MenuModalPresentation::compose($base, $snapshot, $theme, 0.6);
    expect($first->toArray())->toBe($next->toArray())
      ->and(array_filter($first->images, fn($image) => str_contains($image->id, '-cursor')))->toBeEmpty();
    $layers = [...$first->images, ...$first->textLayers];
    expect(array_filter($layers, fn($layer) => str_starts_with($layer->id, 'menu-modal-choice-0-focus')))->not->toBeEmpty();
  }
  $list = new ModalPresentation('Choose', '', ['To Title', 'Exit'], 0, vertical: true);
  $first = MenuModalPresentation::compose($base, $list, $theme, 0);
  $next = MenuModalPresentation::compose($base, $list, $theme, 0.6);
  $cursor = fn($frame) => array_find($frame->images, fn($image) => str_contains($image->id, '-cursor'));
  expect($cursor($first))->not->toBeNull()
    ->and($cursor($next)->destination->x)->toBeGreaterThan($cursor($first)->destination->x);
})->with([false, true]);

it('batches only fully visible nonoverlapping text without changing paint positions or clipping', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $layers = [];
  for ($i = 0; $i < 65; $i++) {
    $layers[] = new CanvasTextLayer('text-' . $i, 30, 5, $i * 10, new RendererGridConfig(4, 1, 10, 10),
      [new PresentationTextRun(0, 0, (string)$i, $theme->colors['text'])], new CanvasRectangle(5, $i * 10, 40, 10));
  }
  $batched = MenuCanvasTextBatch::compact($layers);
  expect($batched)->toHaveCount(64)->and(modalMenuForeground($batched))->toBe(modalMenuForeground($layers));
  $clipped = array_map(fn($text) => new CanvasTextLayer($text->id, $text->layer, $text->x, $text->y,
    $text->grid, $text->runs, new CanvasRectangle($text->x, $text->y, 10, 10)), $layers);
  expect(MenuCanvasTextBatch::compact($clipped))->toBe($clipped);
  $overlapping = array_map(fn($text) => new CanvasTextLayer($text->id, $text->layer, 0, 0, $text->grid, $text->runs), $layers);
  expect(MenuCanvasTextBatch::compact($overlapping))->toBe($overlapping);
});

it('batches default focus edges without changing any filled pixel or the centered command label', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $base = new PresentationCanvas(450, 100, textLayers: array_map(fn($i) => new CanvasTextLayer('base-' . $i, 0, 0, 0,
    new RendererGridConfig(1, 1, 10, 10), [new PresentationTextRun(0, 0, 'A', $theme->colors['text'])]), range(0, 60)));
  $overlay = MenuRowPainter::compose(450, 100, 'menu-modal-choice', [new MenuRow('0', 'OK', kind: MenuRowKind::COMMAND, focused: true)],
    new MenuRowLayout(new CanvasRectangle(10, 10, 360, 40)), $theme->rows);
  $frame = MenuCanvas::overlay($base, $overlay, $theme);
  $pixels = static function (array $layers): array {
    $pixels = [];
    foreach ($layers as $layer) {
      if (!str_contains($layer->id, '-focus')) { continue; }
      foreach ($layer->runs as $run) {
        for ($x = 0; $x < mb_strlen($run->text) * $layer->grid->cellWidth; $x++) {
          for ($y = 0; $y < $layer->grid->cellHeight; $y++) {
            $pixels[($layer->x + $run->column * $layer->grid->cellWidth + $x) . ':'
              . ($layer->y + $run->row * $layer->grid->cellHeight + $y)] = [$run->background->toArray(), $layer->opacity];
          }
        }
      }
    }
    ksort($pixels);
    return $pixels;
  };
  expect($pixels($frame->textLayers))->toBe($pixels($overlay->textLayers))->and(count($frame->textLayers))->toBeLessThanOrEqual(64);
  $label = array_find($frame->textLayers, fn($text) => $text->id === 'menu-modal-choice-0-text');
  expect($label->x + $label->bounds->width / 2)->toBe(190.0);
});
