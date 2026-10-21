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

/** Answers the menu's confirmation dialog without opening one, keeping each question and its buttons. */
function masteryMenuAnswers(bool $answer): ModalManager
{
  $modals = new class extends ModalManager {
    /** @var list<string> */
    public array $asked = [];
    /** @var list<array{string, string}> */
    public array $buttons = [];
    public bool $answer = true;
    public function __construct() {}
    public function confirm(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH,
      string $confirmLabel = 'OK', string $cancelLabel = 'Cancel'): bool
    {
      $this->asked[] = $message;
      $this->buttons[] = [$confirmLabel, $cancelLabel];
      return $this->answer;
    }
  };
  $modals->answer = $answer;
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $modals);

  return $modals;
}

/** Every word the menu shows the player: tabs, list, character block, details and help. */
function masteryMenuWords(MasteryMenuState $state): string
{
  $content = $state->getPresentationContent();
  $list = new ReflectionMethod($state, 'buildListEntries')->invoke($state);
  $summary = new ReflectionMethod($state, 'buildSummaryLines')->invoke($state);
  $detail = new ReflectionMethod($state, 'buildDetailLines')->invoke($state);

  return implode("\n", [...$content->regions, ...$list, ...$summary, ...$detail, ...array_keys($content->summary),
    ...$content->summary, ...array_map(static fn($node) => $node->label . ' ' . $node->value, $content->nodes),
    $content->description, $content->emptyText, $content->status ?? '']);
}

/** The focused skill's Terminal detail panel, as one string. */
function masteryMenuDetail(MasteryMenuState $state): string
{
  return implode("\n", array_map('rtrim', new ReflectionMethod($state, 'buildDetailLines')->invoke($state)));
}

/** Writes a synthetic project: two regions; the second holds an open skill, a dear one, a locked one and a secret. */
function writeMasteryMenuProject(string $root): void
{
  mkdir($root . '/assets/Data/Skills', 0777, true);
  mkdir($root . '/assets/Data/Mastery', 0777, true);
  foreach ([new SpecialSkill('Start Cut', 'A first cut.', '', 2, 0, effects: [new HPDamageSkillEffect('1')]),
    new MagicSkill('Spark', 'Calls a spark down on one foe.', '', 4, 0), new MagicSkill('Storm', 'A storm.', '', 9, 0),
    new MagicSkill('Tempest', 'A tempest.', '', 20, 0), new MagicSkill('Veil', 'A veil.', '', 30, 0)] as $n => $skill) {
    file_put_contents(sprintf('%s/assets/Data/Skills/%04d.php', $root, $n + 1),
      '<?php return ' . var_export(['class' => \Ichiloto\Engine\Entities\Skills\Skill::class, 'data' => SkillRecord::writeSkill($skill)], true) . ';');
  }
  file_put_contents($root . '/assets/Data/mastery.php', '<?php return ' . var_export(['regions' => [
    ['id' => 'blades', 'name' => 'Blades'], ['id' => 'storms', 'name' => 'Storms']]], true) . ';');
  file_put_contents($root . '/assets/Data/Mastery/hero.php', '<?php return ' . var_export(['actor' => 'hero', 'nodes' => [
    ['id' => 'cut', 'skill' => 'Start Cut', 'region' => 'blades', 'cost' => 0],
    ['id' => 'spark', 'skill' => 'Spark', 'region' => 'storms', 'cost' => 50],
    ['id' => 'storm', 'skill' => 'Storm', 'region' => 'storms', 'cost' => 80],
    ['id' => 'tempest', 'skill' => 'Tempest', 'region' => 'storms', 'cost' => 120, 'opensAtLevel' => 5, 'requires' => ['storm']],
    ['id' => 'veil', 'skill' => 'Veil', 'region' => 'storms', 'cost' => 200, 'requires' => ['tempest'], 'hidden' => true],
  ]], true) . ';');
}

