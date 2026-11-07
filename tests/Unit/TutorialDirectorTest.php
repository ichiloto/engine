<?php

use Ichiloto\Engine\Core\GameState;
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

function makeTutorialDirector(GameState $state, bool $automatic = false, ?string $onFinish = null): TutorialDirector
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
  ]]), $state);
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
  expect($state->getTutorialPhase('guide.sample'))->toBeNull();

  $state->setSwitch('available', false);
  $director->updateInStableField(true);
  $state->setSwitch('available');
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint { return null; }
  });
  $director->updateInStableField(true);
  expect($director->getActiveTutorial())->toBeNull()
    ->and($state->getTutorialPhase('guide.sample'))->toBe(TutorialPhase::PENDING);
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
