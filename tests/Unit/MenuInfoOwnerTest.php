<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentSelectionMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\UseItemMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectIemMenuCommandMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\ViewKeyItemsMode;
use Ichiloto\Engine\Core\Menu\MainMenu\ConfigMenu;
use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\ConfigDetailPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\ConfigSelectionWindow;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\AbilityMenuState;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\Scenes\Game\States\MagicMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Presentation\ItemMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class MenuInfoGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

final class MenuInfoMemoryConfig extends ProjectConfig
{
  public int $writes = 0;
  protected function load(): array { return $this->options; }
  public function persist(): void { $this->writes++; }
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, AudioManager::class, QuestManager::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  new ReflectionProperty(QuestManager::class, 'current')->setValue(null, null);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  $this->config = new MenuInfoMemoryConfig([]);
  ConfigStore::put(ProjectConfig::class, $this->config);
  $this->game = new MenuInfoGame();
  $audio = new class extends AudioManager {
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void {}
  };
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $audio);
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty($manager, 'game')->setValue($manager, $this->game);
  $this->scene = makeBareScene(GameScene::class);
  new ReflectionProperty($this->scene, 'sceneManager')->setValue($this->scene, $manager);
  new ReflectionProperty($this->scene, 'gameState')->setValue($this->scene, new GameState());
  $ui = makeBareScene(UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  $this->party = new Party();
  $this->actor = new Character('Actor', 0, new Stats(), actorId: 'actor.one');
  $this->party->addMember($this->actor);
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, $this->party);
  InputManager::setBindings(array_map(fn($key) => ['keys' => [$key]], [
    'info' => KeyCode::F2, 'down' => KeyCode::DOWN, 'up' => KeyCode::UP, 'confirm' => KeyCode::ENTER,
    'cancel' => KeyCode::ESCAPE, 'back' => KeyCode::ESCAPE, 'character_next' => KeyCode::Q,
  ]));
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

function pressMenuInfoKey(object $owner, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $owner instanceof ConfigMenu ? $owner->update() : $owner->execute();
}

function getOwnerInfoPanel(object $owner): Window
{
  if ($owner instanceof ConfigMenu) { return $owner->detail; }
  $property = $owner instanceof EquipmentMenuState ? 'equipmentInfoPanel' : 'infoPanel';
  return new ReflectionProperty($owner, $property)->getValue($owner);
}

function createInfoOwner(object $fixture, string $kind, string $description): object
{
  if ($kind === 'config') {
    $manager = new MainMenuSettingsManager();
    $owner = new ConfigMenu($manager, new ConfigSelectionWindow(new Rect(0, 0, 80, 20), $manager),
      new ConfigDetailPanel(new Rect(0, 20, 80, 4)), static fn() => null);
    $owner->enter();
    $owner->selection->setSettings([new GameSetting('first', 'First', $description), new GameSetting('second', 'Second', $description),
      new GameSetting('third', 'Third', "New one\nNew two\nNew three\nNew four")]);
    $owner->selection->focus();
    $owner->render();
    return $owner;
  }
  if ($kind === 'items') {
    $fixture->party->inventory->addItems(new Item('First', $description, '', 1), new Item('Second', $description, '', 1),
      new Item('Third', "New one\nNew two\nNew three\nNew four", '', 1));
    $owner = new ItemMenuState(new SceneStateContext($fixture->scene));
    $owner->enter();
    $owner->setMode(new UseItemMode($owner));
    return $owner;
  }
  if ($kind === 'equipment') {
    $fixture->party->inventory->addItems(new Weapon('First', $description, '', 1), new Weapon('Second', $description, '', 1),
      new Weapon('Third', "New one\nNew two\nNew three\nNew four", '', 1));
    $owner = new EquipmentMenuState(new SceneStateContext($fixture->scene));
    $owner->enter();
    $mode = new EquipmentSelectionMode($owner);
    $mode->character = $fixture->actor;
    $mode->equipmentSlot = $fixture->actor->equipment[0];
    $owner->setMode($mode);
    return $owner;
  }
  $class = $kind === 'magic' ? MagicSkill::class : SpecialSkill::class;
  $skills = [new $class('First', $description, '', 1, 0), new $class('Second', $description, '', 1, 0),
    new $class('Third', "New one\nNew two\nNew three\nNew four", '', 1, 0)];
  foreach ($skills as $skill) { $fixture->actor->learnSkill($skill); }
  $class = $kind === 'magic' ? MagicMenuState::class : AbilityMenuState::class;
  $owner = new $class(new SceneStateContext($fixture->scene));
  $owner->enter();
  return $owner;
}

