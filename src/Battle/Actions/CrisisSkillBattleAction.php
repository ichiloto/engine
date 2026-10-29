<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\CrisisAbility;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\Skill;

/** A queued crisis technique rechecks current eligibility before presentation and resource expenditure. */
final class CrisisSkillBattleAction extends SkillBattleAction
{
  public function __construct(Skill $skill, private readonly CrisisAbility $ability,
    private readonly string $actorId, private readonly ?GameState $gameState = null,
    private readonly ?Party $party = null)
  {
    parent::__construct($skill);
  }

  public function getExecutionRefusal(CharacterInterface $actor): ?string
  {
    $refusal = parent::getExecutionRefusal($actor);
    if ($refusal !== null) { return $refusal; }
    if (!$actor instanceof Character || $actor->actorId !== $this->actorId
      || $actor->crisisAbility !== $this->ability
      || $this->ability->getAvailableSkill($actor, $this->gameState, $this->party)?->name !== $this->skill->name) {
      return sprintf('%s is unavailable.', $this->name);
    }
    return null;
  }
}
