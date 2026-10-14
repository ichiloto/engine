<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\Core\ProjectState\ProjectStateParticipantInterface;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ActionHintProvider;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Scenes\Game\States\SaveMenuState;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\SaveLoadMenuPresentation;
use Ichiloto\Engine\UI\Presentation\TitlePresentationCatalog;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Windows\SaveSlotMenuLayout;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

require_once dirname(__DIR__) . '/Support/Input/FakeInputSource.php';

final class SaveCatalogProjectConfig extends ProjectConfig
{
  protected function load(): array { return $this->options; }
  public function persist(): void {}
}

final class SaveCatalogSaveMenuProbe extends SaveMenuState
{
  public function openMenu(): void { $this->enter(); }
  public function selectSlot(int $index): void { $this->activeSlotIndex = $index; $this->render(); }
  public function getSlots(): array { return $this->slots; }
  public function getSelectedIndex(): int { return $this->activeSlotIndex; }
  public function getWindows(): array { return [$this->infoWindow, ...$this->slotWindows, $this->helpWindow]; }
  public function getDrawnText(): string { return TerminalText::stripAnsi(implode("\n", Console::getBuffer())); }
  public function composeCanvas(MenuPresentationCatalog $theme): \Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas
  {
    return $this->composeMenuCanvas($theme, 0);
  }
}

final class SaveCatalogGame extends Game
{
  public function __construct(private readonly string $root) {}
  public function __destruct() {}
  public function getDataRoot(): string { return $this->root; }
  public function hasIsolatedDataRoot(): bool { return true; }
}

final class SaveCatalogScene extends GameScene
{
  public bool $unstable = false;
  public int $checkpoint = 0;
  public bool $invalidState = false;
  public function __construct() { $this->party = new Party(); }
  public function hasUnstableEventSession(): bool { return $this->unstable; }
  public function createSnapshot(int $playTimeSeconds = 0): GameConfig
  {
    return new GameConfig('fixture-map', $this->party, new Vector2(1, 1), new Rect(0, 0, 1, 1),
      MovementHeading::SOUTH, playTimeSeconds: 17, projectState: [
        'fixture/checkpoint' => ['version' => 1, 'data' => $this->invalidState
          ? ['invalid' => new stdClass()] : ['checkpoint' => $this->checkpoint]],
      ]);
  }
}

final class SaveCatalogParticipant implements ProjectStateParticipantInterface
{
  public string $namespace { get => 'fixture/checkpoint'; }
  public int $version { get => 1; }
  public function migrateState(array $data, int $fromVersion): array { return $data; }
  public function validateState(array $data): void {}
}

final class SaveCatalogHydrationProbe
{
  public static int $hydrations = 0;
  public function __wakeup(): void { self::$hydrations++; }
}

final class SaveCatalogTitleProbe extends TitleScene
{
  public function __construct(Game $game, SaveManager $saveManager)
  {
    $this->name = 'Synthetic Title';
    $this->sceneManager = new class($game, $saveManager) extends SceneManager {
      public ?GameConfig $loaded = null;
      public int $loads = 0;
      public function __construct(Game $game, SaveManager $saveManager)
      {
        $this->game = $game;
        $this->saveManager = $saveManager;
      }
      public function loadSavedGame(GameConfig $config): GameScene
      {
        $this->loads++;
        $this->loaded = $config;
        return new SaveCatalogScene();
      }
    };
  }
  public function selectSlot(int $index): void
  {
    $this->activeContinueSlotIndex = $index;
    $this->renderContinueMenu();
  }
  public function loadSelectedSlot(): void { $this->loadActiveContinueSlot(); }
  public function getSlots(): array { return $this->continueSlots; }
  public function getSelectedIndex(): int { return $this->activeContinueSlotIndex; }
  public function getLoadCount(): int { return $this->sceneManager->loads; }
  public function getLoadedConfig(): ?GameConfig { return $this->sceneManager->loaded; }
  public function getDrawnText(): string { return TerminalText::stripAnsi(implode("\n", Console::getBuffer())); }
  public function enableGraphicalPresentation(): void
  {
    $root = dirname(__DIR__) . '/Fixtures/Renderer';
    $this->getGame()->useRendererRuntime(new RendererRuntime(new RendererRuntimeConfig(
      new RendererProcessConfig(['/never-started']), $root,
    )));
    $this->menu = new TitleMenu($this, 'Synthetic Title');
    $this->resetTitlePresentation();
    $catalog = new TitlePresentationCatalog($root, [
      'schema' => 'ichiloto.title/1', 'logo' => 'test-sprite.png',
      'theme' => ['schema' => 'ichiloto.menu/1', 'showInputHints' => false],
      'scenes' => ['day' => ['background' => 'test-sprite.png'], 'night' => ['background' => 'test-sprite.png']],
    ]);
    new ReflectionProperty(TitleScene::class, 'titleCatalog')->setValue($this, $catalog);
    new ReflectionProperty(TitleScene::class, 'titleCatalogLoaded')->setValue($this, true);
  }
}

function createSaveCatalogManifest(): SaveCompatibilityManifest
{
  return SaveCompatibilityManifest::fromArray('fixture/catalog', ['contentVersion' => 0, 'migrations' => [],
    'aliases' => [], 'tombstones' => []], 'synthetic save catalog');
}

