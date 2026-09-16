<?php

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole;
use Ichiloto\Engine\Battle\Resolution\CombatHitResult;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\ElementalOutcome;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Enumerations\Color;

function feedbackFormatterHit(
  ElementalOutcome $outcome = ElementalOutcome::NORMAL,
  int $lost = 0,
  int $restored = 0,
  bool $critical = false,
  bool $hit = true,
): CombatHitResult {
  return new CombatHitResult(
    actionId: 'attack', executionId: 'attack:1', actorId: 'Actor', targetId: 'Target',
    hit: $hit, missReason: $hit ? '' : 'evasion', rawMagnitude: 100,
    kind: ResolutionKind::PHYSICAL_DAMAGE, offensiveInput: 100, defensiveInput: 0,
    mitigationAmount: 0, mitigationRate: 0.0, criticalEligible: true, criticalRoll: 1,
    critical: $critical, criticalMultiplier: 1.5, guardApplied: false, element: 'Fire',
    elementalOutcome: $outcome, elementalMultiplier: 1.0, preEffectHp: 100,
    requestedHpChange: $restored - $lost, actualHpLost: $lost, actualHpRestored: $restored,
    overkill: 0, postEffectHp: 100 - $lost + $restored, hitRoll: 1, hitChance: 100,
  );
}

function feedbackFormatterLines(Character $target, int $hp, int $mp, ?CombatTargetResult $result = null): array
{
  $state = new ReflectionClass(ActionExecutionState::class)->newInstanceWithoutConstructor();
  return new ReflectionMethod(ActionExecutionState::class, 'buildStatChangePopupLines')->invoke($state, $target, $hp, $mp, $result);
}

it('assigns distinct elemental roles at the formatter for typed and legacy outcomes', function ($outcome, $text, $color, $role, $typed) {
  $target = new Character('Target', 1, new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20));
  $target->lastElementReaction = $typed ? 'ABSORB' : $text;
  $result = $typed ? new CombatTargetResult('Target', [feedbackFormatterHit($outcome)]) : null;

  expect(feedbackFormatterLines($target, 100, 20, $result))->toBe([
    ['text' => $text, 'color' => $color, 'role' => $role],
  ])->and($target->lastElementReaction)->toBeNull();
})->with([
  [ElementalOutcome::WEAK, 'WEAK!', Color::LIGHT_RED, BattleFeedbackRole::WEAK],
  [ElementalOutcome::RESIST, 'RESIST', Color::LIGHT_CYAN, BattleFeedbackRole::RESIST],
  [ElementalOutcome::NULL, 'NULL', Color::LIGHT_CYAN, BattleFeedbackRole::NULL],
  [ElementalOutcome::ABSORB, 'ABSORB', Color::LIGHT_GREEN, BattleFeedbackRole::ABSORB],
])->with([true, false]);

it('preserves elemental priority instead of choosing the first hit reaction', function ($outcomes, $expectedRole) {
  $target = new Character('Target', 1, new Stats(currentHp: 100, totalHp: 100));
  $result = new CombatTargetResult('Target', array_map(fn($outcome) => feedbackFormatterHit($outcome), $outcomes));
  expect(feedbackFormatterLines($target, 100, $target->stats->currentMp, $result)[0]['role'])->toBe($expectedRole);
})->with([
  [[ElementalOutcome::RESIST, ElementalOutcome::WEAK, ElementalOutcome::NULL, ElementalOutcome::ABSORB], BattleFeedbackRole::ABSORB],
  [[ElementalOutcome::RESIST, ElementalOutcome::WEAK, ElementalOutcome::NULL], BattleFeedbackRole::NULL],
  [[ElementalOutcome::RESIST, ElementalOutcome::WEAK], BattleFeedbackRole::WEAK],
]);

