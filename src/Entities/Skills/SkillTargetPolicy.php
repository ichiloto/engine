<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/**
 * Applies the shared alive/dead/any targeting vocabulary used by skills.
 */
final class SkillTargetPolicy
{
  /**
   * Checks whether a character satisfies the requested target status.
   */
  public static function matchesStatus(
    CharacterInterface $character,
    ItemScopeStatus $status,
  ): bool
  {
    return match ($status) {
      ItemScopeStatus::DEAD => $character->isKnockedOut,
      ItemScopeStatus::ANY => true,
      ItemScopeStatus::ALIVE => ! $character->isKnockedOut,
    };
  }

  /**
   * @param CharacterInterface[] $characters The candidate characters.
   * @return CharacterInterface[] Characters matching the requested status.
   */
  public static function filterByStatus(array $characters, ItemScopeStatus $status): array
  {
    return array_values(array_filter(
      $characters,
      static fn(mixed $character): bool => $character instanceof CharacterInterface
        && self::matchesStatus($character, $status),
    ));
  }
}
