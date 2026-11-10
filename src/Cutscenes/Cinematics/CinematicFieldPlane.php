<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use InvalidArgumentException;

/**
 * Where a cinematic's graphical field presentation (a `field_animation`
 * effect or a staged actor) draws.
 *
 * FIELD draws among the field world, under cinematic covers such as a
 * stage dim. ABOVE_COVER lifts the image onto the cinematic stage plane,
 * over the whole field, the active stage cover and any transition cover, at
 * full strength; cinematic text, map transfer covers and notifications stay
 * above it. The same subject placement, timing and Terminal glyphs apply on
 * either plane, and the plane never changes collision, events or saves.
 */
enum CinematicFieldPlane: string
{
  /** The authored command or cast field that selects the plane. */
  public const string FIELD_NAME = 'plane';

  case FIELD = 'field';
  case ABOVE_COVER = 'aboveCover';

  /** The plane a command or staged actor entry authors; the field plane when it names none. */
  public static function getFromCommand(array $command): self
  {
    $value = array_key_exists(self::FIELD_NAME, $command) ? $command[self::FIELD_NAME] : self::FIELD->value;
    return (is_string($value) ? self::tryFrom($value) : null)
      ?? throw new InvalidArgumentException(sprintf('plane must be one of %s.',
        implode(', ', array_column(self::cases(), 'value'))));
  }
}