function createSaveCatalogManager(Game $game): SaveManager
{
  return new SaveManager($game, 'saves', 'saves/quick', createSaveCatalogManifest());
}

function readSaveCatalogEnvelope(string $path): array
{
  return unserialize(gzdecode(substr(file_get_contents($path), 4)), ['allowed_classes' => true]);
}

function writeSaveCatalogEnvelope(string $path, array $envelope): void
{
  if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
  file_put_contents($path, 'IED1' . gzencode(serialize($envelope)));
}

function getSaveCatalogCheckpoint(SaveManager $manager, string $path): int
{
  return $manager->loadSaveFile($path)->config->projectState['fixture/checkpoint']['data']['checkpoint'];
}

beforeEach(function () {
  $this->root = createTestDirectory('ichiloto-save-catalog-');
  $this->statics = [];
  foreach ([Debug::class, ConfigStore::class, Time::class, Console::class, SaveManager::class, GameLoader::class,
    ActionHints::class, InputManager::class, AudioManager::class, ModalManager::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Debug::configure(['log_directory' => $this->root . '/logs']);
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  // This catalog fixture deliberately exercises a small project capacity.
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['saves' => ['manual_slots' => 5], 'save' => ['autosave' => true]]));
  $this->game = new SaveCatalogGame($this->root);
  $this->game->registerProjectStateParticipant(new SaveCatalogParticipant());
  $this->manager = createSaveCatalogManager($this->game);
  $this->scene = new SaveCatalogScene();
});

