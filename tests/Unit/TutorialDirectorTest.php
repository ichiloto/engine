<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\IO\ActionHintProvider;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\Progression\TutorialCatalog;
use Ichiloto\Engine\Progression\TutorialDirector;
use Ichiloto\Engine\Progression\TutorialPhase;
use Ichiloto\Engine\Progression\TutorialStartContext;

function makeTutorialDirector(GameState $state, bool $automatic = false, ?string $onFinish = null, array $startConditions = []): TutorialDirector
{
  return new TutorialDirector(new TutorialCatalog([[
    'id' => 'guide.sample',
    'title' => 'Sample',
    'category' => 'Field',
    'start' => $automatic ? 'automatic' : 'script',
    'trigger' => $automatic ? [['type' => 'switch', 'name' => 'available']] : [],
    'replay' => 'practice',
    'onFinish' => $onFinish,
    'steps' => [[
      'target' => ['screen' => 'menu', 'view' => 'detail', 'id' => 'sample'],
      'action' => 'confirm',
      'until' => ['outcome' => 'sample.applied', 'subject' => 'sample'],
    ], [
      'target' => ['screen' => 'menu', 'view' => 'result', 'id' => 'summary'],
      'action' => 'confirm',
      'until' => ['type' => 'switch', 'name' => 'read'],
    ]],
  ]]), $state, $startConditions);
}

beforeEach(function () {
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint
    {
      return $action === 'confirm' ? new ControlHint('controller', 'south', 'A') : null;
    }
  });
});

afterEach(function () {
  ActionHints::useProvider(null);
});

it('defers a scripted lesson to stable field play and advances only on its view and outcome', function () {
  $state = new GameState();
  $director = makeTutorialDirector($state);
  $director->queueScriptTutorial('guide.sample');
  expect($director->getActiveTutorial())->toBeNull()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::PENDING);

  $restored = GameState::fromArray($state->toArray());
  $director = makeTutorialDirector($restored);
  $director->updateInStableField(false);
  $director->reportOutcome('menu', 'other', 'sample.applied', 'sample');
  $director->reportOutcome('menu', 'detail', 'sample.applied', 'other');
  expect($director->getStepForView('menu', 'detail'))->not->toBeNull();

  $director->reportOutcome('menu', 'detail', 'sample.applied', 'sample');
  $director->evaluateStepCondition('menu', 'result');
  expect($director->getStepForView('menu', 'result'))->not->toBeNull();
  $restored->setSwitch('read');
  $director->evaluateStepCondition('menu', 'detail');
  expect($director->getActiveTutorial())->not->toBeNull();
  $director->evaluateStepCondition('menu', 'result');
  $director->queueScriptTutorial('guide.sample');
  expect($director->getActiveTutorial())->toBeNull()
    ->and($restored->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::COMPLETED);
});

it('checks the current project condition before starting a restored pending lesson', function () {
  $state = new GameState();
  $director = makeTutorialDirector($state);
  $director->queueScriptTutorial('guide.sample');
  $restored = GameState::fromArray($state->toArray());
  $party = new Party();
  $party->setBattleSize(2);
  $director = makeTutorialDirector($restored, startConditions: [
    'guide.sample' => static fn(Party $currentParty, GameState $world): bool =>
      $currentParty->battleSize === 3 && $world->getTutorialPhase('guide.sample') === TutorialPhase::PENDING,
  ]);

  $director->updateInStableField(false);
  expect($director->getActiveTutorial())->toBeNull();
  $director->updateInStableField(false, $party);
  expect($director->getActiveTutorial())->toBeNull()
    ->and($restored->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::PENDING);

  $party->setBattleSize(3);
  $director->updateInStableField(false, $party);
  expect($director->getActiveTutorial()?->id)->toBe('guide.sample');
});

