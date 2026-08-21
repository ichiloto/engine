<?php

namespace Ichiloto\Engine\Progress;

use InvalidArgumentException;

/**
 * An authored achievement definition.
 *
 * Definitions live in the project's `assets/Data/achievements.php` and are
 * pure data; unlock state lives in the {@see AchievementManager} and rides
 * save files.
 *
 * @package Ichiloto\Engine\Progress
 */
class Achievement
{
  /**
   * @param string $id The achievement id.
   * @param string $name The display name.
   * @param string $description What the player did (or must do) to earn it.
   * @param string $icon The icon shown in the list.
   * @param bool $isSecret True to hide the name and description until unlocked.
   * @param int $points An optional score value.
   * @param array<int, array<string, mixed>> $conditions World-state conditions that unlock it automatically (same shapes as event-trigger conditions).
   */
  public function __construct(
    protected(set) string $id,
    protected(set) string $name,
    protected(set) string $description = '',
    protected(set) string $icon = '🏆',
    protected(set) bool $isSecret = false,
    protected(set) int $points = 0,
    protected(set) array $conditions = [],
  )
  {
  }

  /**
   * Hydrates an achievement from its data-file entry.
   *
   * @param array<string, mixed> $data The achievement entry.
   * @return self The achievement.
   */
  public static function fromArray(array $data): self
  {
    $id = trim(strval($data['id'] ?? ''));

    if ($id === '') {
      throw new InvalidArgumentException('Achievements require an id.');
    }

    return new self(
      $id,
      strval($data['name'] ?? $id),
      strval($data['description'] ?? ''),
      strval($data['icon'] ?? '🏆'),
      (bool) ($data['secret'] ?? $data['isSecret'] ?? false),
      intval($data['points'] ?? 0),
      array_values(array_filter((array) ($data['conditions'] ?? []), 'is_array')),
    );
  }
}