afterEach(function () {
  foreach ($this->statics as $class => $properties) {
    foreach ($properties as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
});

it('inspects manual automatic and quick records without restoring state or writing player data', function () {
  $this->scene->checkpoint = 20;
  $this->manager->save($this->scene, 2);
  $this->scene->checkpoint = 30;
  $auto = $this->manager->autoSave($this->scene);
  $this->scene->checkpoint = 40;
  $quick = $this->manager->quickSave($this->scene);
  $registry = $this->game->getProjectStateRegistry();
  $registry->replaceStates(['fixture/checkpoint' => ['checkpoint' => 99]]);
  $files = $this->manager->getSaveFiles(true);
  $bytes = array_map(file_get_contents(...), $files);
  $time = new ReflectionClass(Time::class)->getStaticProperties();
  $settings = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  $slots = $this->manager->getLoadSlots();
  expect($slots)->toHaveCount(7)
    ->and(array_column($slots, 'slot'))->toBe([SaveManager::AUTO_SAVE_SLOT, 1, 2, 3, 4, 5, SaveManager::QUICK_SAVE_SLOT])
    ->and(array_map(fn($slot) => $slot->getLabel(), $slots))->toBe([
      'Autosave', 'File 1', 'File 2', 'File 3', 'File 4', 'File 5', 'Quick Save',
    ])
    ->and($slots[6]->path)->toBe($quick->path)->and($slots[0]->path)->toBe($auto->path)
    ->and($registry->getState('fixture/checkpoint'))->toBe(['checkpoint' => 99])
    ->and(new ReflectionClass(Time::class)->getStaticProperties())->toBe($time)
    ->and(new ReflectionClass(ConfigStore::class)->getStaticProperties())->toBe($settings)
    ->and($this->manager->getSaveFiles(true))->toBe($files)
    ->and(array_map(file_get_contents(...), $files))->toBe($bytes)
    ->and($this->manager->getSaveSlots())->toHaveCount(5);
});

it('leaves missing save directories absent while inspecting the load catalog', function () {
  expect($this->manager->getLoadSlots())->toHaveCount(6)
    ->and($this->manager->hasSaveFiles(true))->toBeFalse()
    ->and(is_dir($this->root . '/saves'))->toBeFalse();
});

it('keeps bad automatic and quick entries visible disabled and skips them for latest loadable', function () {
  $good = $this->manager->save($this->scene, 1);
  $quick = $this->manager->quickSave($this->scene);
  $future = readSaveCatalogEnvelope($quick->path);
  $future['schemaVersion'] = 100;
  writeSaveCatalogEnvelope($quick->path, $future);
  $auto = $this->manager->getQuickSavePath('auto-01');
  file_put_contents($auto, 'IED1not-gzip');
  touch($auto, time() + 3600);
  $slots = $this->manager->getLoadSlots();
  expect($slots[0]->slot)->toBe(SaveManager::AUTO_SAVE_SLOT)->and($slots[0]->isLoadable)->toBeFalse()
    ->and($slots[6]->slot)->toBe(SaveManager::QUICK_SAVE_SLOT)->and($slots[6]->isLoadable)->toBeFalse()
    ->and($slots[6]->statusMessage)->toContain('newer engine')
    ->and($this->manager->getLatestSaveFile(true))->toBe($auto)
    ->and($this->manager->getLatestLoadableSaveFile(true))->toBe($good->path);
});

it('removes rotating writes and replaces one checkpoint atomically across manager restarts', function () {
  $orders = [];
  foreach (range(1, 9) as $checkpoint) {
    $this->scene->checkpoint = $checkpoint;
    $manager = createSaveCatalogManager($this->game);
    $saved = $manager->autoSave($this->scene);
    $orders[] = readSaveCatalogEnvelope($saved->path)['saveOrder'];
    touch($saved->path, 1000);
    expect(basename($saved->path))->toBe('auto.iedata')
      ->and($manager->getLatestLoadableSaveFile(true))->toBe($saved->path)
      ->and(getSaveCatalogCheckpoint($manager, $saved->path))->toBe($checkpoint);
  }
  foreach (range(1, count($orders) - 1) as $index) { expect($orders[$index])->toBeGreaterThan($orders[$index - 1]); }
  expect($manager->getSaveFiles(true))->toHaveCount(1);
});

it('uses one persisted ordering for manual quick and auto saves and excludes quick files on request', function () {
  $manual = $this->manager->save($this->scene, 1);
  $quick = $this->manager->quickSave($this->scene);
  $auto = $this->manager->autoSave($this->scene);
  foreach ([$manual->path, $quick->path, $auto->path] as $path) { touch($path, 100); }
  expect($this->manager->getSaveFiles(true))->toBe([$auto->path, $quick->path, $manual->path])
    ->and($this->manager->getSaveFiles())->toBe([$manual->path]);
});

it('preserves legacy timestamps with deterministic ties and advances beyond future clock facts', function () {
  $futureTime = time() + 86400;
  $paths = [$this->manager->getSlotPath(1), $this->manager->getSlotPath(2)];
  foreach ($paths as $index => $path) {
    writeSaveCatalogEnvelope($path, ['slot' => new SaveSlot($index + 1, $path, false, 'Fixture'),
      'config' => $this->scene->createSnapshot()]);
    touch($path, $futureTime);
  }
  expect($this->manager->getSaveFiles())->toBe($paths);
  $saved = $this->manager->autoSave($this->scene);
  $order = readSaveCatalogEnvelope($saved->path)['saveOrder'];
  expect($order)->toBe($futureTime * 1_000_000 + 1)
    ->and(createSaveCatalogManager($this->game)->getLatestLoadableSaveFile(true))->toBe($saved->path);
  $next = createSaveCatalogManager($this->game)->quickSave($this->scene);
  expect(readSaveCatalogEnvelope($next->path)['saveOrder'])->toBe($order + 1);
});

it('selects the newest decodable legacy checkpoint without destructive migration', function () {
  $paths = [];
  foreach (range(1, 3) as $index) {
    $path = $this->manager->getQuickSavePath('auto-0' . $index);
    $this->scene->checkpoint = $index;
    writeSaveCatalogEnvelope($path, ['slot' => new SaveSlot(-2, $path, false, 'Legacy fixture'),
      'config' => $this->scene->createSnapshot(), 'saveOrder' => 100 + $index]);
    $paths[] = $path;
  }
  file_put_contents($paths[2], 'IED1not-gzip');
  touch($paths[2], time() + 3600);
  $bytes = array_map(file_get_contents(...), $paths);
  $slot = $this->manager->getLoadSlots()[0];
  expect($slot->path)->toBe($paths[1])->and($slot->getLabel())->toBe('Autosave')
    ->and($this->manager->getLatestLoadableSaveFile(true))->toBe($paths[1]);
  $saved = $this->manager->autoSave($this->scene);
  expect(basename($saved->path))->toBe('auto.iedata')
    ->and($this->manager->getLoadSlots()[0]->path)->toBe($saved->path)
    ->and(array_map(file_get_contents(...), $paths))->toBe($bytes)
    ->and($this->manager->getSaveFiles(true))->toHaveCount(4);
});

it('breaks duplicated persisted order ties deterministically and advances beyond all predecessors', function () {
  $manual = $this->manager->save($this->scene, 1);
  $quick = $this->manager->quickSave($this->scene);
  $order = (time() + 3600) * 1_000_000;
  foreach ([$quick->path, $manual->path] as $path) {
    $envelope = readSaveCatalogEnvelope($path);
    $envelope['saveOrder'] = $order;
    writeSaveCatalogEnvelope($path, $envelope);
  }
  expect($this->manager->getSaveFiles(true))->toBe([$manual->path, $quick->path]);
  $next = $this->manager->autoSave($this->scene);
  expect(readSaveCatalogEnvelope($next->path)['saveOrder'])->toBe($order + 1);
});

it('orders files without invoking saved object hydration', function () {
  SaveCatalogHydrationProbe::$hydrations = 0;
  $path = $this->manager->getSlotPath(1);
  writeSaveCatalogEnvelope($path, ['saveOrder' => 100, 'probe' => new SaveCatalogHydrationProbe()]);
  expect($this->manager->getSaveFiles())->toBe([$path])
    ->and(SaveCatalogHydrationProbe::$hydrations)->toBe(0);
});

it('does not treat a decoded disabled or empty descriptor as the latest loadable save', function () {
  $good = $this->manager->save($this->scene, 1);
  $quick = $this->manager->quickSave($this->scene);
  $envelope = readSaveCatalogEnvelope($quick->path);
  foreach ([SaveSlot::empty(SaveManager::QUICK_SAVE_SLOT, $quick->path),
    SaveSlot::incompatible(SaveManager::QUICK_SAVE_SLOT, $quick->path, 'Synthetic refusal')] as $slot) {
    $envelope['payload']['slot'] = $slot;
    writeSaveCatalogEnvelope($quick->path, $envelope);
    expect($this->manager->canLoadSaveFile($quick->path))->toBeFalse()
      ->and($this->manager->getLatestLoadableSaveFile(true))->toBe($good->path);
  }
});

it('rejects symlinked storage outside the isolated data root during catalog and ring inspection', function () {
  $outside = createTestDirectory('ichiloto-save-outside-');
  mkdir($this->root . '/saves');
  symlink($outside, $this->root . '/saves/quick');
  expect(fn() => $this->manager->getLoadSlots())->toThrow(InvalidArgumentException::class)
    ->and(fn() => $this->manager->autoSave($this->scene))->toThrow(InvalidArgumentException::class);
});

it('keeps a good checkpoint and its order after snapshot validation refuses a replacement', function () {
  $saved = $this->manager->quickSave($this->scene);
  $bytes = file_get_contents($saved->path);
  $this->scene->invalidState = true;
  expect(fn() => $this->manager->quickSave($this->scene))->toThrow(RuntimeException::class)
    ->and(file_get_contents($saved->path))->toBe($bytes)
    ->and($this->manager->getLatestLoadableSaveFile(true))->toBe($saved->path)
    ->and(glob(dirname($saved->path) . '/.*.tmp'))->toBe([]);
});

it('preserves a good checkpoint when storage refuses an atomic replacement', function () {
  $saved = $this->manager->quickSave($this->scene);
  $bytes = file_get_contents($saved->path);
  $directory = dirname($saved->path);
  chmod($directory, 0500);
  try {
    expect(fn() => $this->manager->quickSave($this->scene))->toThrow(RuntimeException::class)
      ->and(file_get_contents($saved->path))->toBe($bytes)
      ->and($this->manager->getLatestLoadableSaveFile(true))->toBe($saved->path)
      ->and(glob($directory . '/.*.tmp'))->toBe([]);
  } finally { chmod($directory, 0700); }
});

it('retains the unstable-event save guard for every writer', function () {
  $this->scene->unstable = true;
  expect(fn() => $this->manager->save($this->scene, 1))->toThrow(ActiveEventSaveException::class)
    ->and(fn() => $this->manager->quickSave($this->scene))->toThrow(ActiveEventSaveException::class)
    ->and(fn() => $this->manager->autoSave($this->scene))->toThrow(ActiveEventSaveException::class)
    ->and($this->manager->getSaveFiles(true))->toBe([]);
});

it('pages every title load record inside the Terminal viewport and relayouts after resize', function (int $height) {
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, $height);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => $height]));
  foreach (range(1, 3) as $checkpoint) { $this->manager->autoSave($this->scene); }
  $this->manager->quickSave($this->scene);
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $title->openContinueMenu();
  expect($title->getSelectedIndex())->toBe(6)->and($title->getSlots())->toHaveCount(7);
  foreach ($title->getSlots() as $index => $slot) {
    $title->selectSlot($index);
    expect($title->getDrawnText())->toContain($slot->getLabel())
      ->and(count(Console::getBuffer()))->toBeLessThanOrEqual($height);
    $visibleCount = (new SaveSlotMenuLayout(80, $height, 7))->visibleCount;
    $first = min(intdiv($index, $visibleCount) * $visibleCount, max(0, 7 - $visibleCount));
    expect($title->getDrawnText())->toContain(($first + 1) . '-' . min(7, $first + $visibleCount) . ' / 7');
  }
  Console::syncDimensions(60, 15);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 60, 'height' => 15]));
  $title->selectSlot(6);
  expect($title->getDrawnText())->toContain($title->getSlots()[6]->getLabel(), '6-7 / 7')
    ->and(count(Console::getBuffer()))->toBeLessThanOrEqual(15);
})->with([8, 11, 19, 36]);

