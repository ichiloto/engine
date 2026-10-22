<?php

declare(strict_types=1);

use Assegai\Collections\Stack;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryFamily;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\MasteryMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Presentation\MasteryLearnHold;
use Ichiloto\Engine\UI\Presentation\MasteryMenuNode;
use Ichiloto\Engine\UI\Presentation\MasteryPointsCount;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeHeldInputSource;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeHeldInputSource.php';

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

/** Presses keys in the menu on input without held keys, as the Terminal reports them. */
function masteryMenuKey(MasteryMenuState $state, KeyCode ...$keys): void
{
  foreach ($keys as $key) {
    InputManager::setInputSource(new FakeInputSource($key));
    InputManager::handleInput();
    $state->execute();
  }
}

/** One input update and menu frame from a source that reports held keys. */
function masteryMenuFrame(MasteryMenuState $state, FakeHeldInputSource $source): void
{
  // Choosing a source forgets held keys, so a source that holds stays chosen across frames.
  if (InputManager::getInputSource() !== $source) {
    InputManager::setInputSource($source);
  }
  InputManager::handleInput();
  $state->execute();
}

/** The Terminal panels' lines, styling removed. */
function masteryMenuTerminal(MasteryMenuState $state, string $panel): array
{
  return array_map(static fn(string $line): string => rtrim(preg_replace('/\e\[[0-9;]*m/', '', $line)),
    new ReflectionProperty($state, $panel)->getValue($state)->getContent());
}

/** Every word the menu shows: tabs, board, selection, footer, Info and the Terminal panels. */
function masteryMenuWords(MasteryMenuState $state): string
{
  $content = $state->getPresentationContent();
  $panels = array_merge(...array_map(static fn(string $panel): array => masteryMenuTerminal($state, $panel),
    ['summaryPanel', 'tabPanel', 'listPanel', 'detailPanel', 'footerPanel']));

  return implode("\n", [...array_map(static fn($tab) => $tab->name, [...$content->regions, ...$content->families]),
    ...array_map(static fn(MasteryMenuNode $node) => $node->label . ' ' . $node->badge, $content->nodes),
    $content->selection->name, $content->selection->subtitle ?? '', $content->selection->price ?? '', $content->selection->action ?? '',
    ...array_column($content->footer, 'text'), ...array_map(static fn($hint) => $hint->label, $content->hints), ...($content->info ?? []),
    ...$panels]);
}

/** Moves the menu's presentation clock on by some seconds, as frames passing would. */
function masteryMenuAdvance(MasteryMenuState $state, float $seconds): void
{
  $clock = new ReflectionProperty($state, 'menuPresentationStart');
  $clock->setValue($state, $clock->getValue($state) - $seconds);
}

/**
 * Writes a synthetic project. Blades holds a trunk skill; Storms holds a Sky element branch (a
 * foundation, two grades and an advanced skill for all foes) and a Veils aspect without a foundation
 * (a hidden skill and a draft whose spread awaits its author).
 */
