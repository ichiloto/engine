<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Battle\BattleTargetPolicy;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Formation\BattleFormation;
use Ichiloto\Engine\Battle\Formation\BattlePosition;
use Ichiloto\Engine\Battle\Formation\BattleRow;
use Ichiloto\Engine\Battle\Formation\BattleTargeting;
use Ichiloto\Engine\Battle\Formation\BattleTargetShape;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\createQueuedBattleFixture;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

it('resolves lines and front rows using live eligible instances without drawing random recipients', function () {
  $actor = new Character('Actor', 0, new Stats());
  $front = new Character('Twin', 0, new Stats());
  $back = new Character('Twin', 0, new Stats());
  $other = new Character('Other', 0, new Stats());
  $targets = [$front, $back, $other];
  $formation = new BattleFormation([$actor], $targets, [], [new BattlePosition(BattleRow::FRONT, 0), new BattlePosition(BattleRow::BACK, 0), new BattlePosition(BattleRow::FRONT, 1)]);
  $random = new class implements CombatRandomSource {
    public int $calls = 0;
    public function nextInt(int $minimum, int $maximum): int { ++$this->calls; return $minimum; }
  };
  $line = new BattleTargeting(BattleTargetShape::LINE, true);
  expect(BattleTargetPolicy::resolveTargets(new ItemScope(), $actor, [$actor], $targets, [$back], $random, $formation, $line))->toBe([$front, $back])
    ->and($random->calls)->toBe(0);
  $front->stats->currentHp = 0;
  expect(BattleTargetPolicy::resolveTargets(new ItemScope(), $actor, [$actor], $targets, [$back], $random, $formation, $line))->toBe([$back]);
  $row = new BattleTargeting(BattleTargetShape::FRONT_ROW);
  expect(BattleTargetPolicy::resolveTargets(new ItemScope(number: ItemScopeNumber::ALL), $actor, [$actor], $targets, formation: $formation, targeting: $row))->toBe([$other]);
  expect(fn() => BattleTargetPolicy::resolveTargets(new ItemScope(), $actor, [$actor], $targets, targeting: $line))->toThrow(InvalidArgumentException::class, 'formation');
});

it('keeps ordinary Cover behavior and permits authored cover piercing without changing the chosen target', function () {
  $actor = new Character('Actor', 0, new Stats());
  $weak = new Character('Weak', 0, new Stats(currentHp: 1));
  $coverer = new class('Coverer', 0, new Stats()) extends Character {
    public function isCoveringAllies(): bool { return true; }
  };
  $scope = new ItemScope();
  expect(BattleTargetPolicy::resolveTargets($scope, $actor, [$actor], [$weak, $coverer], [$weak]))->toBe([$coverer])
    ->and(BattleTargetPolicy::resolveTargets($scope, $actor, [$actor], [$weak, $coverer], [$weak], targeting: new BattleTargeting(ignoreCover: true)))->toBe([$weak]);
});

it('revalidates the queued line around its selected anchor in traditional and ATB combat', function (bool $activeTime) {
  $priorDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
  try {
    $seed = createTargetExecutionFixture($activeTime, false, $this->createStub(AudioManager::class));
    [, $seedContext, , $actor, , $targets] = $seed;
    $targets[] = clone $targets[0];
    foreach ($targets as $target) { $target->stats->currentHp = 50; }
    $troop = new Troop('Lines', $targets, battlePositions: [new BattlePosition(BattleRow::FRONT, 0), new BattlePosition(BattleRow::BACK, 0), new BattlePosition(BattleRow::BACK, 1)]);
    $fixture = createQueuedBattleFixture($activeTime, false, $this->createStub(AudioManager::class), $seedContext->party, $troop);
    [$engine, $context, $screen] = $fixture;
    $skill = new BasicSkill('Line', '', '', 3, 0, occasion: Occasion::BATTLE_SCREEN,
      invocation: new SkillInvocation(accuracy: 100), effects: [new HPRecoverSkillEffect('10', variance: 0)],
      battleTargeting: new BattleTargeting(BattleTargetShape::LINE));
    $action = new SkillBattleAction($skill);
    $option = new BattleCommandOption('Line', '', $action);
    new ReflectionProperty($screen->commandContextWindow, 'items')->setValue($screen->commandContextWindow, [$option]);
    new ReflectionProperty($screen->commandContextWindow, 'activeIndex')->setValue($screen->commandContextWindow, 0);
    $state = $activeTime ? new \Ichiloto\Engine\Battle\Engines\ActiveTime\States\ActiveTimeFlowState($engine)
      : new class($engine) extends PlayerActionState {
        protected function selectNextCharacter(\Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext $context, bool $resetToStart = false): void {}
      };
    new ReflectionProperty($state, 'activeCharacterIndex')->setValue($state, 0);
    new ReflectionProperty($state, 'activeTargetIndex')->setValue($state, 1);
    $preview = new ReflectionMethod($state, 'resolveSelectedTargets')->invoke($state, $context, $option);
    expect($preview)->toBe([$targets[0], $targets[1]]);
    new ReflectionProperty($state, 'selectionMode')->setValue($state, 'target');
    new ReflectionMethod($state, 'applyTargetingVisuals')->invoke($state, $context);
    expect(new ReflectionProperty($screen->fieldWindow, 'focusedTroopIndexes')->getValue($screen->fieldWindow))->toBe([0, 1]);

    $context->setTurns([new \Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn($actor)]);
    new ReflectionMethod($state, 'queueActionForActiveCharacter')->invoke($state, $context);
    $turn = $context->getTurns()[0];
    expect($turn->targetAnchor)->toBe($targets[1])->and($turn->targets)->toBe($preview);
    if (!$activeTime) { $engine->setState($engine->actionExecutionState); }
    $engine->battleConfig->formation->shufflePositions([$targets[0], $targets[2]], new class implements CombatRandomSource {
      public function nextInt(int $minimum, int $maximum): int { return $minimum; }
    });
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.0);
    $engine->state->update($context);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($turn->isCompleted)->toBeTrue()
      ->and(array_map(static fn($target) => $target->stats->currentHp, $targets))->toBe([50, 60, 60])
      ->and($actor->stats->currentMp)->toBe(47);
  } finally {
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $priorDelta);
  }
})->with([false, true]);

