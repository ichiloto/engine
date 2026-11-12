<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO\Saves;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use InvalidArgumentException;

/** Developer-owned capabilities, never campaign or player settings. */
final class SaveFeatures
{
  public static function isAutosaveEnabled(): bool { return self::getOption('autosave', false); }
  public static function isQuickSaveEnabled(): bool { return self::getOption('quicksave', true); }

  /**
   * The event script a save point runs when the player arrives on it
   * (`save.point_script`), or null for the Engine's own save point notice.
   */
  public static function getSavePointScript(): ?string
  {
    $value = ConfigStore::has(ProjectConfig::class)
      ? ConfigStore::get(ProjectConfig::class)->get('save.point_script') : null;
    if ($value === null || $value === '') { return null; }
    if (!is_string($value) || preg_match('/^[a-zA-Z0-9._-]+$/', $value) !== 1) {
      throw new InvalidArgumentException('Project save.point_script must name an event script by its stable id.');
    }
    return $value;
  }

  private static function getOption(string $name, bool $default): bool
  {
    $value = ConfigStore::has(ProjectConfig::class)
      ? ConfigStore::get(ProjectConfig::class)->get('save.' . $name, $default) : $default;
    if (!is_bool($value)) { throw new InvalidArgumentException('Project save.' . $name . ' must be boolean.'); }
    return $value;
  }
}