function writeMasteryMenuProject(string $root): void
{
  mkdir($root . '/assets/Data/Skills', 0777, true);
  mkdir($root . '/assets/Data/Mastery', 0777, true);
  $skills = [new SpecialSkill('Start Cut', 'A first cut.', '', 2, 0, effects: [new HPDamageSkillEffect('1')]),
    new MagicSkill('Spark I', 'Calls a spark down on one foe.', '', 4, 0), new MagicSkill('Spark II', 'A brighter spark.', '', 9, 0),
    new MagicSkill('Storm', 'A storm over every foe.', '', 20, 0, scope: new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ALL)),
    new MagicSkill('Veil', 'A veil.', '', 30, 0, scope: new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE))];
  foreach ($skills as $n => $skill) {
    file_put_contents(sprintf('%s/assets/Data/Skills/%04d.php', $root, $n + 1),
      '<?php return ' . var_export(['class' => \Ichiloto\Engine\Entities\Skills\Skill::class, 'data' => SkillRecord::writeSkill($skill)], true) . ';');
  }
  $gale = SkillRecord::writeSkill(new MagicSkill('Gale', 'A gale at one foe.', '', 12, 0, effects: [new HPDamageSkillEffect('2')]));
  unset($gale['effects'][0]['variance']);
  $gale['unassigned'] = ['effects.0.variance'];
  file_put_contents($root . '/assets/Data/Skills/0006.php', '<?php return ' . var_export(['class' => \Ichiloto\Engine\Entities\Skills\Skill::class, 'data' => $gale], true) . ';');
  file_put_contents($root . '/assets/Data/mastery.php', '<?php return ' . var_export([
    'regions' => [
      ['id' => 'blades', 'name' => 'Blades', 'nodes' => [['id' => 'cut', 'kind' => 'application', 'skill' => 'Start Cut']]],
      ['id' => 'storms', 'name' => 'Storms'],
    ],
    'branches' => [
      ['id' => 'sky', 'region' => 'storms', 'name' => 'Sky', 'kind' => 'element', 'element' => 'Wind', 'description' => 'The open sky.', 'nodes' => [
        ['id' => 'sky', 'kind' => 'foundation', 'name' => 'Sky'],
        ['id' => 'spark-1', 'kind' => 'application', 'grade' => 1, 'skill' => 'Spark I'],
        ['id' => 'spark-2', 'kind' => 'application', 'grade' => 2, 'skill' => 'Spark II'],
        ['id' => 'storm', 'kind' => 'advanced', 'skill' => 'Storm'],
      ]],
      ['id' => 'veils', 'region' => 'storms', 'name' => 'Veils', 'kind' => 'aspect', 'nodes' => [
        ['id' => 'veil', 'kind' => 'application', 'skill' => 'Veil'],
        ['id' => 'gale', 'kind' => 'application', 'skill' => 'Gale'],
      ]],
    ],
  ], true) . ';');
  file_put_contents($root . '/assets/Data/Mastery/hero.php', '<?php return ' . var_export(['actor' => 'hero', 'nodes' => [
    ['id' => 'cut', 'cost' => 0],
    ['id' => 'spark-1', 'cost' => 50],
    ['id' => 'spark-2', 'cost' => 80, 'opensAtLevel' => 5, 'requires' => ['spark-1']],
    ['id' => 'storm', 'cost' => 120, 'requires' => ['spark-2']],
    ['id' => 'veil', 'cost' => 200, 'requires' => ['storm'], 'hidden' => true],
    ['id' => 'gale', 'cost' => 30],
  ]], true) . ';');
}

/** The hero as a save brings them: the free skill learned, holding some points, their role recommending a route. */
function masteryMenuHero(Character $hero, int $points): void
{
  \Ichiloto\Engine\Progression\Mastery\MasteryProgression::forCharacter($hero)?->reconcile($hero);
  $hero->mastery->points = $points;
  $hero->role = new CharacterRole($hero, 'Stormcaller', curriculum: ['spark-1', 'storm']);
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
    'character_next' => KeyCode::TAB, 'character_previous' => KeyCode::SHIFT_TAB, 'menu_tab_previous' => KeyCode::q, 'menu_tab_next' => KeyCode::e,
    'menu_page_previous' => KeyCode::PAGE_UP, 'menu_page_next' => KeyCode::PAGE_DOWN, 'map_zoom' => KeyCode::z, 'curriculum' => KeyCode::c,
    'info' => KeyCode::i]));
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

