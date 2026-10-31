<?php

declare(strict_types=1);

use Assegai\Collections\Stack;
use Ichiloto\Engine\Battle\CrisisAbility;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ShowPowerUnlocksCommand;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Progression\PowerUnlockShowcase;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\NoticeContent;
use Ichiloto\Engine\UI\Modal\NoticeSequenceOperation;
use Ichiloto\Engine\Util\Config\ConfigStore;

final class PowerShowcaseGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

final class PowerShowcaseModals extends ModalManager
{
  public array $delivered = [];
  public bool $fail = false;
  public ?Closure $whileShowing = null;

  public function __construct(Game $game)
  {
    $this->game = $game;
    $this->modals = new Stack(ModalInterface::class);
  }

  protected function showPendingNotice(NoticeContent $notice): void
  {
    ($this->whileShowing ?? static fn() => null)();
    if ($this->fail) { throw new RuntimeException('Delivery interrupted'); }
    $this->delivered[] = $notice;
  }
}

beforeEach(function () {
  $this->previousCatalog = ConfigStore::has(WorldAbilityCatalog::class) ? ConfigStore::get(WorldAbilityCatalog::class) : null;
  $this->abilities = new WorldAbilityCatalog([
    ['id' => 'fast', 'name' => 'Current fast move', 'description' => 'Current movement copy.', 'iconRole' => 'power.fast',
      'conditions' => [['type' => 'switch', 'name' => 'form']]],
    ['id' => 'sense', 'name' => 'Current sensing', 'description' => 'Current sensing copy.',
      'conditions' => [['type' => 'world_ability', 'name' => 'fast']]],
  ]);
  ConfigStore::put(WorldAbilityCatalog::class, $this->abilities);
  $this->state = new GameState();
  $this->game = new PowerShowcaseGame();
  $this->modals = new PowerShowcaseModals($this->game);
  new ReflectionProperty(Game::class, 'modalManager')->setValue($this->game, $this->modals);
});

afterEach(function () {
  $this->previousCatalog === null ? ConfigStore::remove(WorldAbilityCatalog::class)
    : ConfigStore::put(WorldAbilityCatalog::class, $this->previousCatalog);
});

it('projects the real names, descriptions and icon roles and refuses a locked or unresolved power without world writes', function () {
  expect(fn() => PowerUnlockShowcase::getNotices($this->abilities, ['fast'], $this->state))
    ->toThrow(InvalidArgumentException::class, 'locked');
  $this->state->setSwitch('form', true); $before = $this->state->toArray();
  $notices = PowerUnlockShowcase::getNotices($this->abilities, ['sense', 'fast'], $this->state);
  expect(array_column($notices, 'title'))->toBe(['Current sensing', 'Current fast move'])
    ->and($notices[1]->message)->toBe('Current movement copy.')
    ->and($notices[1]->iconRole)->toBe('power.fast')
    ->and($this->state->toArray())->toBe($before)
    ->and(fn() => PowerUnlockShowcase::getNotices($this->abilities, ['missing'], $this->state))->toThrow(InvalidArgumentException::class)
    ->and(fn() => PowerUnlockShowcase::getNotices($this->abilities, ['fast', 'fast'], $this->state))->toThrow(InvalidArgumentException::class)
    ->and(fn() => PowerUnlockShowcase::getNotices($this->abilities, [], $this->state))->toThrow(InvalidArgumentException::class);
});

it('reads the actual unlocked crisis technique while healthy and preserves actor resources and source round trips', function () {
  $root = createTestDirectory('showcase-skills');
  writeSkillRecords($root, new SpecialSkill('Current technique', 'Current technique copy.', '', 0, 0));
  $ability = CrisisAbility::fromArray(['name' => 'Crisis', 'hpBelowPercent' => 10, 'iconRole' => 'power.crisis',
    'variants' => [['skill' => 'Current technique', 'conditions' => [['type' => 'switch', 'name' => 'form']]]]]);
  $actor = new Character('Current actor', 1, new Stats(), actorId: 'actor.test', crisisAbility: $ability);
  $actor->stats->totalHp = $actor->stats->currentHp = 100;
  $actor->stats->currentMp = 7;
  $skills = SkillCatalog::load($root . '/assets');
  expect(fn() => PowerUnlockShowcase::getNotices($this->abilities, [], $this->state, actor: $actor,
    includeCrisis: true, skills: $skills))->toThrow(InvalidArgumentException::class, 'no unlocked');
  $this->state->setSwitch('form', true); $before = serialize($actor);
  $notices = PowerUnlockShowcase::getNotices($this->abilities, ['fast'], $this->state,
    actor: $actor, includeCrisis: true, announcement: 'The authored announcement.', skills: $skills);
  expect(array_column($notices, 'title'))->toBe(['', 'Current fast move', 'Current technique'])
    ->and($notices[0]->actorId)->toBe('actor.test')->and($notices[2]->message)->toBe('Current technique copy.')
    ->and($notices[2]->iconRole)->toBe('power.crisis')->and($notices[2]->actorId)->toBeNull()
    ->and($ability->isActive($actor))->toBeFalse()->and(serialize($actor))->toBe($before)
    ->and(CrisisAbility::fromArray($ability->toArray())->iconRole)->toBe('power.crisis');
});