it('rewinds only the guided transition that Back reverses', function () {
  $state = new GameState();
  $director = makeTutorialDirector($state);
  $director->queueScriptTutorial('guide.sample');
  $director->updateInStableField(false);
  $director->rewindStepOnBack('menu', 'detail', 'result');
  expect($director->getStepForView('menu', 'detail'))->not->toBeNull();

  $director->reportOutcome('menu', 'detail', 'sample.applied', 'sample');
  $director->rewindStepOnBack('menu', 'result', 'other');
  $director->rewindStepOnBack('other', 'result', 'detail');
  expect($director->getStepForView('menu', 'result'))->not->toBeNull();

  $director->rewindStepOnBack('menu', 'result', 'detail');
  expect($director->getStepForView('menu', 'detail'))->not->toBeNull()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::PENDING);
  $director->reportOutcome('menu', 'detail', 'sample.applied', 'sample');
  expect($director->getStepForView('menu', 'result'))->not->toBeNull();
});

it('offers practice for skipped lessons and leaves saved progress unchanged', function () {
  $state = new GameState();
  $director = makeTutorialDirector($state);
  expect($director->startPractice('guide.sample'))->toBeFalse();
  $director->queueScriptTutorial('guide.sample');
  $director->updateInStableField(true);
  expect($director->skipActiveTutorial())->toBeTrue()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::SKIPPED)
    ->and($director->getGuideEntries())->toHaveCount(1);

  expect($director->startPractice('guide.sample'))->toBeTrue();
  $director->reportOutcome('menu', 'detail', 'sample.applied', 'sample');
  $state->setSwitch('read');
  $director->evaluateStepCondition('menu', 'result');
  expect($director->getActiveTutorial())->toBeNull()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::SKIPPED);
});

it('observes automatic trigger edges without starting historical or unbound lessons', function () {
  $state = GameState::fromArray(['switches' => ['available' => true]]);
  $director = makeTutorialDirector($state, automatic: true);
  $director->updateInStableField(true);
  expect($state->getTutorialPhase('guide.sample'))->toBeNull();

  $state->setSwitch('available', false);
  $director->updateInStableField(false);
  $state->setSwitch('available');
  $director->updateInStableField(false);
  expect($director->getActiveTutorial())->toBeNull()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::PENDING);

  $director = makeTutorialDirector(GameState::fromArray($state->toArray()), automatic: true);
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint { return null; }
  });
  $director->updateInStableField(true);
  expect($director->getActiveTutorial())->toBeNull();
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint
    {
      return $action === 'confirm' ? new ControlHint('controller', 'south', 'A') : null;
    }
  });
  $director->updateInStableField(false);
  expect($director->getActiveTutorial())->toBeNull();
  $director->updateInStableField(true);
  expect($director->getActiveTutorial()?->id)->toBe('guide.sample');
});

it('registers a tutorial picker and queues a field lesson without suspending the script', function () {
  $state = new GameState();
  $scene = makeBareScene(GameScene::class);
  $director = makeTutorialDirector($state);
  new ReflectionProperty($scene, 'tutorialDirector')->setValue($scene, $director);
  $catalog = ScriptCommandCatalog::createEngineCatalog();
  $definition = $catalog->findDefinition('start_tutorial');

  expect($definition->fields[0]->reference)->toBe(ScriptCommandReference::TUTORIAL)
    ->and($catalog->createHandler('start_tutorial')->execute(
      new ScriptCommandContext($scene),
      ['tutorial' => 'guide.sample'],
    )->operation)->toBeNull()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::PENDING)
    ->and($director->getActiveTutorial())->toBeNull();
});