it('preserves exact mixed result aggregation ordering terminal literals colors and flag resets', function () {
  $target = new Character('Target', 1, new Stats(currentHp: 0, totalHp: 100, currentMp: 8, totalMp: 20));
  $target->lastHitWasCritical = true;
  $target->lastElementReaction = 'WEAK!';
  $result = new CombatTargetResult('Target', [
    feedbackFormatterHit(ElementalOutcome::WEAK, lost: 20),
    feedbackFormatterHit(ElementalOutcome::ABSORB, lost: 10, restored: 7, critical: true),
    feedbackFormatterHit(restored: 3),
  ]);
  expect(feedbackFormatterLines($target, 90, 12, $result))->toBe([
    ['text' => 'ABSORB', 'color' => Color::LIGHT_GREEN, 'role' => BattleFeedbackRole::ABSORB],
    ['text' => 'CRITICAL', 'color' => Color::YELLOW, 'role' => BattleFeedbackRole::CRITICAL],
    ['text' => '30', 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::DAMAGE],
    ['text' => '+10', 'color' => Color::LIGHT_GREEN, 'role' => BattleFeedbackRole::HEAL],
    ['text' => '-4 MP', 'color' => Color::LIGHT_CYAN, 'role' => BattleFeedbackRole::MP_LOSS],
    ['text' => 'KO', 'color' => Color::YELLOW, 'role' => BattleFeedbackRole::KO],
  ])->and($target->lastHitWasCritical)->toBeFalse()->and($target->lastElementReaction)->toBeNull()
    ->and($target->stats->currentHp)->toBe(0)->and($target->stats->currentMp)->toBe(8);
});

it('preserves legacy restoration and MP gain signs with distinct roles', function () {
  $target = new Character('Target', 1, new Stats(currentHp: 80, totalHp: 100, currentMp: 15, totalMp: 20));
  $target->lastHitWasCritical = true;
  $target->lastElementReaction = 'RESIST';
  expect(feedbackFormatterLines($target, 60, 10))->toBe([
    ['text' => 'RESIST', 'color' => Color::LIGHT_CYAN, 'role' => BattleFeedbackRole::RESIST],
    ['text' => 'CRITICAL', 'color' => Color::YELLOW, 'role' => BattleFeedbackRole::CRITICAL],
    ['text' => '+20', 'color' => Color::LIGHT_GREEN, 'role' => BattleFeedbackRole::HEAL],
    ['text' => '+5 MP', 'color' => Color::LIGHT_CYAN, 'role' => BattleFeedbackRole::MP_GAIN],
  ])->and($target->lastHitWasCritical)->toBeFalse()->and($target->lastElementReaction)->toBeNull();
});

it('keeps typed zero typed miss and legacy miss distinct without resetting unused flags', function ($kind) {
  $target = new Character('Target', 1, new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20));
  $typed = $kind !== 'legacy';
  $target->lastHitWasCritical = $typed;
  $target->lastElementReaction = $typed ? 'WEAK!' : null;
  $result = match ($kind) {
    'zero' => new CombatTargetResult('Target', [feedbackFormatterHit()]),
    'miss' => new CombatTargetResult('Target', [feedbackFormatterHit(), feedbackFormatterHit(hit: false)]),
    'empty' => new CombatTargetResult('Target', []),
    default => null,
  };
  $expected = match ($kind) {
    'zero' => [['text' => '0', 'color' => Color::WHITE, 'role' => BattleFeedbackRole::ZERO]],
    'empty' => [],
    default => [['text' => 'MISS', 'color' => Color::WHITE, 'role' => BattleFeedbackRole::MISS]],
  };
  expect(feedbackFormatterLines($target, 100, 20, $result))->toBe($expected)
    ->and($target->lastHitWasCritical)->toBe($typed)
    ->and($target->lastElementReaction)->toBe($typed ? 'WEAK!' : null);
})->with(['zero', 'miss', 'empty', 'legacy']);

it('preserves unknown legacy reaction text without inventing a semantic role', function () {
  $target = new Character('Target', 1, new Stats(currentHp: 100, totalHp: 100));
  $target->lastElementReaction = 'CUSTOM REACTION';
  expect(feedbackFormatterLines($target, 100, $target->stats->currentMp))->toBe([
    ['text' => 'CUSTOM REACTION', 'color' => Color::LIGHT_CYAN],
  ])->and($target->lastElementReaction)->toBeNull();
});
