<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Entities\Character;

/** The committed or rejected outcome of one field-skill request. */
final readonly class FieldSkillExecutionResult
{
  /**
   * @param Character[] $targets The targets resolved for the request.
   */
  public function __construct(
    public bool $succeeded,
    public array $targets = [],
    public ?FieldSkillFailureReason $failureReason = null,
    public ?CombatActionResult $actionResult = null,
  )
  {
  }
}