it('keeps a story handoff pending across save and interruption until its completion is acknowledged', function () {
  $state = new GameState();
  $director = makeTutorialDirector($state, onFinish: 'sample-disclosure');
  $director->queueScriptTutorial('guide.sample');
  $director->updateInStableField(true);
  $director->skipActiveTutorial();
  expect($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::SKIPPED)
    ->and($state->toArray()['tutorialFollowOns'])->toBe(['guide.sample' => true]);

  $restored = GameState::fromArray($state->toArray());
  $director = makeTutorialDirector($restored, onFinish: 'sample-disclosure');
  expect($director->getPendingFollowOns()[0]->onFinish)->toBe('sample-disclosure');
  $director->deferFailedFollowOn('guide.sample');
  expect($director->getPendingFollowOns())->toBe([])
    ->and($restored->hasPendingTutorialFollowOn('guide.sample'))->toBeTrue();

  $director = makeTutorialDirector(GameState::fromArray($restored->toArray()), onFinish: 'sample-disclosure');
  expect($director->getPendingFollowOns())->toHaveCount(1);
  $director->acknowledgeFollowOn('guide.sample');
  expect($director->getPendingFollowOns())->toBe([]);
});

it('does not schedule a story follow-on when practising a migrated tutorial', function () {
  $state = GameState::fromArray(['tutorials' => ['guide.sample' => 'completed']]);
  $director = makeTutorialDirector($state, onFinish: 'sample-disclosure');
  expect($director->startPractice('guide.sample'))->toBeTrue();
  $director->skipActiveTutorial();
  expect($director->getPendingFollowOns())->toBe([])
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::COMPLETED);
});

it('keeps a discovered lesson in the Guide without starting it or claiming completion', function () {
  $state = GameState::fromArray([
    'switches' => ['available' => true],
    'tutorials' => ['guide.sample' => 'discovered'],
  ]);
  $director = makeTutorialDirector($state, automatic: true, onFinish: 'sample-disclosure');
  $director->updateInStableField(true);
  expect($director->getActiveTutorial())->toBeNull()
    ->and(array_column($director->getGuideEntries(), 'id'))->toBe(['guide.sample'])
    ->and($director->getPendingFollowOns())->toBe([]);

  expect($director->startPractice('guide.sample'))->toBeTrue();
  $director->reportOutcome('menu', 'detail', 'sample.applied', 'sample');
  $state->setSwitch('read');
  $director->evaluateStepCondition('menu', 'result');
  expect($director->getActiveTutorial())->toBeNull()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::DISCOVERED)
    ->and($director->getPendingFollowOns())->toBe([]);
});

it('offers a queued story tutorial for Skip when its required control is unbound', function () {
  $state = new GameState();
  $director = makeTutorialDirector($state, onFinish: 'sample-disclosure');
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint { return null; }
  });
  $director->queueScriptTutorial('guide.sample');
  $director->updateInStableField(true);

  expect($director->getActiveTutorial())->toBeNull()
    ->and($director->getSkippableTutorial()->id)->toBe('guide.sample')
    ->and($director->skipTutorial('unrelated'))->toBeFalse()
    ->and($director->skipTutorial('guide.sample'))->toBeTrue()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::SKIPPED)
    ->and($director->getPendingFollowOns()[0]->onFinish)->toBe('sample-disclosure');
});


