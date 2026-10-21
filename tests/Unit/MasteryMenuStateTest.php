<?php

declare(strict_types=1);

use Assegai\Collections\Stack;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\MasteryMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

class MasteryMenuTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

class MasteryMenuReturnState extends MainMenuState
{
  public int $entries = 0;
  public function enter(): void { $this->entries++; }
}

/** Presses one key in the menu. */
function masteryMenuKey(MasteryMenuState $state, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->execute();
}

/** Writes a synthetic project: two regions, and a map whose second region holds two skills. */
function writeMasteryMenuProject(string $root): void
{
  mkdir($root . '/assets/Data/Skills', 0777, true);
  mkdir($root . '/assets/Data/Mastery', 0777, true);
  foreach ([new SpecialSkill('Start Cut', 'A first cut.', '', 2, 0, effects: [new HPDamageSkillEffect('1')]),
    new MagicSkill('Spark', 'Calls a spark down on one foe.', '', 4, 0), new MagicSkill('Storm', 'A storm.', '', 9, 0)] as $n => $skill) {
    file_put_contents(sprintf('%s/assets/Data/Skills/%04d.php', $root, $n + 1),
      '<?php return ' . var_export(['class' => \Ichiloto\Engine\Entities\Skills\Skill::class, 'data' => SkillRecord::writeSkill($skill)], true) . ';');
  }
  file_put_contents($root . '/assets/Data/mastery.php', '<?php return ' . var_export(['regions' => [
    ['id' => 'blades', 'name' => 'Blades'], ['id' => 'storms', 'name' => 'Storms']]], true) . ';');
  file_put_contents($root . '/assets/Data/Mastery/hero.php', '<?php return ' . var_export(['actor' => 'hero', 'nodes' => [
    ['id' => 'cut', 'skill' => 'Start Cut', 'region' => 'blades', 'cost' => 0, 'order' => 1],
    ['id' => 'spark', 'skill' => 'Spark', 'region' => 'storms', 'cost' => 50, 'order' => 2],
    ['id' => 'storm', 'skill' => 'Storm', 'region' => 'storms', 'cost' => 80, 'order' => 3],
  ]], true) . ';');
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, InputManager::class, ActionHints::class, ConfigStore::class, Debug::class, ModalManager::class, AudioManager::class, Time::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->cwd = getcwd();
  $this->root = sys_get_temp_dir() . '/ichiloto-mastery-state-' . bin2hex(random_bytes(4));
  writeMasteryMenuProject($this->root);
  chdir($this->root);
  MasteryCatalog::forgetProjectCatalogs();
  Debug::configure(['log_directory' => $this->root . '/logs']);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  $this->game = new MasteryMenuTestGame();
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
  new ReflectionProperty(GameScene::class, 'locationHUDWindow')->setValue($this->scene, makeBareScene(LocationHUDWindow::class));
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, makeBareScene(UIManager::class));
  $this->hero = new Character('Hero', 0, new Stats(), actorId: 'hero');
  $party = new Party();
  $party->addMember($this->hero);
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, $party);
  $this->returnState = new MasteryMenuReturnState(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'mainMenuState')->setValue($this->scene, $this->returnState);
  $modals = makeBareScene(ModalManager::class);
  new ReflectionProperty($modals, 'modals')->setValue($modals, new Stack(ModalInterface::class));
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $modals);
  InputManager::setBindings(array_map(fn($key) => ['keys' => [$key]], ['confirm' => KeyCode::ENTER, 'cancel' => KeyCode::ESCAPE,
    'up' => KeyCode::UP, 'down' => KeyCode::DOWN, 'left' => KeyCode::LEFT, 'right' => KeyCode::RIGHT,
    'character_next' => KeyCode::Q, 'character_previous' => KeyCode::E]));
  $this->state = new MasteryMenuState(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'state')->setValue($this->scene, $this->state);
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  chdir($this->cwd);
  MasteryCatalog::forgetProjectCatalogs();
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('opens on the region being studied and shows the same nodes and focus to both renderers', function () {
  \Ichiloto\Engine\Progression\Mastery\MasteryProgression::forCharacter($this->hero)?->advance($this->hero, 10);
  $this->state->enter();
  $content = $this->state->getPresentationContent();
  $list = new ReflectionMethod($this->state, 'buildListEntries')->invoke($this->state);

  expect(MasteryMenuState::projectHasMaps())->toBeTrue()
    ->and($content->regions)->toBe(['Blades', 'Storms'])
    ->and($content->regionIndex)->toBe(1)
    ->and($content->focusedNode)->toBe('spark')
    ->and(array_map(static fn($node) => [$node->id, $node->state], $content->nodes))
      ->toBe([['cut', MasteryNodeState::LEARNED], ['spark', MasteryNodeState::STUDYING], ['storm', MasteryNodeState::OPEN]])
    ->and($list[0])->toContain('Spark')->toContain('Studying 10 / 50')
    ->and($list[1])->toContain('Storm')->toContain('Open')
    ->and($content->summary['Studying'])->toBe('Spark (10 / 50 EXP)');
});

it('studies the focused node on confirm, keeps the old node\'s progress, and returns to the main menu on cancel', function () {
  \Ichiloto\Engine\Progression\Mastery\MasteryProgression::forCharacter($this->hero)?->advance($this->hero, 10);
  $this->state->enter();

  masteryMenuKey($this->state, KeyCode::DOWN);
  masteryMenuKey($this->state, KeyCode::ENTER);
  $content = $this->state->getPresentationContent();

  expect($this->hero->mastery->studying)->toBe('storm')
    ->and($this->hero->mastery->getProgress('spark'))->toBe(10)
    ->and($content->status)->toBe('Hero now studies Storm.')
    ->and(array_find($content->nodes, static fn($node) => $node->id === 'storm')->state)->toBe(MasteryNodeState::STUDYING);

  masteryMenuKey($this->state, KeyCode::ESCAPE);
  expect($this->returnState->entries)->toBe(1);
});