it('loads an automatic or quick Title selection through the existing saved-game dispatch', function (string $writer) {
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 36]));
  $this->scene->checkpoint = 77;
  $saved = $this->manager->$writer($this->scene);
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $loader = new ReflectionClass(GameLoader::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(GameLoader::class, 'game')->setValue($loader, $this->game);
  new ReflectionProperty(GameLoader::class, 'instance')->setValue(null, $loader);
  new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $this->manager);
  $title->openContinueMenu();
  $index = $writer === 'autoSave' ? 0 : 6;
  expect($title->getSlots()[$index]->path)->toBe($saved->path)->and($title->getLoadCount())->toBe(0);
  $title->selectSlot($index);
  $title->loadSelectedSlot();
  expect($title->getLoadCount())->toBe(1)
    ->and($title->getLoadedConfig()->projectState['fixture/checkpoint']['data'])->toBe(['checkpoint' => 77])
    ->and($title->getLoadedConfig()->playTimeSeconds)->toBe(17);
})->with(['autoSave', 'quickSave']);

it('does not dispatch empty or incompatible Title selections', function () {
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 36]));
  $quick = $this->manager->quickSave($this->scene);
  file_put_contents($quick->path, 'bad-header');
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $title->openContinueMenu();
  $title->selectSlot(0);
  $title->loadSelectedSlot();
  expect($title->getDrawnText())->toContain('That file is empty.');
  $title->selectSlot(5);
  $title->loadSelectedSlot();
  expect($title->getLoadCount())->toBe(0)->and($title->getDrawnText())->toContain('Invalid save file header');
});