it('starts contextual lessons only at their own live entry and preserves interrupted progress as pending', function () {
  $state = new GameState();
  $catalog = new TutorialCatalog([
    [
      'id' => 'guide.library', 'title' => 'Library', 'category' => 'Contracts',
      'start' => 'automatic', 'startContext' => 'summons-library', 'replay' => 'practice',
      'trigger' => [['type' => 'switch', 'name' => 'library-ready']],
      'steps' => [[
        'target' => ['screen' => 'summons', 'view' => 'list', 'id' => 'example'],
        'action' => 'confirm', 'until' => ['outcome' => 'summon.opened'],
      ]],
    ],
    [
      'id' => 'guide.battle', 'title' => 'Battle', 'category' => 'Contracts',
      'start' => 'automatic', 'startContext' => 'battle-command', 'replay' => 'read',
      'steps' => [[
        'target' => ['screen' => 'battle', 'view' => 'command', 'id' => 'summon'],
        'action' => 'confirm', 'say' => 'Choose Summon.', 'until' => ['outcome' => 'summon.executed'],
      ]],
    ],
  ]);
  $director = new TutorialDirector($catalog, $state);
  $director->updateInStableField(true);
  $state->setSwitch('library-ready');
  $director->updateInStableField(true);
  expect($director->getActiveTutorial())->toBeNull()
    ->and($state->getTutorialPhase('guide.library'))->toBe(TutorialPhase::PENDING)
    ->and($state->getTutorialPhase('guide.battle'))->toBeNull();

  $director->enterContext(TutorialStartContext::BATTLE_COMMAND, false);
  expect($director->getActiveTutorial())->toBeNull()
    ->and($state->getTutorialPhase('guide.battle'))->toBe(TutorialPhase::PENDING);
  $director = new TutorialDirector($catalog, GameState::fromArray($state->toArray()));
  $director->updateInStableField(true);
  expect($director->getActiveTutorial())->toBeNull();

  $director->enterContext(TutorialStartContext::BATTLE_COMMAND, true);
  expect($director->getActiveTutorial()?->id)->toBe('guide.battle')
    ->and($director->getSkippableTutorial())->toBeNull();
  $director->leaveContext(TutorialStartContext::BATTLE_COMMAND);
  $director->enterContext(TutorialStartContext::SUMMONS_LIBRARY, true);
  expect($director->getActiveTutorial()?->id)->toBe('guide.library');
  $director->reportOutcome('summons', 'list', 'summon.opened', 'example');
  $director->leaveContext(TutorialStartContext::SUMMONS_LIBRARY);
  $director->updateInStableField(true);
  expect($director->getActiveTutorial())->toBeNull();

  $director->enterContext(TutorialStartContext::BATTLE_COMMAND, true);
  expect($director->getActiveTutorial()?->id)->toBe('guide.battle');
  $director->reportOutcome('battle', 'command', 'summon.executed');
  $director->enterContext(TutorialStartContext::BATTLE_COMMAND, true);
  expect($director->getActiveTutorial())->toBeNull();
});

it('queues a voluntary Guard lesson from the field and keeps it pending until its command is usable', function () {
  $state = new GameState();
  $director = new TutorialDirector(new TutorialCatalog([[
    'id' => 'guide.guard', 'title' => 'Guard', 'category' => 'Combat',
    'start' => 'script', 'startContext' => 'battle-command', 'replay' => 'read',
    'steps' => [[
      'target' => ['screen' => 'battle', 'view' => 'command', 'id' => 'guard'],
      'action' => 'confirm', 'say' => 'Choose Guard.', 'until' => ['outcome' => 'guard.executed'],
    ]],
  ]]), $state);
  $director->enterContext(TutorialStartContext::BATTLE_COMMAND, true);
  expect($state->getTutorialPhase('guide.guard'))->toBeNull();
  $director->queueScriptTutorial('guide.guard');
  $director->updateInStableField(false);
  expect($director->getActiveTutorial())->toBeNull()->and($director->getSkippableTutorial()?->id)->toBe('guide.guard');
  $director->enterContext(TutorialStartContext::BATTLE_COMMAND, false, canPresent: static fn() => false);
  expect($director->getActiveTutorial())->toBeNull();
  $director->enterContext(TutorialStartContext::BATTLE_COMMAND, false, canPresent: static fn() => true);
  $director->reportOutcome('battle', 'command', 'summon.executed');
  expect($director->getActiveTutorial()?->id)->toBe('guide.guard');
  $director->reportOutcome('battle', 'command', 'guard.executed');
  $director->queueScriptTutorial('guide.guard');
  expect($director->getActiveTutorial())->toBeNull()
    ->and($state->getTutorialPhase('guide.guard'))->toBe(TutorialPhase::COMPLETED);
});