it('opens on the first skill the character can learn and shows the whole board, unavailable places included, to both renderers', function () {
  masteryMenuHero($this->hero, 60);
  $this->state->enter();
  $content = $this->state->getPresentationContent();
  $states = array_column(array_map(static fn(MasteryMenuNode $node): array => [$node->id, $node->state?->value ?? 'heading'], $content->nodes), 1, 0);
  $list = masteryMenuTerminal($this->state, 'listPanel');

  expect(MasteryMenuState::projectHasMaps())->toBeTrue()
    ->and(array_map(static fn($tab) => [$tab->name, $tab->describeCount()], $content->regions))->toBe([['Blades', '1/1'], ['Storms', '0/6']])
    ->and($content->regionIndex)->toBe(1)
    ->and($content->focus)->toBe('spark-1')
    ->and(array_map(static fn($tab) => $tab->name, $content->families))->toBe(['Sky', 'Veils'])
    // Nothing prices the foundation and the draft awaits its author: both are on the board, and unavailable.
    ->and($states)->toBe(['sky' => 'unavailable', 'spark-1' => 'open', 'spark-2' => 'locked', 'storm' => 'locked',
      MasteryFamily::getHeadingId('veils') => 'heading', 'veil' => 'locked', 'gale' => 'unavailable'])
    ->and($content->selection->name)->toBe('Spark I')
    ->and($content->selection->price)->toBe('50 SP')
    ->and($content->selection->action)->toBe('Learn')
    ->and($content->selection->enabled)->toBeTrue()
    // The Terminal lists the focused family: its count on the top row, each value in one aligned column.
    ->and($list[0])->toMatch('/^\s+Sky\s+0 \/ 4 learned$/')
    ->and($list[1])->toMatch('/^\s+Spark I\s+50 SP$/')
    ->and($list[2])->toContain('Unlocks at Lv 5')->toContain('Learn Spark I first')
    ->and(strpos($list[1], '50 SP'))->toBe(strpos($list[0], '0 / 4'))
    ->and(masteryMenuTerminal($this->state, 'tabPanel')[1])->toContain('Storms / Sky');
});

it('asks on the footer line at the first Learn press without held keys, learns at the second, and says the price once', function () {
  masteryMenuHero($this->hero, 60);
  $this->state->enter();

  masteryMenuKey($this->state, KeyCode::ENTER);
  $content = $this->state->getPresentationContent();
  expect(array_column($content->footer, 'text'))->toBe(['Learn Spark I', '50 SP'])
    ->and($this->hero->mastery->points)->toBe(60)
    ->and(masteryMenuTerminal($this->state, 'footerPanel'))->toContain(' Learn Spark I')
    // While the footer asks, the row leaves the price to it.
    ->and(masteryMenuTerminal($this->state, 'listPanel')[1])->toMatch('/^\s+Spark I$/');

  // Cancel dismisses the question without spending, and the menu stays open.
  masteryMenuKey($this->state, KeyCode::ESCAPE);
  expect($this->hero->mastery->points)->toBe(60)
    ->and($this->state->getPresentationContent()->footer[0]['text'])->not->toBe('Learn Spark I')
    ->and($this->returnState->entries)->toBe(0);

  // Moving dismisses it too.
  masteryMenuKey($this->state, KeyCode::ENTER, KeyCode::DOWN, KeyCode::UP, KeyCode::ENTER);
  expect($this->hero->mastery->hasLearned('spark-1'))->toBeFalse();

  masteryMenuKey($this->state, KeyCode::ENTER);
  $content = $this->state->getPresentationContent();
  expect($this->hero->mastery->points)->toBe(10)
    ->and($this->hero->mastery->hasLearned('spark-1'))->toBeTrue()
    ->and($this->hero->spellbook->getLearnedSpells()[0]->name)->toBe('Spark I')
    ->and($content->selection->subtitle)->toBe('Learned')
    ->and($content->selection->action)->toBeNull()
    ->and($content->selection->price)->toBeNull()
    // The Terminal shows the new total at once.
    ->and($content->pointsCount)->toBeNull()
    ->and(masteryMenuTerminal($this->state, 'summaryPanel')[2])->toContain('SP 10');

  masteryMenuKey($this->state, KeyCode::ESCAPE);
  expect($this->returnState->entries)->toBe(1);
});

