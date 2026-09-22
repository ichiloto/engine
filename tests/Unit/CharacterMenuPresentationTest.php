<?php

declare(strict_types=1);

use Assegai\Collections\Stack;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentMenuCommandSelectionMode;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentSelectionMode;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentSlotSelectionMode;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ActionHintProvider;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\Rendering\Launch\RendererExecutableResolverInterface;
use Ichiloto\Engine\Rendering\Launch\RendererRegistry;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;
use Ichiloto\Engine\Scenes\Game\States\StatusViewState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Presentation\CharacterMenuPresentation;
use Ichiloto\Engine\UI\Presentation\CharacterMenuRows;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

class CharacterMenuGameProbe extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

class CharacterMenuAlerts extends ModalManager
{
  public array $messages = [];
  public function __construct() { $this->modals = new Stack(ModalInterface::class); }
  public function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void { $this->messages[] = $message; }
}

class EquipmentMenuPresentationProbe extends EquipmentMenuState
{
  public function cycle(int $offset): void { $this->selectCharacterByOffset($offset); }
  public function canvas(MenuPresentationCatalog $theme): ?PresentationCanvas { return $this->composeMenuCanvas($theme, 0); }
  public function currentMode(): mixed { return $this->mode; }
}

/** Only synthetic image geometry is asserted; mutable project art is never pinned. */
function characterMenuPng(string $path, int $width, int $height): void
{
  $chunk = static fn(string $type, string $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xDD\xEE\xFF\xFF", $width), $height))) . $chunk('IEND', ''));
}

function characterMenuTheme(bool $alternative): array
{
  $data = ['schema' => 'ichiloto.menu/1', 'icons' => ['unknown' => 'unknown.png', 'weapon.staff' => 'staff.png',
    'slot.head' => 'head.png'], 'cursor' => 'cursor.png', 'portraits' => ['actor.one' => 'portrait.png']];
  if (!$alternative) { return $data; }
  $art = ['asset' => 'state.png', 'cuts' => [3, 4, 3, 4]];
  return [...$data, 'colors' => ['text' => [42, 37, 25], 'background' => [235, 221, 199], 'panel' => [246, 235, 212]],
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34, 'panelPadding' => 16, 'sectionGap' => 8, 'portraitSize' => 88],
    'rowMetrics' => ['padding' => 24, 'iconWidth' => 30, 'iconHeight' => 16, 'cursorWidth' => 10, 'cursorHeight' => 20,
      'cursorInset' => 6, 'cursorTravel' => 3, 'cursorPeriod' => 2, 'recordSeparatorOpacity' => 0.35],
    'rowArtwork' => ['selected' => $art, 'focus' => ['asset' => 'focus.png'],
      'command.normal' => $art, 'command.selected' => $art, 'command.focus' => ['asset' => 'focus.png']],
    'frames' => ['panel' => $art, 'quiet' => $art, 'portrait' => $art]];
}

function characterMenuText(PresentationCanvas $canvas): array
{
  $result = [];
  foreach ($canvas->textLayers as $layer) {
    if (str_ends_with($layer->id, '-text') || in_array($layer->id, ['status-name', 'status-role', 'equipment-identity', 'equipment-description'], true)) {
      $result[$layer->id] = array_column($layer->runs, 'text');
    }
  }
  return $result;
}