it('pages every owner in the existing Info window and keeps ranges solely on its border', function (string $kind) {
  $owner = createInfoOwner($this, $kind, "One\nTwo\nThree\nFour\nFive");
  $panel = getOwnerInfoPanel($owner);
  expect($panel->getContent())->toBe(['One', 'Two']);
  pressMenuInfoKey($owner, KeyCode::F2);
  expect($panel->getContent())->toBe(['Three', 'Four'])->and($panel->getHelp())->toBe('Lines 3-4 / 5');
  $border = new ReflectionMethod($panel, 'getBottomBorder')->invoke($panel);
  expect(TerminalText::stripAnsi($border))->toContain('Lines 3-4 / 5');
  pressMenuInfoKey($owner, KeyCode::F2);
  expect($panel->getContent())->toBe(['Five', ''])->and($panel->getHelp())->toBe('Lines 5-5 / 5');
  pressMenuInfoKey($owner, KeyCode::F2);
  expect($panel->getContent())->toBe(['One', 'Two'])->and($this->config->writes)->toBe(0);
  if ($owner instanceof AbilityMenuState || $owner instanceof MagicMenuState) {
    expect($owner->getPresentationContent()->infoModel)->toBe($owner->menuInfoText);
  }
})->with(['items', 'equipment', 'abilities', 'magic', 'config']);

it('resets reading on identity changes even with matching descriptions and uses fresh source before advancing', function (string $kind) {
  $owner = createInfoOwner($this, $kind, "One\nTwo\nThree\nFour");
  $panel = getOwnerInfoPanel($owner);
  pressMenuInfoKey($owner, KeyCode::F2);
  expect($panel->getContent())->toBe(['Three', 'Four']);
  pressMenuInfoKey($owner, KeyCode::DOWN);
  expect($panel->getContent())->toBe(['One', 'Two']);
  pressMenuInfoKey($owner, KeyCode::DOWN);
  expect($panel->getContent())->toBe(['New one', 'New two']);
  pressMenuInfoKey($owner, KeyCode::F2);
  expect($panel->getContent())->toBe(['New three', 'New four']);
})->with(['items', 'equipment', 'abilities', 'magic', 'config']);

it('honors live Info rebindings without executing the owners confirm actions', function (string $kind) {
  $owner = createInfoOwner($this, $kind, "One\nTwo\nThree\nFour");
  $panel = getOwnerInfoPanel($owner);
  InputManager::setBindings(['info' => ['keys' => [KeyCode::X]], 'confirm' => ['keys' => [KeyCode::X]]]);
  pressMenuInfoKey($owner, KeyCode::F2);
  expect($panel->getContent())->toBe(['One', 'Two']);
  pressMenuInfoKey($owner, KeyCode::X);
  expect($panel->getContent())->toBe(['Three', 'Four'])->and($this->config->writes)->toBe(0);
})->with(['items', 'equipment', 'abilities', 'magic', 'config']);

it('keeps complete status and description reachable without restoring old source on Info input', function (string $kind) {
  $owner = createInfoOwner($this, $kind, "One\nTwo\nThree\nFour");
  $model = $owner->menuInfoText;
  $model->getPage('Old renderer source', null, 20);
  new ReflectionProperty($owner, 'statusMessage')->setValue($owner, "Status one\nStatus two\nStatus three");
  pressMenuInfoKey($owner, KeyCode::F2);
  expect($model->lastPage->source)->toBe("One\nTwo\nThree\nFour\nStatus one\nStatus two\nStatus three");
  pressMenuInfoKey($owner, KeyCode::F2);
  expect(getOwnerInfoPanel($owner)->getContent())->toBe(['Status one', 'Status two']);
  pressMenuInfoKey($owner, KeyCode::F2);
  expect(getOwnerInfoPanel($owner)->getContent())->toBe(['Status three', '']);
})->with(['abilities', 'magic', 'config']);

it('refreshes a changed Item or Equipment description before consuming the Info action', function (string $kind) {
  $owner = createInfoOwner($this, $kind, "One\nTwo\nThree\nFour");
  pressMenuInfoKey($owner, KeyCode::F2);
  $panel = getOwnerInfoPanel($owner);
  $panel->setText("Changed one\nChanged two\nChanged three\nChanged four");
  pressMenuInfoKey($owner, KeyCode::F2);
  expect($panel->getContent())->toBe(['Changed three', 'Changed four'])
    ->and($owner->menuInfoText->lastPage->source)->toBe("Changed one\nChanged two\nChanged three\nChanged four");
})->with(['items', 'equipment']);