it('learns once a held Learn completes, fills and drains only as a preview, and cancels on release or a move', function () {
  masteryMenuHero($this->hero, 60);
  $this->state->enter();
  new ReflectionProperty($this->state, 'isMenuCanvasShown')->setValue($this->state, true);
  $source = new FakeHeldInputSource();

  // Held halfway: nothing is spent; the drawn total drains as a preview.
  $source->press('enter', KeyCode::ENTER);
  masteryMenuFrame($this->state, $source);
  masteryMenuAdvance($this->state, MasteryLearnHold::DURATION / 2);
  masteryMenuFrame($this->state, $source);
  $content = $this->state->getPresentationContent();
  $time = $content->hold->startedAt + MasteryLearnHold::DURATION / 2;
  expect($content->hold?->node)->toBe('spark-1')
    ->and($this->hero->mastery->points)->toBe(60)
    ->and($content->getPointsAt($time))->toBe(35)
    ->and($content->hints[4]->label)->toBe('Hold · Learn');

  // Letting go cancels: the total returns and nothing is learned.
  $source->release('enter');
  masteryMenuFrame($this->state, $source);
  expect($this->state->getPresentationContent()->hold)->toBeNull()
    ->and($this->hero->mastery->points)->toBe(60)
    ->and($this->hero->mastery->hasLearned('spark-1'))->toBeFalse();

  // Moving the focus cancels too.
  $source->press('enter', KeyCode::ENTER);
  masteryMenuFrame($this->state, $source);
  $source->press('down', KeyCode::DOWN)->release('down');
  masteryMenuFrame($this->state, $source);
  expect($this->state->getPresentationContent()->hold)->toBeNull();
  $source->release('enter');
  masteryMenuFrame($this->state, $source);

  // A completed hold learns exactly once, and Learn must come up before another hold.
  $source->press('up', KeyCode::UP)->release('up');
  masteryMenuFrame($this->state, $source);
  $source->press('enter', KeyCode::ENTER);
  masteryMenuFrame($this->state, $source);
  masteryMenuAdvance($this->state, MasteryLearnHold::DURATION + 0.01);
  masteryMenuFrame($this->state, $source);
  masteryMenuAdvance($this->state, MasteryLearnHold::DURATION + 0.01);
  masteryMenuFrame($this->state, $source);
  expect($this->hero->mastery->points)->toBe(10)
    ->and($this->hero->mastery->hasLearned('spark-1'))->toBeTrue()
    ->and($this->state->getPresentationContent()->hold)->toBeNull()
    // The drained total does not count down again afterwards.
    ->and($this->state->getPresentationContent()->pointsCount)->toBeNull();
});

it('takes as long to hold under reduced motion, without the drain, and changes the total when it completes', function () {
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['accessibility' => ['reducedMotion' => true]]));
  masteryMenuHero($this->hero, 60);
  $this->state->enter();
  $source = new FakeHeldInputSource();
  $source->press('enter', KeyCode::ENTER);
  masteryMenuFrame($this->state, $source);
  masteryMenuAdvance($this->state, MasteryLearnHold::DURATION / 2);
  masteryMenuFrame($this->state, $source);
  $content = $this->state->getPresentationContent();

  expect($content->reducedMotion)->toBeTrue()
    ->and($content->getPointsAt($content->hold->startedAt + MasteryLearnHold::DURATION / 2))->toBe(60)
    ->and($this->hero->mastery->hasLearned('spark-1'))->toBeFalse();

  masteryMenuAdvance($this->state, MasteryLearnHold::DURATION);
  masteryMenuFrame($this->state, $source);
  expect($this->hero->mastery->points)->toBe(10)
    ->and($this->state->getPresentationContent()->flare)->toBeNull();
});

it('ticks the shared counter only while a drawn total moves, and stops when it lands, is not drawn or closes', function () {
  $manager = makeCounterTickManager();
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $manager);
  $drawn = new ReflectionProperty($this->state, 'isMenuCanvasShown');
  masteryMenuHero($this->hero, 300);
  $this->state->enter();
  $drawn->setValue($this->state, true);
  $source = new FakeHeldInputSource();

  // The tick sounds while a held Learn drains the drawn total, and stops when the hold lets go.
  $source->press('enter', KeyCode::ENTER);
  masteryMenuFrame($this->state, $source);
  masteryMenuAdvance($this->state, 0.2);
  masteryMenuFrame($this->state, $source);
  $manager->update();
  expect($manager->counterSound->isPlaying)->toBeTrue();
  $source->release('enter');
  masteryMenuFrame($this->state, $source);
  $manager->update();
  expect($manager->counterSound->isPlaying)->toBeFalse();

  // A two-press learn counts the drawn total down afterwards; a frame that draws nothing plays no tick.
  InputManager::setInputSource(new FakeInputSource());
  masteryMenuKey($this->state, KeyCode::ENTER);
  expect($manager->counterSound->isPlaying)->toBeFalse();
  $source->reportsHeldState = false;
  masteryMenuFrame($this->state, $source);
  masteryMenuKey($this->state, KeyCode::ENTER);
  $manager->update();
  expect($this->hero->mastery->points)->toBe(250)
    ->and($manager->counterSound->isPlaying)->toBeTrue();
  masteryMenuAdvance($this->state, MasteryPointsCount::DURATION);
  $this->state->execute();
  $manager->update();
  expect($manager->counterSound->isPlaying)->toBeFalse();
  $this->state->exit();
  $manager->shutdown();
});

