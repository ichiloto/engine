<?php

namespace Ichiloto\Engine\Battle\Entry;

use Ichiloto\Engine\Battle\BattleClassification;

/** A validated, project-authored battle-entry transaction. */
final readonly class BattleEntryRule
{
  /**
   * @param BattleEntryActorPredicate[] $actors
   * @param BattleEntryStatStageEffect[] $effects
   * @param array<int, array<string, mixed>> $conditions
   * @param array<int, array<string, mixed>> $writes
   */
  public function __construct(
    public string $id,
    public int $priority,
    public int $declarationOrder,
    public BattleClassification $classification,
    public array $actors,
    public array $conditions,
    public array $effects,
    public array $writes,
    public string $source,
  )
  {
  }
}