it('resets a skill Info page when cycling to an actor with the same description', function (string $kind) {
  $owner = createInfoOwner($this, $kind, "One\nTwo\nThree\nFour");
  $other = new Character('Other Actor', 0, new Stats(), actorId: 'actor.two');
  $skills = $kind === 'magic' ? $this->actor->spellbook->getLearnedSpells() : $this->actor->abilityBook->getLearnedAbilities();
  foreach ($skills as $skill) { $other->learnSkill($skill); }
  $this->party->addMember($other);
  pressMenuInfoKey($owner, KeyCode::F2);
  pressMenuInfoKey($owner, KeyCode::Q);
  expect($owner->character)->toBe($other)->and(getOwnerInfoPanel($owner)->getContent())->toBe(['One', 'Two']);
})->with(['abilities', 'magic']);

it('pages read-only Key Items through the actual state canvas without an authored Info binding', function (bool $alternate) {
  InputManager::setBindings([
    'confirm' => ['keys' => [KeyCode::ENTER]], 'back' => ['keys' => [KeyCode::ESCAPE]],
    'cancel' => ['keys' => [KeyCode::C, KeyCode::c, KeyCode::ESCAPE]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]],
  ]);
  $lines = ['First read-only line.', 'Second read-only line.', 'Third read-only line.', 'Fourth read-only line.'];
  $key = new Item('Test key', implode("\n", $lines), '', 0, isKeyItem: true, id: 'test.key');
  $this->party->inventory->addItems($key);
  $owner = new ItemMenuState(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $owner);
  $owner->enter();
  for ($i = 0; $i < 3; $i++) { pressMenuInfoKey($owner, KeyCode::RIGHT); }
  pressMenuInfoKey($owner, KeyCode::ENTER);
  expect($owner->mode)->toBeInstanceOf(ViewKeyItemsMode::class);

  $data = ['schema' => 'ichiloto.menu/1', 'showInputHints' => false];
  if ($alternate) {
    $data['metrics'] = ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 32, 'panelPadding' => 16];
    $data['colors'] = ['panel' => [244, 237, 217], 'text' => [30, 35, 50]];
  }
  $theme = new MenuPresentationCatalog(sys_get_temp_dir(), $data);
  // An already-loaded theme exercises state delegation without files or a native process.
  $this->game->useRendererRuntime(new RendererRuntime(new RendererRuntimeConfig(
    new RendererProcessConfig(['not-launched']), sys_get_temp_dir()), new FakeRendererTransport()));
  new ReflectionProperty($owner, 'menuThemeLoaded')->setValue($owner, true);
  new ReflectionProperty($owner, 'menuTheme')->setValue($owner, $theme);
  $readCanvas = function () use ($owner, $theme): string {
    expect(ItemMenuPresentation::compose($owner, $theme))->toBeInstanceOf(PresentationCanvas::class);
    $canvas = $this->scene->getPresentationCanvas();
    expect($canvas)->toBeInstanceOf(PresentationCanvas::class);
    return implode("\n", array_map(fn($layer) => implode('', array_column($layer->runs, 'text')), $canvas->textLayers));
  };
  expect($readCanvas())->toContain($lines[0], $lines[1], 'Lines 1-2 / 4')->not->toContain($lines[2]);
  pressMenuInfoKey($owner, KeyCode::i);
  expect($owner->infoPanel->getContent())->toBe(array_slice($lines, 2))
    ->and($owner->infoPanel->getHelp())->toBe('Lines 3-4 / 4');
  expect($readCanvas())->toContain($lines[2], $lines[3], 'Lines 3-4 / 4')->not->toContain($lines[0]);
  expect($readCanvas())->toContain($lines[2])->and($owner->menuInfoText->lastPage->first)->toBe(2);
  pressMenuInfoKey($owner, KeyCode::I);
  expect($readCanvas())->toContain($lines[0], $lines[1])->not->toContain($lines[2]);
  pressMenuInfoKey($owner, KeyCode::ENTER);
  expect($owner->mode)->toBeInstanceOf(ViewKeyItemsMode::class)->and($key->quantity)->toBe(1);
  pressMenuInfoKey($owner, KeyCode::ESCAPE);
  expect($owner->mode)->toBeInstanceOf(SelectIemMenuCommandMode::class)
    ->and($owner->itemMenu->activeIndex)->toBe(3)->and($this->config->writes)->toBe(0);
})->with([false, true]);