it('names a reach once: on the badge of a skill without a grade, otherwise in the footer, with its MP from the record', function () {
  masteryMenuHero($this->hero, 500);
  $this->state->enter();
  $nodes = array_column(array_map(static fn(MasteryMenuNode $node): array => [$node->id, $node], $this->state->getPresentationContent()->nodes), 1, 0);

  expect($nodes['spark-1']->badge)->toBe('I')
    ->and($nodes['spark-2']->badge)->toBe('II')
    ->and($nodes['storm']->badge)->toBe('All foes')
    ->and($nodes['storm']->badgeIsTarget)->toBeTrue()
    ->and($nodes['sky']->badge)->toBeNull()
    // A secret shows no badge or name.
    ->and($nodes['veil']->label)->toBe('???')
    ->and($nodes['veil']->badge)->toBeNull()
    // Every node is named, whatever its kind.
    ->and(array_filter($nodes, static fn(MasteryMenuNode $node): bool => $node->label === ''))->toBe([])
    ->and(array_column($this->state->getPresentationContent()->footer, 'text'))->toBe(['MP', '4', 'One foe']);

  new ReflectionMethod($this->state, 'setFocus')->invoke($this->state, 'storm');
  expect(array_column($this->state->getPresentationContent()->footer, 'text'))->toBe(['MP', '20']);
  // A draft's MP is the value its author gave.
  new ReflectionMethod($this->state, 'setFocus')->invoke($this->state, 'gale');
  expect(array_column($this->state->getPresentationContent()->footer, 'text'))->toBe(['MP', '12'])
    ->and($this->state->getPresentationContent()->selection->subtitle)->toBe('Unavailable')
    ->and($this->state->getPresentationContent()->selection->action)->toBeNull();
  // A family's top says its counts, never a casting value.
  new ReflectionMethod($this->state, 'setFocus')->invoke($this->state, MasteryFamily::getHeadingId('veils'));
  expect(array_column($this->state->getPresentationContent()->footer, 'text'))->toBe(['0 / 2 learned']);
});

it('moves along the drawn tree, pages families and disciplines, and zooms out to family summaries without learning', function () {
  masteryMenuHero($this->hero, 500);
  $this->state->enter();
  new ReflectionProperty($this->state, 'isMenuCanvasShown')->setValue($this->state, true);
  $focus = fn(): ?string => $this->state->getPresentationContent()->focus;

  masteryMenuKey($this->state, KeyCode::DOWN);
  expect($focus())->toBe('spark-2');
  masteryMenuKey($this->state, KeyCode::UP, KeyCode::UP);
  expect($focus())->toBe('sky');
  masteryMenuKey($this->state, KeyCode::RIGHT);
  expect($focus())->toBe(MasteryFamily::getHeadingId('veils'))
    ->and($this->state->getPresentationContent()->familyIndex)->toBe(1);
  masteryMenuKey($this->state, KeyCode::PAGE_DOWN);
  expect($focus())->toBe('sky');
  masteryMenuKey($this->state, KeyCode::e);
  expect($this->state->getPresentationContent()->regionIndex)->toBe(0)
    ->and($focus())->toBe(MasteryFamily::getHeadingId(MasteryFamily::getTrunkId('blades')));
  masteryMenuKey($this->state, KeyCode::q);

  masteryMenuKey($this->state, KeyCode::z);
  $content = $this->state->getPresentationContent();
  expect($content->overview)->toBeTrue()
    ->and($content->focus)->toBeNull()
    ->and($content->selection->name)->toBe('Sky')
    ->and(array_column($content->footer, 'text'))->toBe(['2 families', '0 / 6 learned'])
    ->and($content->hints[3]->label)->toBe('Detail');
  masteryMenuKey($this->state, KeyCode::RIGHT, KeyCode::ENTER);
  expect($this->state->getPresentationContent()->overview)->toBeFalse()
    ->and($focus())->toBe(MasteryFamily::getHeadingId('veils'))
    ->and($this->hero->mastery->points)->toBe(500);
});

