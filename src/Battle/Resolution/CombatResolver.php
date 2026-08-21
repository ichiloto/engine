<?php

namespace Ichiloto\Engine\Battle\Resolution;

use Ichiloto\Engine\Battle\BattlerBattleView;
use Ichiloto\Engine\Entities\Character;

/** The one mutation and arithmetic boundary for resolved HP effects. */
final class CombatResolver
{
  public function resolve(
    CombatResolutionRequest $request,
    ?CombatRandomSource $random = null,
  ): CombatHitResult
  {
    $random ??= new NativeCombatRandomSource();
    $actor = new BattlerBattleView($request->actor);
    $target = new BattlerBattleView($request->target);
    $preHp = $request->target->stats->currentHp;
    $guaranteed = $request->baseAccuracy === null;
    $equipmentAccuracy = $request->actor instanceof Character ? $request->actor->getEquipmentAccuracyModifier() : 0;
    $equipmentCritical = $request->actor instanceof Character ? $request->actor->getEquipmentCriticalModifier() : 0;
    $hitChance = $guaranteed ? 100 : intval(clamp(
      intval($request->baseAccuracy) + $actor->stats->grace + $equipmentAccuracy - $target->stats->evasion,
      CombatCalibration::HIT_CHANCE_FLOOR,
      CombatCalibration::HIT_CHANCE_CEILING,
    ));
    $hitRoll = $guaranteed ? null : ($request->hitRollOverride ?? $random->nextInt(1, 100));
    $hit = $guaranteed || $hitRoll <= $hitChance;
    $offensive = match ($request->kind) {
      ResolutionKind::PHYSICAL_DAMAGE => $actor->stats->attack,
      ResolutionKind::MAGICAL_DAMAGE => $actor->stats->magicAttack,
      default => $request->rawMagnitude,
    };
    $defensive = match ($request->kind) {
      ResolutionKind::PHYSICAL_DAMAGE => max(0, $target->stats->defence),
      ResolutionKind::MAGICAL_DAMAGE => max(0, $target->stats->magicDefence),
      default => 0,
    };

    if (! $hit) {
      return new CombatHitResult(
        $request->actionId,
        $request->executionId,
        self::identity($request->actor),
        self::identity($request->target),
        false,
        'accuracyRoll',
        $request->rawMagnitude,
        $request->kind,
        $offensive,
        $defensive,
        0,
        0.0,
        $request->criticalEligible,
        null,
        false,
        $request->criticalMultiplier,
        false,
        $request->element,
        ElementalOutcome::NORMAL,
        1.0,
        $preHp,
        0,
        0,
        0,
        0,
        $preHp,
        $hitRoll,
        $hitChance,
      );
    }

    $mitigationRate = $request->kind === ResolutionKind::PHYSICAL_DAMAGE
      || $request->kind === ResolutionKind::MAGICAL_DAMAGE
      ? min(CombatCalibration::MAX_ORDINARY_MITIGATION, $defensive / ($defensive + CombatCalibration::DEFENCE_CONSTANT))
      : 0.0;
    $afterMitigation = $request->rawMagnitude * (1.0 - $mitigationRate);
    $criticalChance = intval(clamp(
      $request->baseCriticalChance + intdiv($actor->stats->grace, CombatCalibration::GRACE_CRITICAL_DIVISOR) + $equipmentCritical,
      CombatCalibration::CRITICAL_CHANCE_FLOOR,
      CombatCalibration::CRITICAL_CHANCE_CEILING,
    ));
    $criticalRoll = $request->criticalEligible && ! $request->guaranteedCritical
      ? ($request->criticalRollOverride ?? $random->nextInt(1, 100))
      : null;
    $critical = $request->criticalEligible
      && ($request->guaranteedCritical || $criticalRoll <= $criticalChance);
    $afterCritical = $critical ? $afterMitigation * $request->criticalMultiplier : $afterMitigation;
    $guardApplied = $request->guardEligible && $request->kind->isDamage() && ($request->target->isGuarding ?? false);
    $afterGuard = $guardApplied ? $afterCritical * CombatCalibration::GUARD_FACTOR : $afterCritical;
    $affinity = $request->kind->isDamage()
      ? ElementalAffinityResolver::forTarget($request->target, $request->element)
      : ['outcome' => ElementalOutcome::NORMAL, 'multiplier' => 1.0];
    $resolved = $afterGuard * $affinity['multiplier'];
    $roundedMagnitude = intval(round(abs($resolved), 0, PHP_ROUND_HALF_UP));

    if ($request->kind->isDamage() && $affinity['outcome'] !== ElementalOutcome::NULL
      && $affinity['outcome'] !== ElementalOutcome::ABSORB) {
      $roundedMagnitude = max($request->minimumDamage, $roundedMagnitude);
    }

    $requestedChange = match (true) {
      $affinity['outcome'] === ElementalOutcome::ABSORB => $roundedMagnitude,
      $request->kind === ResolutionKind::HEALING => $roundedMagnitude,
      default => -$roundedMagnitude,
    };
    $actualLost = 0;
    $actualRestored = 0;
    $overkill = 0;

    if ($requestedChange < 0) {
      $requestedDamage = abs($requestedChange);
      $actualLost = min(max(0, $preHp), $requestedDamage);
      $overkill = max(0, $requestedDamage - max(0, $preHp));
      $request->target->stats->currentHp = max(0, $preHp - $requestedDamage);
    } elseif ($requestedChange > 0) {
      $headroom = max(0, $request->target->stats->totalHp - $preHp);
      $actualRestored = min($headroom, $requestedChange);
      $request->target->stats->currentHp = min($request->target->stats->totalHp, $preHp + $requestedChange);
    }

    $mitigationAmount = intval(round($request->rawMagnitude - $afterMitigation, 0, PHP_ROUND_HALF_UP));

    if ($critical && property_exists($request->target, 'lastHitWasCritical')) {
      $request->target->lastHitWasCritical = true;
    }

    if ($affinity['outcome'] !== ElementalOutcome::NORMAL && property_exists($request->target, 'lastElementReaction')) {
      $request->target->lastElementReaction = match ($affinity['outcome']) {
        ElementalOutcome::WEAK => 'WEAK!',
        ElementalOutcome::RESIST => 'RESIST',
        ElementalOutcome::NULL => 'NULL',
        ElementalOutcome::ABSORB => 'ABSORB',
        default => null,
      };
    }

    return new CombatHitResult(
      $request->actionId,
      $request->executionId,
      self::identity($request->actor),
      self::identity($request->target),
      true,
      '',
      $request->rawMagnitude,
      $request->kind,
      $offensive,
      $defensive,
      $mitigationAmount,
      $mitigationRate,
      $request->criticalEligible,
      $criticalRoll,
      $critical,
      $request->criticalMultiplier,
      $guardApplied,
      $request->element,
      $affinity['outcome'],
      $affinity['multiplier'],
      $preHp,
      $requestedChange,
      $actualLost,
      $actualRestored,
      $overkill,
      $request->target->stats->currentHp,
      $hitRoll,
      $hitChance,
    );
  }

  public static function identity(object $battler): string
  {
    $name = property_exists($battler, 'name') ? trim(strval($battler->name)) : '';

    return sprintf(
      '%s#%d',
      $name !== '' ? $name : $battler::class,
      spl_object_id($battler),
    );
  }
}