it('keeps semantic controller hints and status readable in the compact load viewport', function () {
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 8);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 8]));
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint
    {
      return new ControlHint('gamepad', $action, $action === 'confirm' ? 'A' : 'B');
    }
  });
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $title->openContinueMenu();
  $title->loadSelectedSlot();
  expect($title->getDrawnText())->toContain('A: Load', 'B: Back', 'That file is empty.', '1-1 / 6')
    ->not->toContain('Enter', 'Esc')->and(count(Console::getBuffer()))->toBe(8);
});

it('scrolls the full Title catalog with shared labels and unique automatic identities in both themes', function (bool $alternative) {
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 19);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 19]));
  foreach (range(1, 3) as $checkpoint) { $this->manager->autoSave($this->scene); }
  $this->manager->quickSave($this->scene);
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $title->openContinueMenu();
  $title->enableGraphicalPresentation();
  $theme = new MenuPresentationCatalog($this->root, [
    'schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'metrics' => $alternative ? ['cellWidth' => 8, 'cellHeight' => 22, 'panelPadding' => 12] : [],
    'colors' => $alternative ? ['text' => [30, 50, 40], 'panel' => [220, 230, 210], 'accent' => [150, 50, 20]] : [],
  ]);
  foreach ($title->getSlots() as $index => $slot) {
    $title->selectSlot($index);
    $frame = SaveLoadMenuPresentation::compose($title->getSlots(), $title->getSelectedIndex(), $theme, new MenuInfoText());
    $text = implode('', array_map(static fn($layer) => implode('', array_map(static fn($run) =>
      $run->foreground === null ? '' : $run->text, $layer->runs)), $frame->textLayers));
    $ids = [...array_column($frame->textLayers, 'id'), ...array_column($frame->images, 'id')];
    expect($text)->toContain($slot->getLabel(), '/ 7')
      ->not->toContain('File -1', 'File -2')->and(array_unique($ids))->toHaveCount(count($ids));
    $recordId = 'save-slot-' . $slot->slot . ($slot->slot <= 0 ? '-' . hash('sha256', $slot->path) : '');
    expect($ids)->toContain($recordId . '-title');
    $reordered = SaveLoadMenuPresentation::compose(array_reverse($title->getSlots()),
      count($title->getSlots()) - 1 - $index, $theme, new MenuInfoText());
    expect(array_column($reordered->textLayers, 'id'))->toContain($recordId . '-title');
    foreach ($frame->textLayers as $layer) {
      expect($layer->clipRect->y)->toBeGreaterThanOrEqual(0)
        ->and($layer->clipRect->y + $layer->clipRect->height)->toBeLessThanOrEqual($frame->height);
    }
    $titleFrame = $title->getPresentationCanvas();
    expect($titleFrame)->not->toBeNull();
    $titleText = implode('', array_map(static fn($layer) => implode('', array_map(static fn($run) =>
      $run->foreground === null ? '' : $run->text, $layer->runs)), $titleFrame->textLayers));
    expect($titleText)->toContain($slot->getLabel(), '/ 7');
  }
})->with([false, true]);

function createSaveCapacityMenu(SaveCatalogScene $scene, SaveCatalogTitleProbe $title): SaveCatalogSaveMenuProbe
{
  new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($scene, $title->sceneManager);
  new ReflectionProperty(GameScene::class, 'locationHUDWindow')->setValue($scene,
    makeBareScene(\Ichiloto\Engine\UI\Elements\LocationHUDWindow::class));
  return new SaveCatalogSaveMenuProbe(new SceneStateContext($scene));
}

it('defaults both catalogs to thirty manual slots with no project setting', function (bool $registered) {
  ConfigStore::remove(ProjectConfig::class);
  if ($registered) { ConfigStore::put(ProjectConfig::class, new SaveCatalogProjectConfig()); }
  $slots = $this->manager->getSaveSlots();
  expect($this->manager->getManualSlotCount())->toBe(30)
    ->and(array_column($slots, 'slot'))->toBe(range(1, 30))
    ->and($this->manager->getLoadSlots())->toHaveCount(30)
    ->and(basename($slots[29]->path))->toBe('file-30.iedata');
})->with([false, true]);

it('applies capacity changes to both menus without moving or rewriting legacy saves', function () {
  $config = new SaveCatalogProjectConfig(['saves' => ['manual_slots' => 30]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $bytes = [];
  foreach (range(1, 5) as $number) {
    $path = $this->manager->getSlotPath($number);
    writeSaveCatalogEnvelope($path, ['slot' => new SaveSlot($number, '/legacy/path', false, 'Legacy fixture'),
      'config' => $this->scene->createSnapshot()]);
    $bytes[$path] = file_get_contents($path);
  }
  $saved = $this->manager->save($this->scene, 30);
  $bytes[$saved->path] = file_get_contents($saved->path);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 19);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 19]));
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $menu = createSaveCapacityMenu($this->scene, $title);
  $menu->openMenu();
  $title->openContinueMenu();
  $menu->selectSlot(29);
  foreach ([7, 40, 1, 30] as $count) {
    $config->set('saves.manual_slots', $count);
    $menu->resume();
    $title->selectSlot(0);
    expect($menu->getSlots())->toHaveCount($count)->and($title->getSlots())->toHaveCount($count)
      ->and($menu->getSelectedIndex())->toBeLessThan($count)
      ->and($this->manager->loadSlot(30)->slot->slot)->toBe(30);
    foreach ($bytes as $path => $contents) {
      expect(file_get_contents($path))->toBe($contents);
    }
    foreach (range(1, 5) as $number) {
      expect($this->manager->loadSlot($number)->slot->slot)->toBe($number)
        ->and($this->manager->loadSlot($number)->slot->path)->toBe($this->manager->getSlotPath($number));
    }
  }
  expect($this->manager->getSaveSlots(3))->toHaveCount(3);
});