it('round trips battle geometry and rejects field usage and incompatible scope numbers', function () {
  $skill = new BasicSkill('Line', '', '', 0, 0, occasion: Occasion::BATTLE_SCREEN,
    battleTargeting: new BattleTargeting(BattleTargetShape::LINE, true));
  $data = SkillRecord::writeSkill($skill);
  expect(SkillRecord::readSkill($data)->battleTargeting->toArray())->toBe(['shape' => 'line', 'ignoreCover' => true]);
  expect(fn() => new BasicSkill('Invalid field', '', '', 0, 0, battleTargeting: new BattleTargeting(BattleTargetShape::LINE)))
    ->toThrow(InvalidArgumentException::class, 'battle-only')
    ->and(fn() => new BasicSkill('Invalid count', '', '', 0, 0, new ItemScope(number: ItemScopeNumber::ALL), occasion: Occasion::BATTLE_SCREEN,
      battleTargeting: new BattleTargeting(BattleTargetShape::LINE)))->toThrow(InvalidArgumentException::class, 'anchor');
});


it('restores ordinary historical skills and preserves geometry for basic, special and magic skills', function () {
  foreach ([BasicSkill::class, \Ichiloto\Engine\Entities\Skills\SpecialSkill::class, \Ichiloto\Engine\Entities\Skills\MagicSkill::class] as $type) {
    $skill = new $type('Row', '', '', 0, 0, new ItemScope(number: ItemScopeNumber::ALL), Occasion::BATTLE_SCREEN,
      battleTargeting: new BattleTargeting(BattleTargetShape::BACK_ROW));
    expect(SkillRecord::readSkill(SkillRecord::writeSkill($skill))->battleTargeting->shape)->toBe(BattleTargetShape::BACK_ROW)
      ->and(unserialize(serialize($skill))->battleTargeting->shape)->toBe(BattleTargetShape::BACK_ROW);
    Closure::bind(function (): void { unset($this->battleTargeting); }, $skill, \Ichiloto\Engine\Entities\Skills\Skill::class)();
    expect(unserialize(serialize($skill))->battleTargeting)->toBeNull();
  }
});

it('rejects malformed battle target metadata', function (mixed $data) {
  expect(fn() => BattleTargeting::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
  'string' => ['line'], 'unknown shape' => [['shape' => 'diagonal']], 'numeric shape' => [['shape' => 1]],
  'string boolean' => [['ignoreCover' => 'true']], 'unknown field' => [['width' => 2]],
]);

it('keeps counter responses restricted to one recipient when a skill selects a line', function () {
  $line = new BasicSkill('Line', '', '', 0, 0, occasion: Occasion::BATTLE_SCREEN,
    battleTargeting: new BattleTargeting(BattleTargetShape::LINE));
  $catalog = \Ichiloto\Engine\Entities\Skills\SkillCatalog::fromSkills(['line.php' => $line]);
  expect(fn() => new \Ichiloto\Engine\Battle\CounterAttackRule('Line')->resolveSkill($catalog))
    ->toThrow(InvalidArgumentException::class, 'one living opponent');
});


it('preserves authored geometry in an uncastable draft while scope and occasion await the author', function () {
  $skill = new BasicSkill('Draft', '', '', 0, 0);
  $data = SkillRecord::writeSkill($skill);
  unset($data['scope'], $data['occasion']);
  $data['unassigned'] = ['scope', 'occasion'];
  $data['battleTargeting'] = ['shape' => 'back_row'];
  $draft = \Ichiloto\Engine\Entities\Skills\SkillDraft::fromArray($data);
  expect($draft->data)->toBe($data)->and($draft->getScope())->toBeNull()
    ->and(fn() => SkillRecord::readSkill($data))->toThrow(InvalidArgumentException::class, 'draft');
});
