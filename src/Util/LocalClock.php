<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Util;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use IntlTimeZone;

/** Host-local wall time, independent of PHP's application/logging timezone. */
final class LocalClock
{
  public static function getTime(?DateTimeImmutable $instant = null): DateTimeImmutable
  {
    return ($instant ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
      ->setTimezone(self::getTimezone());
  }

  public static function getTimezone(): DateTimeZone
  {
    foreach (self::getTimezoneCandidates() as $candidate) {
      $candidate = ltrim(trim($candidate), ':');
      if (str_contains($candidate, '/zoneinfo/')) {
        $candidate = explode('/zoneinfo/', $candidate, 2)[1];
      }
      if ($candidate === '') { continue; }
      try { return new DateTimeZone($candidate); }
      catch (DateInvalidTimeZoneException) { /* Try the next host source. */ }
    }

    static $reported = false;
    if (!$reported) {
      $reported = true;
      Debug::warn('Host timezone could not be detected; local presentation uses the PHP timezone.');
    }
    return new DateTimeZone(date_default_timezone_get());
  }

  /** @return Generator<int, string> */
  private static function getTimezoneCandidates(): Generator
  {
    $environment = getenv('TZ');
    if ($environment !== false) { yield $environment; }

    // macOS and most Linux hosts link this file to their selected IANA zone.
    clearstatcache(true, '/etc/localtime');
    $localtime = realpath('/etc/localtime');
    if ($localtime !== false && str_contains($localtime, '/zoneinfo/')) { yield $localtime; }
    if (is_readable('/etc/timezone')) {
      $timezone = file_get_contents('/etc/timezone', length: 256);
      if ($timezone !== false) { yield $timezone; }
    }

    // ICU also resolves native Windows zones; intl remains an optional capability.
    if (class_exists(IntlTimeZone::class)) { yield IntlTimeZone::createDefault()->getID(); }
  }
}