function characterMenuWriteTheme(string $root, array $theme): void
{
  file_put_contents($root . '/' . MenuPresentationCatalog::FILE, '<?php return ' . var_export($theme, true) . ';');
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, InputManager::class, ActionHints::class, ConfigStore::class, Debug::class, ModalManager::class, \Ichiloto\Engine\Audio\AudioManager::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-character-menu-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/Data/Presentation', 0777, true);
  foreach (['unknown', 'staff', 'head', 'cursor', 'portrait', 'state', 'focus'] as $name) {
    characterMenuPng($this->root . '/' . $name . '.png', $name === 'portrait' ? 73 : 31, $name === 'portrait' ? 39 : 47);
  }
  Debug::configure(['log_directory' => $this->root . '/logs']);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  $this->game = new CharacterMenuGameProbe();
  new ReflectionProperty(\Ichiloto\Engine\Audio\AudioManager::class, 'instance')->setValue(null, null);
  $this->alerts = new CharacterMenuAlerts();
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->alerts);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty($manager, 'game')->setValue($manager, $this->game);
  $this->scene = makeBareScene(GameScene::class);
  new ReflectionProperty($this->scene, 'sceneManager')->setValue($this->scene, $manager);
  $ui = makeBareScene(\Ichiloto\Engine\UI\UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  $this->party = new Party();
  $this->actor = new Character('First Actor', 0, new Stats(totalHp: 901, totalMp: 123, attack: 34), actorId: 'actor.one');
  $this->other = new Character('Second Actor', 0, new Stats(), actorId: 'actor.two');
  $this->party->addMember($this->actor);
  $this->party->addMember($this->other);
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, $this->party);
  $this->equipped = new Weapon('Owner Staff', 'A complete current equipment description.', '/', 10,
    parameterChanges: new ParameterChanges(attack: 5), equipmentType: WeaponType::STAFF, id: 'weapon.current');
  $this->candidate = new Weapon('Other Staff', 'A different candidate.', '/', 10,
    parameterChanges: new ParameterChanges(attack: 11), equipmentType: WeaponType::STAFF, id: 'weapon.candidate');
  $this->party->inventory->addItems($this->equipped, $this->candidate);
  $slot = $this->actor->equipment[0];
  $slot->equipment = $this->equipped;
  $this->state = new EquipmentMenuPresentationProbe(new SceneStateContext($this->scene));
  $this->state->character = $this->actor;
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $this->state);
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

function characterMenuRuntime(string $root, array $caps): RendererRuntime
{
  $transport = new FakeRendererTransport();
  $transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $root,
    requiredCapabilities: $caps), $transport);
  $runtime->start('Menu fixture', 135, 36);
  return $runtime;
}

function characterMenuCandidates(EquipmentMenuPresentationProbe $state): EquipmentSelectionMode
{
  $slots = new EquipmentSlotSelectionMode($state);
  $state->setMode($slots);
  $mode = new EquipmentSelectionMode($state);
  $mode->character = $state->character;
  $mode->equipmentSlot = $state->character->equipment[0];
  $mode->previousMode = $slots;
  $state->setMode($mode);
  return $mode;
}

it('projects actual Equipment and complete Status data through two themes without changing owners', function () {
  $themes = [new MenuPresentationCatalog($this->root, characterMenuTheme(false)), new MenuPresentationCatalog($this->root, characterMenuTheme(true))];
  $commands = $this->state->equipmentMenu->getItems()->toArray();
  $frames = array_map(fn($theme) => $this->state->canvas($theme), $themes);
  expect(characterMenuText($frames[0]))->toBe(characterMenuText($frames[1]));
  foreach ($frames as $frame) {
    $layers = array_column($frame->textLayers, null, 'id');
    foreach ($commands as $index => $command) {
      $text = $layers['equipment-command-' . $index . '-text'];
      expect($text->x + $text->grid->columns * $text->grid->cellWidth / 2)
        ->toBe($text->clipRect->x + $text->clipRect->width / 2)
        ->and(array_column($text->runs, 'text'))->toBe([$command->getLabel()]);
    }
    expect($layers)->toHaveKeys(['equipment-slots-slot-0-separator', 'equipment-slots-slot-4-separator'])
      ->not->toHaveKey('equipment-command-0-separator');
    $images = array_column($frame->images, null, 'id');
    expect(array_filter($images, fn($image) => $image->asset === 'head.png'))->not->toBeEmpty()
      ->and(array_filter($images, fn($image) => str_contains($image->id, 'equipment-command-') && str_contains($image->id, '-cursor')))->toBeEmpty();
  }
  expect(array_filter($frames[1]->images, fn($image) => $image->asset === 'state.png'))->not->toBeEmpty()
    ->and($this->state->equipmentMenu->getItems()->toArray())->toBe($commands)
    ->and($this->actor->equipment[0]->equipment)->toBe($this->equipped);
  $status = array_map(fn($theme) => CharacterMenuPresentation::status($this->actor, $theme), $themes);
  expect(characterMenuText($status[0]))->toBe(characterMenuText($status[1]));
  $text = characterMenuText($status[0]);
  expect($text)->toHaveKeys(['status-resources-level-text', 'status-resources-hp-text', 'status-resources-mp-text',
    'status-resources-ap-text', 'status-exp-current-text', 'status-exp-next-text', 'status-stats-grace-text', 'status-slots-slot-4-text'])
    ->and($text['status-resources-hp-text'])->toBe(['HP', $this->actor->effectiveStats->currentHp . ' / 901']);
});

