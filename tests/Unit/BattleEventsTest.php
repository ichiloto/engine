<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Events\BattleEventPage;
use Ichiloto\Engine\Battle\Events\BattleEventRunner;
use Ichiloto\Engine\Battle\Events\BattleEventSpan;
use Ichiloto\Engine\Battle\Events\BattleOutcome;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\EnemyDefeatStyle;
use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Skills\SkillTargetPolicy;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

final class BattleEventWorldScene extends GameScene
{
  public array $finished = [];
  public function __construct()
  {
    $this->currentMapId = 'battle-event-test';
    $this->gameState = new GameState();
  }
  public function onEventSessionStarted(EventExecutionSession $session): void {}
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
  {
    $this->finished[] = $completed;
  }
}

final class BattleEventLinePresentation implements EventPresentationInterface
{
  /** @var list<array{string, string}> */
  public array $lines = [];
  private bool $showing = false;
  public function beginText(string $text, string $speaker): void { $this->lines[] = [$speaker, $text]; $this->showing = true; }
  public function beginChoice(string $prompt, array $options, string $title = ''): void { $this->showing = true; }
  public function update(): void {}
  public function render(): void {}
  public function isComplete(): bool { return ! $this->showing; }
  public function choiceResult(): ?int { return 0; }
  public function reset(): void { $this->showing = false; }
  public function confirm(): void { $this->showing = false; }
}

function battleEventEnemy(string $name, int $hp = 100, int $experience = 10): Enemy
{
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();

  foreach ([
    'name' => $name,
    'level' => 1,
    'stats' => new Stats(currentHp: $hp, totalHp: 100, attack: 50),
    'imagePath' => '',
    'image' => ['(o_o)'],
    'position' => new Vector2(),
    'rewards' => new \Ichiloto\Engine\Battle\BattleRewards($experience, 5, []),
  ] as $property => $value) {
    new ReflectionProperty(Enemy::class, $property)->setValue($enemy, $value);
  }

  return $enemy;
}

function battleEventPage(array $page, int $members = 2): BattleEventPage
{
  return BattleEventPage::fromArray($page, 'test troop events[0]', $members, ScriptCommandCatalog::createEngineCatalog());
}

/** @param list<BattleEventPage> $pages */
function battleEventRunner(array $pages, array $enemies, array $party = [], ?BattleEventLinePresentation $presentation = null,
  ?GameScene $scene = null): BattleEventRunner
{
  return new BattleEventRunner(new Troop('Synthetic trial', $enemies, $pages), static fn(): array => $party,
    $scene ?? new BattleEventWorldScene(), null, $presentation ?? new BattleEventLinePresentation());
}

beforeEach(function () {
  $this->debugBefore = new ReflectionClass(Debug::class)->getStaticProperties();
  $this->logs = sys_get_temp_dir() . '/ichiloto-battle-events-' . bin2hex(random_bytes(4));
  mkdir($this->logs);
  Debug::configure(['log_directory' => $this->logs]);
});

