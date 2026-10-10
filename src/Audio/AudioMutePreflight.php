<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio;

use Ichiloto\Engine\Util\Config\ProjectConfig;
use RuntimeException;

/** A read-only guard for automated game launches that must stay silent. */
final class AudioMutePreflight
{
  public static function assertMuted(ProjectConfig $effectiveConfig): void
  {
    foreach (['audio.music' => false, 'audio.sfx' => false, 'audio.voice' => true] as $path => $default) {
      if (boolval($effectiveConfig->get($path, $default))) {
        throw new RuntimeException("Automated playtest requires $path to be off.");
      }
    }
  }
}