it('keeps equipped selection separate from candidate focus quantity zero and owner preview', function () {
  $mode = characterMenuCandidates($this->state);
  $source = $mode->getPresentationCandidates();
  expect($source[0]['available'])->toBe(0)->and($source[0]['current'])->toBeTrue();
  $preview = $this->state->characterDetailPanel->getPresentationPreview();
  expect($preview->attack)->toBe($this->actor->effectiveStats->attack - 5);
  $preview->attack = 999;
  expect($this->state->characterDetailPanel->getPresentationPreview()->attack)->not->toBe(999);
  $mode->selectNext();
  new ReflectionMethod($mode, 'updateCharacterDetailPanel')->invoke($mode);
  $before = $this->actor->effectiveStats->attack;
  foreach ([false, true] as $alternative) {
    $frame = $this->state->canvas(new MenuPresentationCatalog($this->root, characterMenuTheme($alternative)));
    $text = characterMenuText($frame);
    expect($text['equipment-candidates-candidate-0-text'])->toBe(['Owner Staff', '0'])
      ->and($text['equipment-candidates-candidate-1-text'])->toBe(['Other Staff', '1'])
      ->and($text['equipment-stats-attack-text'])->toBe(['Attack', (string)$before, "\u{2192}", (string)($before + 6), "\u{2191}"]);
    $ids = [...array_column($frame->textLayers, 'id'), ...array_column($frame->images, 'id')];
    expect(array_filter($ids, fn($id) => str_starts_with($id, 'equipment-candidates-candidate-0-selected')))->not->toBeEmpty()
      ->and(array_filter($ids, fn($id) => str_starts_with($id, 'equipment-candidates-candidate-0-focus')))->toBeEmpty()
      ->and(array_filter($ids, fn($id) => str_starts_with($id, 'equipment-candidates-candidate-1-focus')))->not->toBeEmpty()
      ->and(array_filter($ids, fn($id) => str_starts_with($id, 'equipment-candidates-candidate-1-cursor')))->not->toBeEmpty();
  }
  expect($this->actor->effectiveStats->attack)->toBe($before)->and($mode->getPresentationIndex())->toBe(1)
    ->and($this->actor->equipment[0]->equipment)->toBe($this->equipped);
});

it('reuses semantic arrow artwork without moving equipment values or changing previews', function () {
  $mode = characterMenuCandidates($this->state);
  $data = characterMenuTheme(false);
  $fallback = new MenuPresentationCatalog($this->root, $data);
  $data['icons'] += ['comparison.next' => 'head.png', 'comparison.up' => 'staff.png', 'comparison.down' => 'cursor.png',
    'navigation.next' => 'cursor.png', 'navigation.up' => 'head.png', 'navigation.down' => 'staff.png'];
  $art = new MenuPresentationCatalog($this->root, $data);
  foreach (['cursor.png', 'staff.png'] as $directionAsset) {
    $neutral = $this->state->canvas($fallback);
    $frame = $this->state->canvas($art);
    $images = array_column($frame->images, null, 'id');
    $id = 'equipment-stats-attack';
    expect($images[$id . '-value-1-1-1']->asset)->toBe('head.png')
      ->and($images[$id . '-value-3-1-1']->asset)->toBe($directionAsset);
    $before = array_column($neutral->textLayers, null, 'id')[$id . '-text'];
    $after = array_column($frame->textLayers, null, 'id')[$id . '-text'];
    expect($after->bounds)->toEqual($before->bounds);
    foreach ([1, 2] as $index) {
      $previous = $before->runs[$index === 1 ? 1 : 3];
      expect($after->runs[$index]->text)->toBe($previous->text)
        ->and($after->runs[$index]->column)->toBe($previous->column)
        ->and($after->runs[$index]->foreground)->toEqual($previous->foreground);
    }
    $mode->selectNext();
    new ReflectionMethod($mode, 'updateCharacterDetailPanel')->invoke($mode);
  }
  expect($this->actor->equipment[0]->equipment)->toBe($this->equipped);
});