it('refuses invalid project capacities rather than silently using a different menu count', function (mixed $count) {
  ConfigStore::put(ProjectConfig::class, new SaveCatalogProjectConfig(['saves' => ['manual_slots' => $count]]));
  expect(fn() => $this->manager->getSaveSlots())->toThrow(InvalidArgumentException::class)
    ->and(fn() => $this->manager->getLoadSlots())->toThrow(InvalidArgumentException::class);
})->with([0, -1, 1.5, '30', true, [[]]]);

it('pages all thirty Save rows within the Terminal viewport including resize and a partial last page', function (int $height) {
  ConfigStore::put(ProjectConfig::class, new SaveCatalogProjectConfig());
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, $height);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => $height]));
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $menu = createSaveCapacityMenu($this->scene, $title);
  $menu->openMenu();
  $layout = new SaveSlotMenuLayout(80, $height, 30);
  foreach (range(0, 29) as $index) {
    $menu->selectSlot($index);
    [$first, $last] = $layout->getVisibleRange($index);
    expect($menu->getDrawnText())->toContain('File ' . ($index + 1), ($first + 1) . '-' . $last . ' / 30')
      ->and(count(Console::getBuffer()))->toBeLessThanOrEqual($height);
    foreach ($menu->getWindows() as $window) {
      $bounds = $window->getBounds();
      expect($bounds->getY())->toBeGreaterThanOrEqual(0)
        ->and($bounds->getY() + $bounds->getHeight())->toBeLessThanOrEqual($height);
    }
    expect(count($menu->getWindows()))->toBe($layout->visibleCount + 2);
  }
  Console::syncDimensions(60, 15);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 60, 'height' => 15]));
  $menu->selectSlot(29);
  expect($menu->getDrawnText())->toContain('File 30', '29-30 / 30')
    ->and(count(Console::getBuffer()))->toBeLessThanOrEqual(15);
  $menu->selectSlot(0);
  expect($menu->getDrawnText())->not->toContain('File 30');
})->with([8, 11, 19, 36]);

it('navigates through thirty Save slots wraps both ends and confirms the actual slot thirty path', function () {
  ConfigStore::put(ProjectConfig::class, new SaveCatalogProjectConfig());
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 19);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 19]));
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $menu = createSaveCapacityMenu($this->scene, $title);
  $menu->openMenu();
  InputManager::setBindings(['down' => ['keys' => [KeyCode::DOWN]], 'up' => ['keys' => [KeyCode::UP]],
    'confirm' => ['keys' => [KeyCode::ENTER]]]);
  $press = function (KeyCode $key) use ($menu): void {
    InputManager::setInputSource(new \Tests\Support\Input\FakeInputSource($key));
    InputManager::handleInput();
    $menu->execute();
  };
  foreach (range(1, 30) as $step) {
    $press(KeyCode::DOWN);
    expect($menu->getSelectedIndex())->toBe($step % 30);
  }
  $press(KeyCode::UP);
  expect($menu->getSelectedIndex())->toBe(29)->and($menu->getDrawnText())->toContain('File 30');
  $audio = new class extends AudioManager {
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void {}
  };
  new ReflectionProperty(Game::class, 'audioManager')->setValue($this->game, $audio);
  $alerts = new class extends ModalManager {
    public array $messages = [];
    public function __construct() {}
    public function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void
    {
      $this->messages[] = $message;
    }
  };
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $alerts);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $press(KeyCode::ENTER);
  expect($this->manager->loadSlot(30)->slot->slot)->toBe(30)
    ->and(basename($this->manager->getSaveFiles()[0]))->toBe('file-30.iedata')
    ->and($alerts->messages)->toBe(['Saved to File 30.']);
  $loader = new ReflectionClass(GameLoader::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(GameLoader::class, 'game')->setValue($loader, $this->game);
  new ReflectionProperty(GameLoader::class, 'instance')->setValue(null, $loader);
  new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $this->manager);
  $title->openContinueMenu();
  expect($title->getSelectedIndex())->toBe(29)->and($title->getSlots()[29]->slot)->toBe(30);
  $title->loadSelectedSlot();
  expect($title->getLoadCount())->toBe(1)->and($title->getLoadedConfig()->playTimeSeconds)->toBe(17);
});

it('keeps semantic Save and Back hints readable in the smallest supported viewport', function () {
  ConfigStore::put(ProjectConfig::class, new SaveCatalogProjectConfig());
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 8);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 8]));
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint
    {
      return new ControlHint('gamepad', $action, $action === 'confirm' ? 'A' : 'B');
    }
  });
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $menu = createSaveCapacityMenu($this->scene, $title);
  $menu->openMenu();
  $menu->selectSlot(29);
  expect($menu->getDrawnText())->toContain('A: Save', 'B: Back', 'File 30', '30-30 / 30')
    ->not->toContain('Enter', 'Esc')->and(count(Console::getBuffer()))->toBe(8);
});

