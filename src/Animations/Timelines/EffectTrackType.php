<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

/** The shared authoring and compilation vocabulary; editors retain renderer-specific workflows. */
enum EffectTrackType: string
{
  case IMAGE = 'image';
  case GLYPH = 'glyph';
  case TEXT = 'text';
  case FLASH = 'flash';
  case SHAKE = 'shake';
  case TONE = 'tone';
  case TRAIL = 'trail';
  case TETHER = 'tether';
  case FIELD_MOTION = 'field-motion';
  case BATTLER_MOTION = 'battler-motion';

  public function requiresGraphicalPresentation(): bool
  {
    return in_array($this, [self::TRAIL, self::TETHER, self::FIELD_MOTION, self::BATTLER_MOTION], true);
  }

  /** @return list<string> Existing profiles keep their exact admitted track set. */
  public static function getSupportedValues(bool $forBattle = false, bool $forSummon = false): array
  {
    return array_values(array_map(static fn(self $type): string => $type->value,
      array_filter(self::cases(), static fn(self $type): bool => match ($type) {
        self::IMAGE, self::GLYPH, self::TEXT => true,
        self::FLASH, self::SHAKE, self::TONE => $forBattle,
        default => $forBattle && !$forSummon,
      })));
  }
}