it('wraps complete long labels and follows the actual candidate index in a finite viewport', function () {
  new ReflectionProperty($this->candidate, 'name')->setValue($this->candidate, str_repeat('Long candidate label ', 5));
  for ($index = 0; $index < 25; $index++) {
    $this->party->inventory->addItems(new Weapon('Candidate ' . $index, '', '/', 10, id: 'weapon.extra-' . $index));
  }
  $mode = characterMenuCandidates($this->state);
  $mode->selectNext();
  $theme = new MenuPresentationCatalog($this->root, characterMenuTheme(false));
  $frame = $this->state->canvas($theme);
  $text = characterMenuText($frame)['equipment-candidates-candidate-1-text'];
  expect(implode('', array_slice($text, 0, -1)))->toBe($this->candidate->name);
  for ($index = 0; $index < 25; $index++) { $mode->selectNext(); }
  $frame = $this->state->canvas($theme);
  expect(characterMenuText($frame))->toHaveKey('equipment-candidates-candidate-26-text')
    ->and(array_column($frame->textLayers, 'id'))->toContain('equipment-candidates-range')
    ->and($mode->getPresentationIndex())->toBe(26);
  new ReflectionProperty($this->actor, 'name')->setValue($this->actor, 'A deliberately longer complete actor display name');
  $status = CharacterMenuPresentation::status($this->actor, $theme);
  expect(implode('', characterMenuText($status)['status-name']))->toBe($this->actor->name);
});

it('uses optional state delegation and restores the same active menu after a modal frame', function () {
  characterMenuWriteTheme($this->root, characterMenuTheme(true));
  $this->runtime = characterMenuRuntime($this->root, MenuPresentationCatalog::CAPABILITIES);
  $this->game->useRendererRuntime($this->runtime);
  $mode = characterMenuCandidates($this->state);
  $mode->selectNext();
  $before = characterMenuText($this->scene->getPresentationCanvas());
  $modalManager = makeBareScene(ModalManager::class);
  $stack = new Stack(ModalInterface::class);
  new ReflectionProperty($modalManager, 'modals')->setValue($modalManager, $stack);
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $modalManager);
  $modal = new AlertModal($this->game, 'Equipment optimized!', 'Equipment');
  $stack->push($modal);
  $modal->show();
  InputManager::setInputSource(new FakeInputSource(KeyCode::TAB));
  expect(array_column($this->scene->getPresentationCanvas()->textLayers, 'id'))->toContain('menu-modal-message')
    ->and($this->state->currentMode())->toBe($mode);
  $modal->hide();
  expect(characterMenuText($this->scene->getPresentationCanvas()))->toBe($before);
  $stack->pop();
  expect(characterMenuText($this->scene->getPresentationCanvas()))->toBe($before)
    ->and($mode->getPresentationIndex())->toBe(1)->and($this->actor->equipment[0]->equipment)->toBe($this->equipped);
  InputManager::handleInput();
  expect(InputManager::getPressedKeyCode())->toBe(KeyCode::TAB);
  $this->state->execute();
  expect($this->state->character)->toBe($this->other)
    ->and($this->state->currentMode())->toBeInstanceOf(EquipmentMenuCommandSelectionMode::class);
});

it('negotiates a base session independently of optional theme files', function () {
  $resolver = new class implements RendererExecutableResolverInterface {
    public function resolve(string $rendererId): string { return PHP_BINARY; }
  };
  $registry = new RendererRegistry($resolver);
  expect($registry->require('terminal')->createRuntime($this->root))->toBeNull()
    ->and(MenuPresentationCatalog::load($this->root))->toBeNull()->and($this->scene->getPresentationCanvas())->toBeNull();
  $unconfigured = $registry->require('gpui')->createRuntime($this->root);
  $config = new ReflectionProperty($unconfigured, 'config')->getValue($unconfigured);
  expect($config->requiredCapabilities)->toBe([RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::TILE_BATCHES]);
  characterMenuWriteTheme($this->root, ['schema' => 'ichiloto.menu/1']);
  $configured = $registry->require('gpui')->createRuntime($this->root);
  $config = new ReflectionProperty($configured, 'config')->getValue($configured);
  expect($config->requiredCapabilities)->toBe([RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::TILE_BATCHES])
    ->and(is_file($this->root . '/Data/Presentation/battle.php'))->toBeFalse();
  $this->runtime = characterMenuRuntime($this->root, array_values(array_unique([...$config->requiredCapabilities, ...MenuPresentationCatalog::CAPABILITIES])));
  $this->game->useRendererRuntime($this->runtime);
  expect($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class);
  foreach (MenuPresentationCatalog::CAPABILITIES as $capability) { expect($this->runtime->supports($capability))->toBeTrue(); }
});