afterEach(function () {
  foreach ($this->debugBefore as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
  foreach (glob($this->logs . '/*') ?: [] as $path) { unlink($path); }
  rmdir($this->logs);
});

it('reads an authored page and keeps its authored shape', function () {
  $authored = [
    'name' => 'Concession',
    'span' => 'battle',
    'conditions' => ['turn' => 2, 'turnEvery' => 3, 'enemy' => 1, 'enemyHpPercent' => 25,
      'world' => [['type' => 'switch', 'name' => 'trial_open', 'value' => true]]],
    'commands' => [['type' => 'text', 'name' => 'Rival', 'text' => 'Enough.'], ['type' => 'concede', 'enemy' => 1]],
  ];
  $page = battleEventPage($authored);

  expect($page->span)->toBe(BattleEventSpan::BATTLE)
    ->and($page->toArray())->toBe($authored);
});

it('refuses malformed pages with messages that say what to fix', function (array $page, string $message) {
  expect(fn() => battleEventPage($page))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'no conditions' => [['commands' => [['type' => 'wait']]], 'needs at least one condition'],
  'no commands' => [['conditions' => ['turn' => 1]], 'needs a list of commands'],
  'unknown key' => [['conditions' => ['turn' => 1], 'commands' => [['type' => 'wait']], 'trigger' => 'x'], 'unknown key "trigger"'],
  'unknown span' => [['span' => 'always', 'conditions' => ['turn' => 1], 'commands' => [['type' => 'wait']]], 'span must be battle, turn or moment'],
  'member out of range' => [['conditions' => ['enemy' => 2, 'enemyHpPercent' => 50], 'commands' => [['type' => 'wait']]], 'names troop member 2, but the troop has 2'],
  'half an HP condition' => [['conditions' => ['enemy' => 0], 'commands' => [['type' => 'wait']]], 'needs both enemy and enemyHpPercent'],
  'turnEvery alone' => [['conditions' => ['turnEvery' => 2], 'commands' => [['type' => 'wait']]], 'turnEvery without the turn'],
  'field-only command' => [['conditions' => ['turn' => 1], 'commands' => [['type' => 'transfer', 'map' => 'x']]], 'runs only in field scripts'],
  'nested field-only command' => [['conditions' => ['turn' => 1], 'commands' => [['type' => 'branch', 'conditions' => [],
    'then' => [['type' => 'shop', 'items' => []]]]]], 'commands[0].then[0] uses "shop", which runs only in field scripts'],
  'concede beyond the troop' => [['conditions' => ['turn' => 1], 'commands' => [['type' => 'concede', 'enemy' => 5]]], 'names troop member 5'],
  'bad outcome' => [['conditions' => ['turn' => 1], 'commands' => [['type' => 'end_battle', 'outcome' => 'draw']]], 'outcome'],
]);

it('holds an enemy above its knockout floor against hits and damaging states', function () {
  $actor = battleEventEnemy('Actor');
  $target = battleEventEnemy('Target', 30);
  $target->setKnockoutFloorPercent(25);

  $hit = new CombatResolver()->resolve(new CombatResolutionRequest(actionId: 'test', executionId: 'test:1',
    actor: $actor, target: $target, rawMagnitude: 500, kind: ResolutionKind::TRUE_DAMAGE));

  expect($target->stats->currentHp)->toBe(25)->and($target->isKnockedOut)->toBeFalse()
    ->and($hit->actualHpLost)->toBe(5);

  $target->addState(new State('burn', 'Burn', durationTurns: 3, tickFormula: '-50'));
  $events = $target->tickStates();
  expect($target->stats->currentHp)->toBe(25)->and($events[0]['hpDelta'])->toBe(0);

  $plain = battleEventEnemy('Plain', 30);
  new CombatResolver()->resolve(new CombatResolutionRequest(actionId: 'test', executionId: 'test:2',
    actor: $actor, target: $plain, rawMagnitude: 500, kind: ResolutionKind::TRUE_DAMAGE));
  expect($plain->isKnockedOut)->toBeTrue();
});

it('takes a conceding enemy out of the fight without knocking it out', function () {
  $rival = battleEventEnemy('Rival', 40);
  $other = battleEventEnemy('Other');
  $troop = new Troop('Synthetic', [$rival, $other]);

  expect($rival->concede())->toBeTrue()->and($rival->concede())->toBeFalse();
  expect($rival->isKnockedOut)->toBeFalse()->and($rival->isFighting)->toBeFalse()
    ->and($rival->knockoutFloor)->toBe(0)
    ->and(BattlePoseRole::getRestingRole($rival))->toBe(BattlePoseRole::CONCEDE)
    ->and(SkillTargetPolicy::filterByStatus([$rival, $other], ItemScopeStatus::ALIVE))->toBe([$other])
    ->and(SkillTargetPolicy::filterByStatus([$rival, $other], ItemScopeStatus::ANY))->toBe([$other])
    ->and(SkillTargetPolicy::filterByStatus([$rival, $other], ItemScopeStatus::DEAD))->toBe([])
    ->and($troop->isDefeated())->toBeFalse();
  $other->concede();
  expect($troop->isDefeated())->toBeTrue();
});

it('keeps a conceded enemy on the Terminal battlefield, dimmed', function () {
  $sprite = EnemyDefeatStyle::applyTerminalConcession(["\033[31m(o_o)\033[0m"]);

  expect($sprite)->toBe(["\033[2m(o_o)\033[0m"]);
});

it('runs a due page through the interpreter: dialogue, then the enemy concedes', function () {
  $rival = battleEventEnemy('Rival', 20);
  $presentation = new BattleEventLinePresentation();
  $runner = battleEventRunner([battleEventPage([
    'conditions' => ['enemy' => 0, 'enemyHpPercent' => 25],
    'commands' => [['type' => 'text', 'name' => 'Rival', 'text' => 'Enough.'],
      ['type' => 'set_switch', 'name' => 'rival_conceded'], ['type' => 'concede', 'enemy' => 0]],
  ], 1)], [$rival], presentation: $presentation);

  expect($runner->queueDuePages(1, 'action:1'))->toBeTrue()
    ->and($runner->update(0.0))->toBeTrue()
    ->and($presentation->lines)->toBe([['Rival', 'Enough.']])
    ->and($rival->hasConceded)->toBeFalse();

  $presentation->confirm();
  expect($runner->update(0.0))->toBeFalse()
    ->and($rival->hasConceded)->toBeTrue()
    ->and($runner->getEventWorldState()->getSwitch('rival_conceded'))->toBeTrue()
    ->and($runner->requestedOutcome)->toBeNull();
});

it('waits for a condition and honours each span', function (string $span, array $expected) {
  $rival = battleEventEnemy('Rival', 100);
  $runner = battleEventRunner([battleEventPage([
    'span' => $span,
    'conditions' => ['turn' => 1, 'turnEvery' => 1],
    'commands' => [['type' => 'set_variable', 'name' => 'runs', 'op' => 'add', 'value' => 1]],
  ], 1)], [$rival]);
  $runs = [];

  foreach ([[1, 'turn:1'], [1, 'action:1'], [1, 'action:1'], [2, 'turn:2'], [2, 'action:2']] as [$turn, $check]) {
    $runner->queueDuePages($turn, $check);
    $runner->update(0.0);
    $runs[] = $runner->getEventWorldState()->getVariable('runs');
  }

  expect($runs)->toBe($expected);
})->with([
  'once per battle' => ['battle', [1, 1, 1, 1, 1]],
  'once per turn' => ['turn', [1, 1, 1, 2, 2]],
  'every check' => ['moment', [1, 2, 2, 3, 4]],
]);

it('tests turn, enemy, party member and world conditions together', function () {
  $rival = battleEventEnemy('Rival', 50);
  $hero = new Character('Hero', 0, new Stats(currentHp: 30, totalHp: 100));
  $scene = new BattleEventWorldScene();
  $runner = battleEventRunner([battleEventPage([
    'conditions' => ['turn' => 2, 'enemy' => 0, 'enemyHpPercent' => 50, 'actor' => $hero->actorId, 'actorHpPercent' => 30,
      'world' => [['type' => 'switch', 'name' => 'gate', 'value' => true]]],
    'commands' => [['type' => 'record_event', 'name' => 'all_held']],
  ], 1)], [$rival], [$hero], scene: $scene);

  expect($runner->queueDuePages(2, 'turn:2'))->toBeFalse();
  $scene->gameState->setSwitch('gate', true);
  expect($runner->queueDuePages(1, 'turn:1'))->toBeFalse();
  $hero->stats->currentHp = 31;
  expect($runner->queueDuePages(2, 'action:5'))->toBeFalse();
  $hero->stats->currentHp = 30;
  expect($runner->queueDuePages(2, 'action:6'))->toBeTrue();
  $runner->update(0.0);
  expect($scene->gameState->hasStoryEvent('all_held'))->toBeTrue();
});

it('lets a conceded or knocked-out enemy no longer satisfy its HP condition', function () {
  $rival = battleEventEnemy('Rival', 10);
  $rival->concede();
  $runner = battleEventRunner([battleEventPage([
    'span' => 'moment', 'conditions' => ['enemy' => 0, 'enemyHpPercent' => 25], 'commands' => [['type' => 'wait']],
  ], 1)], [$rival]);

  expect($runner->queueDuePages(1, 'action:1'))->toBeFalse();
});

it('records the outcome a page asks for and stops the remaining queue', function () {
  $runner = battleEventRunner([
    battleEventPage(['conditions' => ['turn' => 1], 'commands' => [['type' => 'end_battle', 'outcome' => 'victory']]], 1),
    battleEventPage(['conditions' => ['turn' => 1], 'commands' => [['type' => 'record_event', 'name' => 'second']]], 1),
  ], [battleEventEnemy('Rival')]);

  $runner->queueDuePages(1, 'turn:1');
  expect($runner->update(0.0))->toBeFalse()
    ->and($runner->requestedOutcome)->toBe(BattleOutcome::VICTORY)
    ->and($runner->getEventWorldState()->hasStoryEvent('second'))->toBeFalse()
    ->and($runner->queueDuePages(2, 'turn:2'))->toBeFalse();
});

it('fails only the page when a command fails at run time', function () {
  $scene = new BattleEventWorldScene();
  $runner = battleEventRunner([battleEventPage([
    'conditions' => ['turn' => 1], 'commands' => [['type' => 'common_event', 'id' => 'missing-battle-common-event']],
  ], 1)], [battleEventEnemy('Rival')], scene: $scene);

  $runner->queueDuePages(1, 'turn:1');
  expect($runner->update(0.0))->toBeFalse()->and($runner->isRunning)->toBeFalse()
    ->and($scene->finished)->toBe([]);
  expect(file_get_contents(glob($this->logs . '/*')[0]))->toContain('missing-battle-common-event');
});

it('refuses battle commands in field scripts', function () {
  $scene = new BattleEventWorldScene();
  $interpreter = new EventInterpreter($scene, new BattleEventLinePresentation());
  $interpreter->run([['type' => 'end_battle', 'outcome' => 'victory'], ['type' => 'record_event', 'name' => 'after']], 'field');

  expect($scene->finished)->toBe([false])->and($scene->gameState->hasStoryEvent('after'))->toBeFalse();
});

it('refuses field-only commands inside a battle page interpreter', function () {
  $runner = battleEventRunner([], [battleEventEnemy('Rival')]);
  $interpreter = new EventInterpreter(new BattleEventWorldScene(), new BattleEventLinePresentation(), host: $runner);
  $interpreter->run([['type' => 'transfer', 'map' => 'elsewhere'], ['type' => 'record_event', 'name' => 'after']], 'battle');

  expect($runner->getEventWorldState()->hasStoryEvent('after'))->toBeFalse()
    ->and($interpreter->lastSession?->status->value)->toBe('failed');
});

it('reads the troop knockout floor and event pages from troop data', function () {
  $data = ['name' => 'Floor trial', 'enemies' => [['enemy' => 'Rival', 'position' => [1, 1], 'knockoutFloorPercent' => 25]],
    'events' => [['conditions' => ['enemy' => 0, 'enemyHpPercent' => 25], 'commands' => [['type' => 'concede', 'enemy' => 0]]]]];
  $store = (new ReflectionClass(\Ichiloto\Engine\Util\Stores\EnemyStore::class))->newInstanceWithoutConstructor();
  $store->set('Rival', battleEventEnemy('Rival'));
  \Ichiloto\Engine\Util\Config\ConfigStore::put(\Ichiloto\Engine\Util\Stores\EnemyStore::class, $store);

  $troop = Troop::fromArray($data);
  expect($troop->getMember(0)?->knockoutFloor)->toBe(25)
    ->and($troop->eventPages)->toHaveCount(1);

  $data['enemies'][0]['knockoutFloorPercent'] = 100;
  expect(fn() => Troop::fromArray($data))->toThrow(InvalidArgumentException::class, 'knockoutFloorPercent');
});
