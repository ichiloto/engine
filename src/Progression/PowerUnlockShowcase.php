<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\UI\Modal\NoticeContent;
use InvalidArgumentException;

/** Reads current power definitions without changing the actor or the world. */
final class PowerUnlockShowcase
{
  /**
   * @param list<string> $abilityIds World abilities in presentation order.
   * @return list<NoticeContent>
   */
  public static function getNotices(WorldAbilityCatalog $abilities, array $abilityIds, GameState $state,
    ?Party $party = null, ?Character $actor = null, bool $includeCrisis = false, string $announcement = '',
    ?QuestLog $quests = null, ?SkillCatalog $skills = null): array
  {
    if (!array_is_list($abilityIds) || !array_all($abilityIds, static fn(mixed $id): bool => is_string($id) && trim($id) !== '')
      || count(array_unique($abilityIds)) !== count($abilityIds)) {
      throw new InvalidArgumentException('Select distinct world ability identities for a power showcase.');
    }
    $notices = [];
    foreach ($abilityIds as $id) {
      $ability = $abilities->getAbility($id);
      if (!$abilities->isUnlocked($id, $state, $party, $quests)) {
        throw new InvalidArgumentException('Cannot showcase locked world ability ' . $id . '.');
      }
      $notices[] = new NoticeContent($ability->name, $ability->description, iconRole: $ability->iconRole);
    }
    if ($includeCrisis) {
      $skill = $actor?->crisisAbility?->getUnlockedSkill($actor, $state, $party, $skills)
        ?? throw new InvalidArgumentException('The showcase actor has no unlocked crisis technique.');
      $iconRole = $actor->crisisAbility->iconRole;
      $notices[] = new NoticeContent($skill->name, $skill->description,
        actorId: $iconRole === null ? $actor->actorId : null, iconRole: $iconRole);
    }
    if ($notices === []) { throw new InvalidArgumentException('A power showcase requires an unlocked power.'); }
    if (trim($announcement) !== '') {
      array_unshift($notices, new NoticeContent('', $announcement, actorId: $actor?->actorId));
    }
    return $notices;
  }
}