it('diagnoses unsupported capabilities and invalid configured assets without changing the owner', function (string $failure) {
  $data = characterMenuTheme(false);
  if ($failure === 'asset') { $data['portraits']['actor.two'] = 'missing.png'; }
  characterMenuWriteTheme($this->root, $data);
  $this->runtime = characterMenuRuntime($this->root, $failure === 'capability' ? [] : MenuPresentationCatalog::CAPABILITIES);
  $this->game->useRendererRuntime($this->runtime);
  $mode = $this->state->currentMode();
  if ($failure === 'asset') {
    $frame = $this->scene->getPresentationCanvas();
    expect($frame)->toBeInstanceOf(PresentationCanvas::class)
      ->and(array_filter($frame->images, fn($image) => $image->asset === 'portrait.png'))->toHaveCount(1)
      ->and($this->state->currentMode())->toBe($mode)->and($this->state->character)->toBe($this->actor);
    return;
  }
  expect($this->scene->getPresentationCanvas())->toBeNull()->and($this->scene->getPresentationCanvas())->toBeNull()
    ->and($this->state->currentMode())->toBe($mode)->and($this->state->character)->toBe($this->actor);
  $log = file_get_contents($this->root . '/logs/error.log');
  expect($log)->toContain('Menu presentation degraded to terminal')
    ->and(substr_count($log, 'Menu presentation degraded'))->toBe(1);
})->with(['asset', 'capability']);

it('retains terminal source data and full description while native overflow diagnoses instead of crashing', function () {
  characterMenuWriteTheme($this->root, characterMenuTheme(false));
  $this->runtime = characterMenuRuntime($this->root, MenuPresentationCatalog::CAPABILITIES);
  $this->game->useRendererRuntime($this->runtime);
  $description = "First\nSecond\n" . str_repeat('Full long description ', 300);
  $this->state->equipmentInfoPanel->setText($description);
  expect($this->state->equipmentInfoPanel->getPresentationText())->toBe($description)
    ->and($this->state->equipmentInfoPanel->getContent())->toBe(['First', 'Second'])
    ->and($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class)
    ->and($this->state->menuInfoText->lastPage->source)->toBe($description)
    ->and($this->state->menuInfoText->lastPage->lines)->toBe(['First', 'Second']);
  $this->state->equipmentInfoPanel->setText('Fits again');
  expect($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class);
  $status = new StatusViewState(new SceneStateContext($this->scene));
  $status->character = $this->actor;
  $status->enter();
  $terminal = TerminalText::stripAnsi(implode("\n", Console::getBuffer()));
  expect($terminal)->toContain('First Actor', 'Current EXP:', 'To Next Level:', 'Owner Staff', 'AP:');
});

it('omits an unconfigured portrait frame rather than substituting the panel artwork', function () {
  $data = characterMenuTheme(true);
  unset($data['frames']['portrait']);
  $frame = CharacterMenuPresentation::status($this->actor, new MenuPresentationCatalog($this->root, $data));
  expect(array_filter($frame->images, fn($image) => $image->asset === 'portrait.png'))->toHaveCount(1)
    ->and(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'portrait-frame-')))->toBeEmpty()
    ->and(array_filter($frame->images, fn($image) => $image->asset === 'state.png'))->not->toBeEmpty();
});

