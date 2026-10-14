<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;

/** Shared combat policy for live engines and simulations; presentation never selects counters. */
final class CounterAttackResolver
{
  public function __construct(
    private ?SkillCatalog $skills = null,
    private readonly ?CombatRandomSource $random = null,
  ) {}

  /** Validate owned references before a command can mutate combat state. */
  public function validateBattlers(array $battlers): void
  {
    foreach ($battlers as $battler) {
      if (!$battler instanceof CounterAttackProvider) { continue; }
      foreach ($battler->getCounterAttackRules() as $rule) {
        $rule->resolveSkill($this->skills ??= SkillCatalog::getProjectCatalog());
      }
    }
  }

  /**
   * Called only for an original command after its return, never for a response.
   * @param list<CharacterInterface> $targets Actual command recipients.
   * @param list<CharacterInterface> $party Active party only, excluding reserves.
   * @param list<CharacterInterface> $troop
   * @return list<CounterAttack>
   */
  public function resolveResponses(?CombatActionResult $result, CharacterInterface $attacker,
    array $targets, array $party, array $troop): array
  {
    if ($result === null || $result->actorId !== CombatResolver::identity($attacker)) { return []; }
    $responses = [];
    $seen = [];
    foreach ($targets as $recipient) {
      $id = CombatResolver::identity($recipient);
      if (isset($seen[$id]) || !$recipient instanceof CounterAttackProvider
        || !$this->canRespond($recipient, $attacker, $party, $troop)) { continue; }
      $seen[$id] = true;
      $landed = false;
      foreach ($result->targets as $outcome) {
        if ($outcome->targetId !== $id) { continue; }
        foreach ($outcome->hits as $hit) {
          if ($hit->hit && $hit->kind === ResolutionKind::PHYSICAL_DAMAGE) { $landed = true; break; }
        }
      }
      if (!$landed) { continue; }
      foreach ($recipient->getCounterAttackRules() as $rule) {
        $skill = $rule->resolveSkill($this->skills ??= SkillCatalog::getProjectCatalog());
        if ($recipient->stats->currentMp < $skill->cost) { continue; }
        $responses[] = new CounterAttack($recipient, $attacker,
          new SkillBattleAction($skill, random: $this->random), $rule);
        break;
      }
    }
    return $responses;
  }

  /** Recheck after earlier responses, which may remove a grant, block an actor or end battle. */
  public function canExecute(CounterAttack $response, array $party, array $troop): bool
  {
    $actor = $response->actor;
    return $actor instanceof CounterAttackProvider
      && $this->canRespond($actor, $response->target, $party, $troop)
      && in_array($response->rule, $actor->getCounterAttackRules(), true)
      && $actor->stats->currentMp >= $response->action->skill->cost;
  }

  private function canRespond(CharacterInterface $actor, CharacterInterface $target, array $party, array $troop): bool
  {
    return !$actor->isKnockedOut && !$target->isKnockedOut
      && $actor instanceof CounterAttackProvider && $actor->getActionBlockingState() === null
      && ((in_array($actor, $party, true) && in_array($target, $troop, true))
        || (in_array($actor, $troop, true) && in_array($target, $party, true)));
  }
}
