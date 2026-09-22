<?php

declare(strict_types=1);

use Ichiloto\Engine\UI\Presentation\TitlePlayback;
use Ichiloto\Engine\Util\LocalClock;

beforeEach(function () {
  $this->phpTimezone = date_default_timezone_get();
  $this->hostTimezone = getenv('TZ');
  date_default_timezone_set('UTC');
});

afterEach(function () {
  date_default_timezone_set($this->phpTimezone);
  putenv($this->hostTimezone === false ? 'TZ' : 'TZ=' . $this->hostTimezone);
});

it('uses host-local evening rather than the PHP UTC hour', function () {
  putenv('TZ=Africa/Lusaka');
  $instant = new DateTimeImmutable('2026-09-22T17:48:00Z');
  $local = LocalClock::getTime($instant);
  $title = new TitlePlayback();
  $title->advance(0, $local, false);

  expect($local->format('H:i P'))->toBe('19:48 +02:00')
    ->and($local->getTimestamp())->toBe($instant->getTimestamp())
    ->and($title->nightWeight)->toBe(1.0)
    ->and(get_local_timezone())->toBe('Africa/Lusaka')
    ->and(date_default_timezone_get())->toBe('UTC');
});

it('converts host-local day boundaries before choosing scenery', function (string $utc, float $night) {
  putenv('TZ=Africa/Lusaka');
  $title = new TitlePlayback();
  $title->advance(0, LocalClock::getTime(new DateTimeImmutable($utc)), false);
  expect($title->nightWeight)->toBe($night);
})->with([
  ['2026-09-22T03:59:59Z', 1.0], ['2026-09-22T04:00:00Z', 0.0],
  ['2026-09-22T15:59:59Z', 0.0], ['2026-09-22T16:00:00Z', 1.0],
]);

it('retains regional daylight-saving rules rather than freezing an offset', function () {
  putenv('TZ=America/New_York');
  expect(LocalClock::getTime(new DateTimeImmutable('2026-01-22T12:00:00Z'))->format('H:i P'))->toBe('07:00 -05:00')
    ->and(LocalClock::getTime(new DateTimeImmutable('2026-07-22T12:00:00Z'))->format('H:i P'))->toBe('08:00 -04:00');
});

it('rechecks a changed host timezone without changing application time', function () {
  $instant = new DateTimeImmutable('2026-09-22T17:48:00Z');
  putenv('TZ=Africa/Lusaka');
  expect(LocalClock::getTime($instant)->format('H:i'))->toBe('19:48');
  putenv('TZ=America/New_York');
  expect(LocalClock::getTime($instant)->format('H:i'))->toBe('13:48')
    ->and(date_default_timezone_get())->toBe('UTC');
});

it('accepts colon-prefixed host zone identifiers', function () {
  putenv('TZ=:Pacific/Auckland');
  expect(LocalClock::getTimezone()->getName())->toBe('Pacific/Auckland');
});

it('falls through an invalid environment zone to the host configuration', function () {
  putenv('TZ');
  $host = LocalClock::getTimezone()->getName();
  putenv('TZ=Not/A_Timezone');
  expect(LocalClock::getTimezone()->getName())->toBe($host);
});

it('uses the local clock when title playback receives no explicit test time', function () {
  // Force the same instant to opposite local dayparts without changing any clock.
  $utcHour = (int)gmdate('G');
  foreach ([12 => 0.0, 0 => 1.0] as $hour => $night) {
    $offset = (($hour - $utcHour + 36) % 24) - 12;
    putenv('TZ=' . sprintf('%+03d:00', $offset));
    $title = new TitlePlayback();
    $title->advance(0, null, false);
    expect($title->nightWeight)->toBe($night);
  }
  expect(date_default_timezone_get())->toBe('UTC');
});
