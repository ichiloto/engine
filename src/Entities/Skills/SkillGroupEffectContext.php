<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Battle\Formation\BattleFormation;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/** Per-command group phase with the completed per-recipient hit evidence. */
final class SkillGroupEffectContext
{
  /** @var array<string, list<array{type: string, id: string, applied: bool}>> */
  private array $outcomes = [];

  /** @param array<int, list<SkillEffectContext>> $recipientContexts */
  public function __construct(
    public readonly CharacterInterface $actor,
    public readonly array $recipientContexts,
    public readonly CombatRandomSource $random,
    public readonly ?BattleFormation $formation,
  ) {}

  /** @return list<CharacterInterface> */
  public function getTargets(): array
  {
    return array_map(static fn(array $contexts): CharacterInterface => $contexts[0]->target, array_values($this->recipientContexts));
  }

  /** Surviving recipients with a successful prior hit, or a standalone group effect. @return list<CharacterInterface> */
  public function getEligibleTargets(): array
  {
    return array_values(array_filter($this->getTargets(), fn(CharacterInterface $target): bool => $target->isFighting
      && array_any($this->recipientContexts[spl_object_id($target)], static fn(SkillEffectContext $context): bool =>
        $context->results() === [] || $context->hadSuccessfulResolution())));
  }

  public function recordOutcome(CharacterInterface $target, string $id, bool $applied): void
  {
    $this->outcomes[CombatResolver::identity($target)][] = ['type' => 'formation', 'id' => $id, 'applied' => $applied];
  }

  /** @return array<string, list<array{type: string, id: string, applied: bool}>> */
  public function getOutcomes(): array { return $this->outcomes; }
}
