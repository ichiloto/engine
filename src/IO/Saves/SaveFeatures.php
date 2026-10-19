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

  private static function getOption(string $name, bool $default): bool
  {
    $value = ConfigStore::has(ProjectConfig::class)
      ? ConfigStore::get(ProjectConfig::class)->get('save.' . $name, $default) : $default;
    if (!is_bool($value)) { throw new InvalidArgumentException('Project save.' . $name . ' must be boolean.'); }
    return $value;
  }
}