it('keeps thirty manual slots and load-only checkpoints reachable in graphical Save and Load', function (bool $alternative) {
  ConfigStore::put(ProjectConfig::class, new SaveCatalogProjectConfig());
  ConfigStore::get(ProjectConfig::class)->set('save.autosave', true);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 19);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 19]));
  foreach (range(1, 3) as $index) { $this->manager->autoSave($this->scene); }
  $this->manager->quickSave($this->scene);
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $menu = createSaveCapacityMenu($this->scene, $title);
  $menu->openMenu();
  $title->openContinueMenu();
  expect($menu->getSlots())->toHaveCount(30)->and($title->getSlots())->toHaveCount(32);
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'metrics' => $alternative ? ['cellWidth' => 8, 'cellHeight' => 22, 'panelPadding' => 12] : []]);
  foreach ($title->getSlots() as $index => $slot) {
    $title->selectSlot($index);
    $frame = SaveLoadMenuPresentation::compose($title->getSlots(), $index, $theme, new MenuInfoText());
    $text = implode('', array_map(static fn($layer) => implode('', array_column($layer->runs, 'text')), $frame->textLayers));
    expect($text)->toContain($slot->getLabel(), '/ 32')
      ->and($title->getDrawnText())->toContain($slot->getLabel());
    if ($slot->slot > 0) {
      $menu->selectSlot($slot->slot - 1);
      $save = $menu->composeCanvas($theme);
      $text = implode('', array_map(static fn($layer) => implode('', array_column($layer->runs, 'text')), $save->textLayers));
      expect($text)->toContain($slot->getLabel(), '/ 30')->not->toContain('Autosave', 'Quick Save');
      expect(array_column($save->textLayers, 'id'))->toContain('save-slot-' . $slot->slot . '-title');
    }
    foreach ($frame->textLayers as $layer) {
      expect($layer->clipRect->y)->toBeGreaterThanOrEqual(0)
        ->and($layer->clipRect->y + $layer->clipRect->height)->toBeLessThanOrEqual($frame->height);
    }
  }
})->with([false, true]);


it('shares five-slot pages at ordinary Terminal and graphical sizes in both themes', function (bool $alternative) {
  $this->manager->save($this->scene, 1);
  $layout = new SaveSlotMenuLayout(80, 24, 30);
  expect($layout->visibleCount)->toBe(5)->and($layout->slotHeight)->toBe(3);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 24);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
  ConfigStore::put(ProjectConfig::class, new SaveCatalogProjectConfig());
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $menu = createSaveCapacityMenu($this->scene, $title);
  $menu->openMenu();
  expect(count($menu->getWindows()))->toBe(7)->and($menu->getDrawnText())->toContain('1-5 / 30', 'File 5', 'Unknown', '00:00:17');
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'metrics' => $alternative ? ['cellWidth' => 8, 'cellHeight' => 22, 'panelPadding' => 12] : []]);
  $frame = $menu->composeCanvas($theme);
  $titles = array_filter($frame->textLayers, fn($layer) => str_starts_with($layer->id, 'save-slot-') && str_ends_with($layer->id, '-title'));
  expect($titles)->toHaveCount(5);
})->with([false, true]);

it('honors every developer save capability combination for writes catalog Continue input and hints', function (bool $auto, bool $quick) {
  // Seed legacy files while both mechanisms are enabled, then change game capabilities.
  $this->manager->autoSave($this->scene);
  $this->manager->quickSave($this->scene);
  $bytes = array_map(file_get_contents(...), $this->manager->getSaveFiles(true));
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['save' => ['autosave' => $auto, 'quicksave' => $quick]]));
  $slots = $this->manager->getLoadSlots(5);
  expect(array_column($slots, 'slot'))->toBe([
    ...($auto ? [SaveManager::AUTO_SAVE_SLOT] : []), 1, 2, 3, 4, 5,
    ...($quick ? [SaveManager::QUICK_SAVE_SLOT] : []),
  ]);
  expect($this->manager->getLatestLoadableSaveFile(true))->toBe($quick ? $this->manager->getQuickSavePath('quick')
    : ($auto ? $this->manager->getQuickSavePath('auto') : null));
  InputManager::setBindings(['quick_save' => ['description' => 'Quick Save', 'keys' => [KeyCode::q]]]);
  InputManager::setInputSource(new \Tests\Support\Input\FakeInputSource(KeyCode::q));
  InputManager::handleInput();
  expect(InputManager::isButtonDown('quick_save'))->toBe($quick)
    ->and(ActionHints::resolve('quick_save', 'Quick Save')->control !== null)->toBe($quick)
    ->and(in_array('quick_save', array_column((new \Ichiloto\Engine\IO\InputBindings())->all(), 'action'), true))->toBe($quick);
  if (!$auto) { expect(fn() => $this->manager->autoSave($this->scene))->toThrow(RuntimeException::class, 'disabled'); }
  if (!$quick) { expect(fn() => $this->manager->quickSave($this->scene))->toThrow(RuntimeException::class, 'disabled'); }
  expect(array_map(file_get_contents(...), $this->manager->getSaveFiles(true)))->toBe($bytes);
})->with([false, true])->with([false, true]);

it('refuses nonboolean developer save capabilities', function (mixed $value) {
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['save' => ['autosave' => $value, 'quicksave' => $value]]));
  expect(fn() => $this->manager->getLoadSlots())->toThrow(InvalidArgumentException::class)
    ->and(fn() => $this->manager->quickSave($this->scene))->toThrow(InvalidArgumentException::class);
})->with([[0], [1], ['false'], [[]]]);