/** The hero as a save brings them: the free skill learned, holding some points. */
function masteryMenuHero(Character $hero, int $points): void
{
  \Ichiloto\Engine\Progression\Mastery\MasteryProgression::forCharacter($hero)?->reconcile($hero);
  $hero->mastery->points = $points;
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

it('opens on the first skill the character can learn and shows the same nodes and focus to both renderers', function () {
  masteryMenuHero($this->hero, 60);
  $this->state->enter();
  $content = $this->state->getPresentationContent();
  $list = array_map('rtrim', new ReflectionMethod($this->state, 'buildListEntries')->invoke($this->state));
  $summary = new ReflectionMethod($this->state, 'buildSummaryLines')->invoke($this->state);

  expect(MasteryMenuState::projectHasMaps())->toBeTrue()
    ->and($content->regions)->toBe(['Blades 1/1', 'Storms 0/4'])
    ->and($content->regionIndex)->toBe(1)
    ->and($content->focusedNode)->toBe('spark')
    ->and($content->getPointsText(0))->toBe('SP 60')
    ->and($summary[0])->toContain('Hero')->toContain('Lv 1')->toContain('SP 60')
    ->and($content->summary)->toBe(['Learned' => '1/5'])
    ->and($list[0])->toStartWith('Spark')->toEndWith(' 50')
    ->and($list[1])->toStartWith('Storm')->toEndWith(' 80')
    ->and($list[2])->toStartWith('Tempest')->toEndWith(' Unlocks at Lv 5')
    ->and($list[3])->toStartWith('???')->toEndWith(' ???')
    ->and(array_column(array_map(static fn($node) => [$node->id, $node->label . ' | ' . $node->value], $content->nodes), 1, 0))
      ->toBe(['cut' => "Start Cut | \u{2713} Learned", 'spark' => 'Spark | 50', 'storm' => 'Storm | 80', 'tempest' => 'Tempest | Lv 5', 'veil' => '??? | ???'])
    ->and(array_column(array_map(static fn($node) => [$node->id, $node->affordable], $content->nodes), 1, 0))
      ->toMatchArray(['spark' => true, 'storm' => false]);
});

it('learns a skill only once the player says Yes to its price, and the total changes at once', function () {
  masteryMenuHero($this->hero, 60);
  $modals = masteryMenuAnswers(false);
  $this->state->enter();

  masteryMenuKey($this->state, KeyCode::ENTER);
  expect($modals->asked)->toBe(['Learn Spark for 50 SP?'])
    ->and($modals->buttons)->toBe([['Yes', 'No']])
    ->and($this->hero->mastery->points)->toBe(60)
    ->and($this->hero->mastery->hasLearned('spark'))->toBeFalse();

  $modals->answer = true;
  masteryMenuKey($this->state, KeyCode::ENTER);
  $content = $this->state->getPresentationContent();
  $summary = new ReflectionMethod($this->state, 'buildSummaryLines')->invoke($this->state);

  expect($this->hero->mastery->points)->toBe(10)
    ->and($this->hero->mastery->hasLearned('spark'))->toBeTrue()
    ->and($this->hero->spellbook->getLearnedSpells()[0]->name)->toBe('Spark')
    ->and($content->status)->toBe('Hero learned Spark!')
    ->and($summary[0])->toContain('SP 10')
    ->and($content->points)->toBe(10)
    // A graphical renderer counts the total down from 60; once the count ends it shows the new total.
    ->and($content->pointsCount?->from)->toBe(60)
    ->and($content->pointsCount?->getValueAt($content->pointsCount->startedAt))->toBe(60)
    ->and($content->getPointsText(($content->pointsCount?->startedAt ?? 0) + 1))->toBe('SP 10');

  masteryMenuKey($this->state, KeyCode::ESCAPE);
  expect($this->returnState->entries)->toBe(1);
});

it('shows the new total at once under reduced motion', function () {
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['accessibility' => ['reducedMotion' => true]]));
  masteryMenuHero($this->hero, 60);
  masteryMenuAnswers(true);
  $this->state->enter();

  masteryMenuKey($this->state, KeyCode::ENTER);
  $content = $this->state->getPresentationContent();

  expect($this->hero->mastery->points)->toBe(10)
    ->and($content->pointsCount)->toBeNull()
    ->and($content->getPointsText(0))->toBe('SP 10');
});