it('reads replacement portrait and frame dimensions from the current PNG without changing actor identity', function () {
  $theme = new MenuPresentationCatalog($this->root, characterMenuTheme(true));
  $first = CharacterMenuPresentation::status($this->actor, $theme);
  characterMenuPng($this->root . '/portrait.png', 21, 89);
  characterMenuPng($this->root . '/state.png', 5, 7);
  $second = CharacterMenuPresentation::status($this->actor, $theme);
  expect(characterMenuText($second))->toBe(characterMenuText($first));
  $portraits = array_values(array_filter($second->images, fn($image) => $image->asset === 'portrait.png'));
  expect($portraits)->toHaveCount(1)->and($portraits[0]->sourceRect->width)->toBe(21)
    ->and($portraits[0]->sourceRect->height)->toBe(89)->and($this->actor->actorId)->toBe('actor.one');
});

it('retains Equip toggle Optimize Clear and actor cycling in the existing controllers under either theme', function (bool $alternative) {
  $theme = new MenuPresentationCatalog($this->root, characterMenuTheme($alternative));
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'back' => ['keys' => [KeyCode::ESCAPE]]]);
  $press = function (KeyCode $key): void {
    InputManager::setInputSource(new FakeInputSource($key));
    InputManager::handleInput();
    $this->state->execute();
  };
  $this->state->canvas($theme);
  $press(KeyCode::ENTER);
  expect($this->state->currentMode())->toBeInstanceOf(EquipmentSlotSelectionMode::class);
  $press(KeyCode::ENTER);
  expect($this->state->currentMode())->toBeInstanceOf(EquipmentSelectionMode::class);
  $this->state->canvas($theme);
  $press(KeyCode::ENTER);
  expect($this->actor->equipment[0]->equipment)->toBeNull();
  $press(KeyCode::ENTER);
  $mode = $this->state->currentMode();
  $mode->selectNext();
  $this->state->canvas($theme);
  $press(KeyCode::ENTER);
  expect($this->actor->equipment[0]->equipment->id)->toBe($this->candidate->id);
  $commands = $this->state->equipmentMenu->getItems()->toArray();
  $commands[1]->execute();
  expect($this->actor->equipment[0]->equipment->id)->toBe($this->candidate->id);
  $commands[2]->execute();
  expect(array_all($this->actor->equipment, fn($slot) => $slot->equipment === null))->toBeTrue()
    ->and($this->alerts->messages)->toBe(['Equipped Other Staff on First Actor', 'Equipment optimized!', 'Equipment cleared!']);
  $this->state->cycle(1);
  expect($this->state->character)->toBe($this->other)->and($this->state->equipmentMenu->activeIndex)->toBe(0);
  $this->state->cycle(-1);
  expect($this->state->character)->toBe($this->actor);
  $status = new StatusViewState(new SceneStateContext($this->scene));
  $status->character = $this->actor;
  $status->enter();
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $status);
  foreach ([KeyCode::TAB, KeyCode::SHIFT_TAB] as $index => $key) {
    InputManager::setInputSource(new FakeInputSource($key));
    InputManager::handleInput();
    $status->execute();
    expect($status->character)->toBe($index === 0 ? $this->other : $this->actor);
    expect(characterMenuText(CharacterMenuPresentation::status($status->character, $theme))['status-name'])
      ->toBe([$status->character->name]);
  }
})->with([false, true]);

it('leaves native terminal frames available when the catalog is absent or the state does not implement Canvas', function () {
  $this->runtime = characterMenuRuntime($this->root, []);
  $this->game->useRendererRuntime($this->runtime);
  expect($this->scene->getPresentationCanvas())->toBeNull()->and(is_dir($this->root . '/logs'))->toBeFalse();
  $state = new class(new SceneStateContext($this->scene)) extends \Ichiloto\Engine\Scenes\Game\States\GameSceneState {
    public function execute(?\Ichiloto\Engine\Scenes\SceneStateContext $context = null): void {}
  };
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $state);
  expect($this->scene->getPresentationCanvas())->toBeNull();
});