it('marks the role\'s curriculum as a guide, and opens and closes Info with what a skill builds on', function () {
  masteryMenuHero($this->hero, 500);
  $this->state->enter();
  masteryMenuKey($this->state, KeyCode::c, KeyCode::DOWN);
  $content = $this->state->getPresentationContent();
  $curriculum = array_values(array_map(static fn(MasteryMenuNode $node): string => $node->id,
    array_filter($content->nodes, static fn(MasteryMenuNode $node): bool => $node->curriculum)));

  expect($content->curriculum)->toBeTrue()
    ->and($content->role)->toBe('Stormcaller')
    ->and($curriculum)->toBe(['spark-1', 'storm'])
    // A curriculum never locks or opens anything.
    ->and(array_find($content->nodes, static fn(MasteryMenuNode $node): bool => $node->id === 'spark-2')->state)->toBe(MasteryNodeState::LOCKED);

  masteryMenuKey($this->state, KeyCode::i);
  expect($this->state->getPresentationContent()->info)->toBe(['A brighter spark.', 'After Spark I'])
    ->and($this->state->getPresentationContent()->hints[array_key_last($this->state->getPresentationContent()->hints)]->label)->toBe('Close')
    ->and(masteryMenuTerminal($this->state, 'detailPanel')[0])->toContain('A brighter spark.');
  // Cancel closes Info before it leaves the menu.
  masteryMenuKey($this->state, KeyCode::ESCAPE);
  expect($this->state->getPresentationContent()->info)->toBeNull()
    ->and($this->returnState->entries)->toBe(0);
});

it('says why a skill waits, refuses it without asking, and keeps a hidden one a secret', function () {
  masteryMenuHero($this->hero, 60);
  $this->state->enter();
  masteryMenuKey($this->state, KeyCode::DOWN);
  $content = $this->state->getPresentationContent();
  expect($content->selection->subtitle)->toBe('Unlocks at Lv 5 · Learn Spark I first')
    ->and($content->selection->enabled)->toBeFalse();

  masteryMenuKey($this->state, KeyCode::ENTER);
  expect(array_column($this->state->getPresentationContent()->footer, 'text'))->not->toContain('Learn Spark II')
    ->and($this->hero->mastery->points)->toBe(60);

  new ReflectionMethod($this->state, 'setFocus')->invoke($this->state, 'veil');
  $content = $this->state->getPresentationContent();
  expect($content->selection->name)->toBe('???')
    ->and(implode(' ', [$content->selection->subtitle, ...array_column($content->footer, 'text')]))->not->toContain('Veil')->not->toContain('200');
});

it('uses the project\'s own words and never engineering words', function () {
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['vocab' => ['menu' => ['mastery' => 'Mastery'],
    'mastery' => ['points' => 'JP', 'points_name' => 'Job Points', 'learn' => 'Study']]]));
  masteryMenuHero($this->hero, 30);
  $this->state->enter();
  // Nothing is affordable, so the board opens on the first discipline; the storms are the next tab.
  masteryMenuKey($this->state, KeyCode::e, KeyCode::DOWN);
  $words = masteryMenuWords($this->state);
  masteryMenuKey($this->state, KeyCode::z);
  $words .= masteryMenuWords($this->state);
  masteryMenuKey($this->state, KeyCode::z, KeyCode::i);
  $words .= masteryMenuWords($this->state);
  $content = $this->state->getPresentationContent();

  expect($content->title)->toBe('Mastery')
    ->and($words)->toContain('JP 30')->toContain('Not enough JP')->toContain('50 JP')
    ->and($content->selection->action)->toBe('Study')
    ->and($words)->not->toMatch('/\\b(banked?|stud(ies|ying)|regions?|nodes?|states?|branch(es)?|trunk|heading|needs|opens?)\\b|to learn:/i');
});