it('owns each notice until dismissal, retries failed delivery and cancels only its own remaining queue entries', function () {
  $first = new NoticeContent('One', 'First'); $second = new NoticeContent('Two', 'Second');
  $operation = new NoticeSequenceOperation($this->modals, [$first, $second]);
  expect($operation->update(0))->toBeFalse();
  $this->modals->whileShowing = function () use ($operation) { expect($operation->update(100))->toBeFalse(); };
  $this->modals->fail = true;
  expect(fn() => $this->modals->processPendingAlerts())->toThrow(RuntimeException::class)
    ->and($operation->update(0))->toBeFalse()->and($this->modals->delivered)->toBe([]);
  $this->modals->fail = false; $this->modals->processPendingAlerts();
  expect($this->modals->delivered)->toBe([$first])->and($operation->update(0))->toBeFalse();
  $other = new NoticeContent('Other', 'Independent producer'); $this->modals->queueNotice($other, new stdClass());
  $operation->cancel(); $this->modals->whileShowing = null;
  $this->modals->processPendingAlerts();
  expect($this->modals->delivered)->toBe([$first, $other])->and($operation->update(0))->toBeTrue();
});

it('keeps the actual field interpreter pending through the final acknowledgement', function () {
  $scene = makeBareScene(GameScene::class); $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty(SceneManager::class, 'game')->setValue($manager, $this->game);
  new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($scene, $manager);
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($scene, $this->state);
  $this->state->setSwitch('form', true);
  $interpreter = new EventInterpreter($scene, scriptCommands: ScriptCommandCatalog::createEngineCatalog());
  $session = $interpreter->run([
    ['type' => 'show_power_unlocks', 'abilities' => [['ability' => 'fast'], ['ability' => 'sense']]],
    ['type' => 'set_switch', 'name' => 'after_showcase', 'value' => true],
  ]);
  $this->modals->whileShowing = function () use ($interpreter) {
    $interpreter->update(0);
    expect($this->state->getSwitch('after_showcase'))->toBeFalse();
  };
  $interpreter->update(0); $this->modals->processPendingAlerts(); $interpreter->update(0);
  expect($session->status)->not->toBe(EventExecutionStatus::COMPLETED)
    ->and($this->state->getSwitch('after_showcase'))->toBeFalse()->and(count($this->modals->delivered))->toBe(1);
  $this->modals->processPendingAlerts(); $interpreter->update(0);
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($this->state->getSwitch('after_showcase'))->toBeTrue()->and(count($this->modals->delivered))->toBe(2);
});

it('declares constrained power and actor references and refuses malformed direct command calls before delivery', function () {
  $definition = ScriptCommandCatalog::createEngineCatalog()->findDefinition('show_power_unlocks');
  expect($definition->fields[0]->fields[0]->reference)->toBe(ScriptCommandReference::WORLD_ABILITY)
    ->and($definition->fields[1]->reference)->toBe(ScriptCommandReference::ACTOR);
  $scene = makeBareScene(GameScene::class);
  expect(fn() => new ShowPowerUnlocksCommand()->execute(new ScriptCommandContext($scene), ['abilities' => 'bad']))
    ->toThrow(RuntimeException::class)
    ->and(fn() => new ShowPowerUnlocksCommand()->execute(new ScriptCommandContext($scene), ['includeCrisis' => 'yes']))
    ->toThrow(RuntimeException::class);
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($scene, $this->state);
  expect(fn() => new ShowPowerUnlocksCommand()->execute(new ScriptCommandContext($scene), ['actor' => 'missing']))
    ->toThrow(RuntimeException::class, 'current party actor');
});