it('rejects malformed configured themes while terminal mode never evaluates them', function (array $data) {
  characterMenuWriteTheme($this->root, $data);
  expect($this->scene->getPresentationCanvas())->toBeNull()->and(is_dir($this->root . '/logs'))->toBeFalse()
    ->and(fn() => MenuPresentationCatalog::load($this->root))->toThrow(InvalidArgumentException::class);
})->with([
  [['schema' => 'unknown']],
  [['schema' => 'ichiloto.menu/1', 'sourceWidth' => 24]],
  [['schema' => 'ichiloto.menu/1', 'colors' => ['text' => [1, 2, 256]]]],
  [['schema' => 'ichiloto.menu/1', 'rowArtwork' => ['callback' => 'anything']]],
  [['schema' => 'ichiloto.menu/1', 'cursor' => 12]],
  [['schema' => 'ichiloto.menu/1', 'frames' => ['panel' => ['asset' => '../outside.png']]]],
]);

it('starts a themed project with only base capabilities and retains terminal menus', function () {
  characterMenuWriteTheme($this->root, ['schema' => 'ichiloto.menu/1']);
  $transport = new FakeRendererTransport();
  // Even malformed optional title/battle catalogs must not execute during registry discovery.
  file_put_contents($this->root . '/Data/Presentation/title.php', '<?php throw new RuntimeException("optional title");');
  file_put_contents($this->root . '/Data/Presentation/battle.php', '<?php throw new RuntimeException("optional battle");');
  $registry = new RendererRegistry(new class implements RendererExecutableResolverInterface {
    public function resolve(string $rendererId): string { return 'not-launched'; }
  });
  $discovered = $registry->require('gpui')->createRuntime($this->root);
  $config = new ReflectionProperty($discovered, 'config')->getValue($discovered);
  $transport->batches = [[RendererEvent::fromJson('{"type":"ready","protocol":2,"capabilities":["sprite_source_rect","tile_batches"]}')]];
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $this->root,
    requiredCapabilities: $config->requiredCapabilities), $transport);
  $this->runtime->start('Fixture', 135, 36);
  $this->game->useRendererRuntime($this->runtime);
  expect($this->scene->getPresentationCanvas())->toBeNull()
    ->and($this->state->character)->toBe($this->actor);
});

it('refreshes both menu footers from live semantic rebindings without consuming input or changing owners', function (bool $alternative) {
  $theme = new MenuPresentationCatalog($this->root, characterMenuTheme($alternative));
  $mode = $this->state->currentMode();
  $source = new FakeInputSource(KeyCode::TAB);
  InputManager::setInputSource($source);
  foreach ([KeyCode::F1, KeyCode::PAGE_DOWN] as $next) {
    $bindings = ['character_next' => ['keys' => [$next]],
      'character_previous' => ['keys' => [KeyCode::PAGE_UP, KeyCode::HOME]], 'back' => ['keys' => [KeyCode::END]]];
    InputManager::setBindings($bindings);
    $effective = InputManager::getBindings();
    foreach (['equipment' => $this->state->canvas($theme),
      'status' => CharacterMenuPresentation::status($this->actor, $theme)] as $id => $canvas) {
      $hint = array_column($canvas->textLayers, null, 'id')[$id . '-hints'];
      expect(implode('', array_column($hint->runs, 'text')))->toBe(' ' . ControlHint::keyboard($next)->label . ' : Next   Page Up : Prev   End : Back');
    }
    expect(InputManager::getBindings())->toBe($effective)->and($source->keys)->toBe([KeyCode::TAB])
      ->and($this->state->currentMode())->toBe($mode)->and($this->state->character)->toBe($this->actor);
  }
})->with([false, true]);

it('consults the shared display provider for each Equipment and Status redraw without changing actions', function () {
  $provider = new class implements ActionHintProvider {
    public function __construct(public string $family = 'profile-one') {}
    public function controlForAction(string $action): ?ControlHint { return new ControlHint($this->family, $action, $this->family); }
  };
  $theme = new MenuPresentationCatalog($this->root, characterMenuTheme(false));
  $mode = $this->state->currentMode();
  $bindings = InputManager::getBindings();
  $source = new FakeInputSource(KeyCode::TAB);
  InputManager::setInputSource($source);
  ActionHints::useProvider($provider);
  foreach (['profile-one', 'profile-two'] as $family) {
    $provider->family = $family;
    foreach (['equipment' => $this->state->canvas($theme),
      'status' => CharacterMenuPresentation::status($this->actor, $theme)] as $id => $frame) {
      $hint = array_column($frame->textLayers, null, 'id')[$id . '-hints'];
      expect(implode('', array_column($hint->runs, 'text')))->toBe(" $family : Next   $family : Prev   $family : Back");
    }
    expect($this->state->currentMode())->toBe($mode)->and($this->state->character)->toBe($this->actor)
      ->and(InputManager::getBindings())->toBe($bindings)->and($source->keys)->toBe([KeyCode::TAB]);
  }
  InputManager::handleInput();
  $this->state->execute();
  expect($this->state->character)->toBe($this->other);
});