it('says Not enough SP for a skill that costs more than the total, and refuses it without asking', function () {
  masteryMenuHero($this->hero, 60);
  $modals = masteryMenuAnswers(true);
  $this->state->enter();
  masteryMenuKey($this->state, KeyCode::DOWN);

  expect($this->state->getPresentationContent()->focusedNode)->toBe('storm')
    ->and(masteryMenuDetail($this->state))->toContain('Storm')->toContain('Not enough SP')->toMatch('/SP\s+80/');

  masteryMenuKey($this->state, KeyCode::ENTER);
  expect($modals->asked)->toBe([])
    ->and($this->hero->mastery->points)->toBe(60)
    ->and($this->state->getPresentationContent()->status)->toBe('Not enough SP.');
});

it('says what unlocks a locked skill, keeps a hidden one a secret, and refuses both without asking', function () {
  masteryMenuHero($this->hero, 500);
  $modals = masteryMenuAnswers(true);
  $this->state->enter();
  masteryMenuKey($this->state, KeyCode::DOWN);
  masteryMenuKey($this->state, KeyCode::DOWN);
  $detail = masteryMenuDetail($this->state);

  expect($this->state->getPresentationContent()->focusedNode)->toBe('tempest')
    ->and($detail)->toContain('Unlocks at Lv 5')->toContain('Unlocked after learning Storm');

  masteryMenuKey($this->state, KeyCode::ENTER);
  expect($this->state->getPresentationContent()->status)->toBe('Unlocks at Lv 5. Unlocked after learning Storm.');

  masteryMenuKey($this->state, KeyCode::DOWN);
  $detail = masteryMenuDetail($this->state);
  masteryMenuKey($this->state, KeyCode::ENTER);

  expect($this->state->getPresentationContent()->focusedNode)->toBe('veil')
    ->and($detail)->toContain('???')->not->toContain('Veil')->not->toContain('200')
    ->and($modals->asked)->toBe([])
    ->and($this->hero->mastery->points)->toBe(500);
});

it('shows points with the project\'s own label, and never shows engineering words', function () {
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['vocab' => ['mastery' => ['points' => 'JP', 'points_name' => 'Job Points']]]));
  masteryMenuHero($this->hero, 30);
  $modals = masteryMenuAnswers(false);
  $this->state->enter();
  // Nothing is affordable yet, so the menu opens on the first region; the storms are one step along.
  masteryMenuKey($this->state, KeyCode::RIGHT);
  $content = $this->state->getPresentationContent();
  $summary = new ReflectionMethod($this->state, 'buildSummaryLines')->invoke($this->state);
  $words = masteryMenuWords($this->state);
  masteryMenuKey($this->state, KeyCode::DOWN);
  $words .= masteryMenuWords($this->state);
  $modals->answer = false;
  $this->hero->mastery->points = 100;
  masteryMenuKey($this->state, KeyCode::UP);
  masteryMenuKey($this->state, KeyCode::ENTER);

  expect($content->getPointsText(0))->toBe('JP 30')
    ->and($summary[0])->toContain('JP 30')
    ->and($content->description)->toContain('Job Points (JP)')
    ->and($modals->asked)->toBe(['Learn Spark for 50 JP?'])
    ->and($words)->toContain('Not enough JP')
    ->and($words)->not->toMatch('/\\b(banked?|stud(y|ies|ying)|regions?|nodes?|states?|needs|opens?)\\b|to learn:/i');
});