it('keeps populated tail pages and navigable selection for any catalog size', function (int $count) {
  $capacity = 5;
  foreach (range(0, max(0, $count - 1)) as $index) {
    [$first, $last] = \Ichiloto\Engine\UI\Windows\SaveSlotPage::getVisibleRange($count, $index);
    expect($last - $first)->toBe(min(5, $count));
    if ($count > 0) {
      expect($first)->toBeLessThanOrEqual($index)->and($last)->toBeGreaterThan($index);
      foreach ([-1, 1] as $direction) {
        $next = \Ichiloto\Engine\UI\Windows\SaveSlotPage::getAdjacentIndex($count, $index, $direction);
        expect($next)->toBe(max(0, min($count - 1, $index + $direction * $capacity)));
      }
    }
  }
})->with([0, 1, 30, 31, 32]);

it('keeps full long selected metadata accessible through Info while removing wrapped graphical card rows', function (bool $alternative, bool $bad) {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'metrics' => $alternative ? ['cellWidth' => 8, 'cellHeight' => 22, 'panelPadding' => 12] : []]);
  $full = str_repeat('Long location and identity ', 30) . 'FINAL METADATA';
  $slot = new SaveSlot(1, '/synthetic/save', false, $bad ? 'Incompatible Save' : $full,
    leaderName: str_repeat('Long leader ', 20), leaderLevel: 123, isLoadable: !$bad, statusMessage: $bad ? $full : null);
  $slots = [$slot, ...array_slice($this->manager->getSaveSlots(), 1)];
  $info = new MenuInfoText();
  $seen = '';
  for ($page = 0; $page < 30; $page++) {
    $frame = SaveLoadMenuPresentation::compose($slots, 0, $theme, $info);
    $seen .= implode('', array_map(fn($layer) => implode('', array_column($layer->runs, 'text')), $frame->textLayers));
    foreach ($frame->textLayers as $layer) {
      expect($layer->clipRect->y + $layer->clipRect->height)->toBeLessThanOrEqual($frame->height);
    }
    if (!$info->advance()) { break; }
  }
  expect($seen)->toContain('FINAL METADATA')->and($info->lastPage->source)->toContain($full);
})->with([false, true])->with([false, true]);


it('refuses invalid save page bounds instead of constructing unsafe navigation ranges', function (int $count, int $capacity) {
  expect(fn() => \Ichiloto\Engine\UI\Windows\SaveSlotPage::getVisibleRange($count, 0, $capacity))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => \Ichiloto\Engine\UI\Windows\SaveSlotPage::getAdjacentIndex($count, 0, 1, $capacity))
    ->toThrow(InvalidArgumentException::class);
})->with([[-1, 5], [30, 0], [30, 6]]);


it('keeps enabled corrupt records discoverable at Title but prevents direct GameOver Continue', function (bool $corrupt, bool $enabled) {
  $saved = $this->manager->quickSave($this->scene);
  if ($corrupt) { file_put_contents($saved->path, 'IED1broken'); }
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['save' => ['autosave' => false, 'quicksave' => $enabled]]));
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 24);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
  new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $this->manager);
  $loader = new ReflectionClass(GameLoader::class)->newInstanceWithoutConstructor();
  $title = new SaveCatalogTitleProbe($this->game, $this->manager);
  $titleMenu = new TitleMenu($title, 'Synthetic Title');
  new ReflectionProperty(TitleScene::class, 'menu')->setValue($title, $titleMenu);
  $titleCommand = new \Ichiloto\Engine\Core\Menu\Commands\ContinueGameCommand($titleMenu, $loader);
  expect($titleCommand->isDisabled())->toBe(!$enabled);
  new ReflectionProperty(TitleScene::class, 'continueCommand')->setValue($title, $titleCommand);
  $title->syncContinueAvailability();
  expect($titleCommand->isDisabled())->toBe(!$enabled);
  $gameOver = new ReflectionClass(\Ichiloto\Engine\Scenes\GameOver\GameOverScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty($gameOver, 'sceneManager')->setValue($gameOver, $title->sceneManager);
  $overMenu = new \Ichiloto\Engine\Scenes\GameOver\Menus\GameOverMenu($gameOver, 'Synthetic Game Over');
  new ReflectionProperty($gameOver, 'menu')->setValue($gameOver, $overMenu);
  $overCommand = new \Ichiloto\Engine\Core\Menu\Commands\ContinueGameCommand($overMenu, $loader);
  expect($overCommand->isDisabled())->toBe(!$enabled || $corrupt);
  new ReflectionProperty($gameOver, 'continueCommand')->setValue($gameOver, $overCommand);
  new ReflectionMethod($gameOver, 'syncContinueAvailability')->invoke($gameOver);
  expect($overCommand->isDisabled())->toBe(!$enabled || $corrupt)
    ->and($this->manager->getSaveFiles(true))->toBe([$saved->path]);
  if ($enabled && $corrupt) {
    $slot = array_find($this->manager->getLoadSlots(), fn($slot) => $slot->slot === SaveManager::QUICK_SAVE_SLOT);
    expect($slot->isLoadable)->toBeFalse()->and($slot->statusMessage)->not->toBeNull();
  }
})->with([false, true])->with([false, true]);