it('shows the existing cycling aliases for missing or empty bindings without inventing a Back key', function (array $bindings) {
  InputManager::setBindings($bindings);
  $theme = new MenuPresentationCatalog($this->root, characterMenuTheme(false));
  foreach (['equipment' => $this->state->canvas($theme),
    'status' => CharacterMenuPresentation::status($this->actor, $theme)] as $id => $canvas) {
    $hint = array_column($canvas->textLayers, null, 'id')[$id . '-hints'];
    expect(implode('', array_column($hint->runs, 'text')))->toBe(' Tab : Next   Shift+Tab : Prev  Unbound: Back');
  }
  foreach ([KeyCode::TAB, KeyCode::SHIFT_TAB] as $index => $key) {
    InputManager::setInputSource(new FakeInputSource($key));
    InputManager::handleInput();
    $this->state->execute();
    expect($this->state->character)->toBe($index === 0 ? $this->other : $this->actor);
  }
})->with([
  [[]],
  [['character_next' => ['keys' => []], 'character_previous' => ['keys' => []], 'back' => ['keys' => []]]],
]);

it('reserves wrapped footer height separately from descriptions and body records in both themes', function (bool $alternative) {
  $labels = str_repeat('Control ', 5) . 'A';
  ActionHints::useProvider(new class($labels) implements ActionHintProvider {
    public function __construct(private string $label) {}
    public function controlForAction(string $action): ?ControlHint { return new ControlHint('profile', $action, $this->label); }
  });
  $theme = new MenuPresentationCatalog($this->root, characterMenuTheme($alternative));
  foreach (['', str_repeat('Complete description ', 10)] as $description) {
    $this->state->equipmentInfoPanel->setText($description);
    foreach (['equipment' => $this->state->canvas($theme),
      'status' => CharacterMenuPresentation::status($this->actor, $theme)] as $id => $canvas) {
      $layers = array_column($canvas->textLayers, null, 'id');
      $hint = $layers[$id . '-hints'];
      $footer = array_values(array_filter([...$canvas->textLayers, ...$canvas->images],
        fn($layer) => $layer->id === $id . '-info' || str_starts_with($layer->id, $id . '-info-')));
      $footerTop = min(array_map(fn($layer) => $layer->clipRect->y, $footer));
      expect($hint->grid->rows)->toBeGreaterThan(1)
        ->and(implode('', array_column($hint->runs, 'text')))->toContain(' ' . $labels . ' : Next', ' ' . $labels . ' : Prev', ' ' . $labels . ' : Back')
        ->and($hint->y)->toBeGreaterThanOrEqual($footerTop + $theme->metrics->panelPadding)
        ->and($hint->y + $hint->grid->rows * $hint->grid->cellHeight)->toBeLessThanOrEqual(710 - $theme->metrics->panelPadding);
      foreach ($layers as $layer) {
        if (str_starts_with($layer->id, $id . '-stats-') || str_starts_with($layer->id, $id . '-slots-')) {
          expect($layer->clipRect->y + $layer->clipRect->height)->toBeLessThanOrEqual($footerTop);
        }
      }
      if ($id === 'equipment' && $description !== '') {
        $prose = $layers['equipment-description'];
        expect(implode('', array_column($prose->runs, 'text')))->toBe($description)
          ->and($prose->y)->toBeGreaterThanOrEqual($footerTop + $theme->metrics->panelPadding)
          ->and($prose->y + $prose->grid->rows * $prose->grid->cellHeight + $theme->metrics->sectionGap)->toBeLessThanOrEqual($hint->y);
      } elseif ($id === 'equipment') {
        expect($layers)->not->toHaveKey('equipment-description');
      }
    }
  }
})->with([false, true]);
