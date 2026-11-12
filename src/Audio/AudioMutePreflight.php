<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio;

use Ichiloto\Engine\Audio\Enumerations\AudioBus;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use RuntimeException;

/** A read-only guard for automated game launches that must stay silent: every bus switch is off. */
final class AudioMutePreflight
{
  public static function assertMuted(ProjectConfig $effectiveConfig): void
  {
    foreach (AudioBus::cases() as $bus) {
      $path = $bus->getEnabledConfigPath();
      if (boolval($effectiveConfig->get($path, $bus->isEnabledByDefault()))) {
        throw new RuntimeException("Automated playtest requires $path to be off.");
      }
    }
  }
}
