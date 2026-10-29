<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDrainSkillEffect;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\States\State;
use InvalidArgumentException;

/** Battle-owned reaction leases. No actor save or presentation stores the selected foe. */
final class BattleReactions
{
  public private(set) ?CharacterInterface $turnOwner = null;
  /** @var list<PreparedCounter> */
  private array $prepared = [];
  /** @var list<CounterAttack> */
  private array $responses = [];

  public function __construct(private ?SkillCatalog $skills = null,
    private readonly ?\Ichiloto\Engine\Battle\Resolution\CombatRandomSource $random = null) {}

  public function beginTurn(CharacterInterface $actor): void
  {
    $this->turnOwner = $actor;
  }

  public function prepareCounter(CharacterInterface $actor, CharacterInterface $opponent,
    CounterAttackRule $rule, State $stance): bool
  {
    $this->validatePreparation($rule, $stance);
    if (!$actor->isFighting || !$opponent->isFighting || $actor === $opponent
      || !method_exists($actor, 'addState')) { return false; }
    foreach ($this->prepared as $index => $previous) {
      if ($previous->actor !== $actor) { continue; }
      $previous->releaseStance();
      unset($this->prepared[$index]);
    }
    // An unrelated grant with the same state identity belongs to its own owner.
    if ($actor->hasState($stance->id)) { return false; }
    $actor->addState($stance, givenByBearer: true, guaranteed: true);
    $instance = array_find($actor->states, static fn($instance): bool => $instance->state->id === $stance->id);
    if ($instance === null) { return false; }
    $this->prepared[] = new PreparedCounter($actor, $opponent, $rule, $instance);
    return true;
  }

  public function validatePreparation(CounterAttackRule $rule, State $stance): void
  {
    $rule->resolveSkill($this->skills ??= SkillCatalog::getProjectCatalog());
    if ($stance->persistsAfterBattle) {
      throw new InvalidArgumentException('A prepared counter stance must clear after battle.');
    }
  }

  /** @param list<CharacterInterface> $targets @return list<CharacterInterface> */
  public function resolveTargets(BattleAction $action, CharacterInterface $attacker, array $targets,
    array $party, array $troop): array
  {
    $counter = $this->findCounter($action, $attacker);
    if ($counter === null || !((in_array($attacker, $party, true) && in_array($counter->actor, $troop, true))
      || (in_array($attacker, $troop, true) && in_array($counter->actor, $party, true)))) { return $targets; }
    return [$counter->actor];
  }

  /** Called after the action pays its cost, once for the whole invocation. */
  public function intercept(BattleAction $action, CharacterInterface $attacker, array $targets,
    string $actionId, string $executionId, CombatResolver $resolver): ?CombatActionResult
  {
    $counter = $this->findCounter($action, $attacker);
    if ($counter === null || $targets !== [$counter->actor]) { return null; }
    $skill = $counter->rule->resolveSkill($this->skills ??= SkillCatalog::getProjectCatalog());
    $counter->consume();
    $this->responses[] = new CounterAttack($counter->actor, $attacker,
      new SkillBattleAction($skill, random: $this->random), $counter->rule, $counter);
    $hit = $resolver->resolve(new CombatResolutionRequest($actionId, $executionId, $attacker,
      $counter->actor, 0, ResolutionKind::PHYSICAL_DAMAGE, forcedMissReason: 'decoy'));
    return new CombatActionResult($actionId, $executionId, CombatResolver::identity($attacker),
      [new CombatTargetResult(CombatResolver::identity($counter->actor), [$hit])]);
  }

  /** @return list<CounterAttack> */
  public function takeResponses(CharacterInterface $attacker): array
  {
    $responses = array_values(array_filter($this->responses,
      static fn(CounterAttack $response): bool => $response->target === $attacker));
    $this->responses = array_values(array_filter($this->responses,
      static fn(CounterAttack $response): bool => $response->target !== $attacker));
    return $responses;
  }

  public function clear(): void
  {
    foreach ($this->prepared as $counter) { $counter->releaseStance(); }
    $this->prepared = $this->responses = [];
    $this->turnOwner = null;
  }

  private function findCounter(BattleAction $action, CharacterInterface $attacker): ?PreparedCounter
  {
    $attacks = $action instanceof AttackAction || ($action instanceof SkillBattleAction
      && array_any($action->skill->effects, static fn($effect): bool => $effect instanceof HPDamageSkillEffect
        || $effect instanceof HPDrainSkillEffect || $effect instanceof MPDamageSkillEffect || $effect instanceof MPDrainSkillEffect));
    if (!$attacks) { return null; }
    return array_find($this->prepared, static fn(PreparedCounter $counter): bool =>
      $counter->opponent === $attacker && $counter->isActive());
  }
}
