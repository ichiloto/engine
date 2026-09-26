<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Exceptions\UnsupportedProjectFormatException;

/**
 * The project format version, recorded in ichiloto.json by `ichiloto upgrade`.
 * Tools write and read it; authors never track it. Each version names what it
 * changed, so an outdated project can say what `ichiloto upgrade` will do.
 */
final class ProjectFormat
{
  public const string KEY = 'format';
  /** The format this engine reads. */
  public const int CURRENT = 2;
  /** What each version introduced. */
  public const array CHANGES = [
    1 => 'a stable project id and save compatibility manifest',
    2 => 'square map cells, two terminal columns wide',
  ];

  /** A project without a recorded version predates the format chain. */
  public static function getVersion(mixed $recorded): int
  {
    return is_int($recorded) && $recorded >= 0 ? $recorded : 0;
  }

  /** @throws UnsupportedProjectFormatException */
  public static function assertSupported(mixed $recorded): void
  {
    $version = self::getVersion($recorded);
    if ($version > self::CURRENT) {
      throw new UnsupportedProjectFormatException(sprintf(
        'This project uses format %d, which is newer than this engine reads (%d). Update Ichiloto to open it.',
        $version, self::CURRENT));
    }
    if ($version < self::CURRENT) {
      $pending = array_slice(self::CHANGES, $version, preserve_keys: true);
      throw new UnsupportedProjectFormatException(sprintf(
        'This project predates %s. Run `ichiloto upgrade` in the project directory to convert it.',
        implode('; and ', $pending)));
    }
  }
}
