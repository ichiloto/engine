<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\Core\ProjectState\ProjectStateParticipantInterface;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
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
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\SaveLoadMenuPresentation;
use Ichiloto\Engine\UI\Presentation\TitlePresentationCatalog;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;

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
    ActionHints::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Debug::configure(['log_directory' => $this->root . '/logs']);
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
    ->and(array_column($slots, 'slot'))->toBe([1, 2, 3, 4, 5, SaveManager::QUICK_SAVE_SLOT, SaveManager::AUTO_SAVE_SLOT])
    ->and(array_map(fn($slot) => $slot->getLabel(), $slots))->toBe([
      'File 1', 'File 2', 'File 3', 'File 4', 'File 5', 'Quick Save', 'Autosave 1',
    ])
    ->and($slots[5]->path)->toBe($quick->path)->and($slots[6]->path)->toBe($auto->path)
    ->and($registry->getState('fixture/checkpoint'))->toBe(['checkpoint' => 99])
    ->and(new ReflectionClass(Time::class)->getStaticProperties())->toBe($time)
    ->and(new ReflectionClass(ConfigStore::class)->getStaticProperties())->toBe($settings)
    ->and($this->manager->getSaveFiles(true))->toBe($files)
    ->and(array_map(file_get_contents(...), $files))->toBe($bytes)
    ->and($this->manager->getSaveSlots())->toHaveCount(5);
});

it('leaves missing save directories absent while inspecting the load catalog', function () {
  expect($this->manager->getLoadSlots())->toHaveCount(5)
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
  expect($slots[5]->slot)->toBe(SaveManager::AUTO_SAVE_SLOT)->and($slots[5]->isLoadable)->toBeFalse()
    ->and($slots[6]->slot)->toBe(SaveManager::QUICK_SAVE_SLOT)->and($slots[6]->isLoadable)->toBeFalse()
    ->and($slots[6]->statusMessage)->toContain('newer engine')
    ->and($this->manager->getLatestSaveFile(true))->toBe($auto)
    ->and($this->manager->getLatestLoadableSaveFile(true))->toBe($good->path);
});

it('retains the three newest checkpoints with identical file timestamps across manager restarts', function () {
  $paths = [];
  $orders = [];
  foreach (range(1, 9) as $checkpoint) {
    $this->scene->checkpoint = $checkpoint;
    $manager = createSaveCatalogManager($this->game);
    $saved = $manager->autoSave($this->scene);
    $paths[] = basename($saved->path);
    $orders[] = readSaveCatalogEnvelope($saved->path)['saveOrder'];
    foreach ($manager->getSaveFiles(true) as $path) { touch($path, 1_000); }
    expect($manager->getLatestLoadableSaveFile(true))->toBe($saved->path)
      ->and(getSaveCatalogCheckpoint($manager, $saved->path))->toBe($checkpoint);
  }
  expect($paths)->toBe(['auto-01.iedata', 'auto-02.iedata', 'auto-03.iedata',
    'auto-01.iedata', 'auto-02.iedata', 'auto-03.iedata', 'auto-01.iedata', 'auto-02.iedata', 'auto-03.iedata']);
  foreach (range(1, count($orders) - 1) as $index) { expect($orders[$index])->toBeGreaterThan($orders[$index - 1]); }
  $manager = createSaveCatalogManager($this->game);
  expect(array_map(fn($path) => getSaveCatalogCheckpoint($manager, $path), $manager->getSaveFiles(true)))->toBe([9, 8, 7]);
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

it('retains readable legacy automatic files without making them canonical ring overwrite candidates', function () {
  $legacy = $this->manager->getQuickSavePath('auto');
  writeSaveCatalogEnvelope($legacy, ['slot' => new SaveSlot(1, '/old/location', false, 'Legacy fixture'),
    'config' => $this->scene->createSnapshot()]);
  $bytes = file_get_contents($legacy);
  foreach (range(1, 5) as $index) { $this->manager->autoSave($this->scene); }
  $slots = $this->manager->getLoadSlots();
  $slot = array_find($slots, fn($slot) => $slot->path === $legacy);
  expect($slot->slot)->toBe(SaveManager::AUTO_SAVE_SLOT)->and($slot->getLabel())->toBe('Autosave')
    ->and($slot->isLoadable)->toBeTrue()->and(file_get_contents($legacy))->toBe($bytes)
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

it('fills a missing autosave ring cell without overwriting another checkpoint', function () {
  $first = $this->manager->autoSave($this->scene);
  $second = $this->manager->autoSave($this->scene);
  $third = $this->manager->autoSave($this->scene);
  $bytes = file_get_contents($third->path);
  unlink($second->path);
  $replacement = $this->manager->autoSave($this->scene);
  expect($replacement->path)->toBe($second->path)->and(file_get_contents($third->path))->toBe($bytes)
    ->and(is_file($first->path))->toBeTrue();
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
  expect($title->getSelectedIndex())->toBe(5)->and($title->getSlots())->toHaveCount(9);
  foreach ($title->getSlots() as $index => $slot) {
    $title->selectSlot($index);
    expect($title->getDrawnText())->toContain($slot->getLabel())
      ->and(count(Console::getBuffer()))->toBeLessThanOrEqual($height);
    $visibleCount = max(1, intdiv($height - min(7, max(4, $height - 4)), 4));
    $first = intdiv($index, $visibleCount) * $visibleCount;
    expect($title->getDrawnText())->toContain(($first + 1) . '-' . min(9, $first + $visibleCount) . ' / 9');
  }
  Console::syncDimensions(60, 15);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 60, 'height' => 15]));
  $title->selectSlot(8);
  expect($title->getDrawnText())->toContain($title->getSlots()[8]->getLabel(), '9-9 / 9')
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
  expect($title->getSlots()[5]->path)->toBe($saved->path)->and($title->getLoadCount())->toBe(0);
  $title->selectSlot(5);
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
  expect($title->getDrawnText())->toContain('A: Load', 'B: Back', 'That file is empty.', '1-1 / 5')
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
    expect($text)->toContain($slot->getLabel(), '/ 9')
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
    expect($titleText)->toContain($slot->getLabel(), '/ 9');
  }
})->with([false, true]);
