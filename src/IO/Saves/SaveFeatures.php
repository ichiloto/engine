<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO\Saves;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;

/** Developer-owned capabilities, never campaign or player settings. */
final class SaveFeatures
{
  public static function isAutosaveEnabled(?Game $game = null): bool { return self::getOption($game, 'autosave', false); }
  public static function isQuickSaveEnabled(?Game $game = null): bool { return self::getOption($game, 'quicksave', true); }

  public static function getManualSlotCount(Game $game, int $default): int
  {
    $configuration = self::getConfiguration($game);
    $count = $configuration === null ? $default : $configuration->get('saves.manual_slots', $default);
    if (!is_int($count) || $count < 1) {
      throw new InvalidArgumentException('Project saves.manual_slots must be a positive integer.');
    }
    return $count;
  }

  /**
   * The event script a save point runs when the player arrives on it
   * (`save.point_script`), or null for the Engine's own save point notice.
   */
  public static function getSavePointScript(?Game $game = null): ?string
  {
    $value = self::getConfiguration($game)?->get('save.point_script');
    if ($value === null || $value === '') { return null; }
    if (!is_string($value) || preg_match('/^[a-zA-Z0-9._-]+$/', $value) !== 1) {
      throw new InvalidArgumentException('Project save.point_script must name an event script by its stable id.');
    }
    return $value;
  }

  /** A Game boot owns its configuration. Unbootstrapped hosts use their active project scope. */
  private static function getConfiguration(?Game $game): ?ConfigInterface
  {
    return $game?->getProjectConfiguration() ?? (ConfigStore::has(ProjectConfig::class)
      ? ConfigStore::get(ProjectConfig::class) : null);
  }

  private static function getOption(?Game $game, string $name, bool $default): bool
  {
    $configuration = self::getConfiguration($game);
    $value = $configuration === null ? $default : $configuration->get('save.' . $name, $default);
    if (!is_bool($value)) { throw new InvalidArgumentException('Project save.' . $name . ' must be boolean.'); }
    return $value;
  }
}
