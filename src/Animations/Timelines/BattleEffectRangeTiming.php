<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

/** An alternate presentation clock with the same duration and contact marks. */
final readonly class BattleEffectRangeTiming
{
  /** @param list<int> $authored
   * @param list<int> $presentation
   */
  private function __construct(public float $minDistance, private array $authored, private array $presentation) {}

  /** @param list<int> $fixedFrames */
  public static function fromArray(mixed $data, int $length, array $fixedFrames): self
  {
    $distance = is_array($data) ? ($data['minDistance'] ?? null) : null;
    $entries = is_array($data) ? ($data['keys'] ?? null) : null;
    if (!is_array($data) || count($data) !== 2 || (!is_float($distance) && !is_int($distance))
      || !is_finite((float)$distance) || $distance <= 0 || $distance > 1 || !is_array($entries)
      || !array_is_list($entries) || $entries === [] || count($entries) > 64) {
      throw new InvalidArgumentException('Battle range timing needs a normalized minimum distance and 1..64 keys.');
    }
    $keys = [0 => 0, $length => $length];
    foreach ($fixedFrames as $frame) { $keys[$frame] = $frame; }
    $authored = [];
    foreach ($entries as $entry) {
      $frame = is_array($entry) ? ($entry['frame'] ?? null) : null;
      $at = is_array($entry) ? ($entry['at'] ?? null) : null;
      if (!is_array($entry) || count($entry) !== 2 || !is_int($frame) || !is_int($at)
        || $frame < 1 || $frame >= $length || $at < 1 || $at >= $length || isset($authored[$frame])
        || (isset($keys[$frame]) && $keys[$frame] !== $at)) {
        throw new InvalidArgumentException('Battle range keys require unique interior frames and preserve authored contacts and cues.');
      }
      $authored[$frame] = $keys[$frame] = $at;
    }
    ksort($keys, SORT_NUMERIC);
    $last = -1;
    foreach ($keys as $at) {
      if ($at <= $last) { throw new InvalidArgumentException('Battle range timing must keep key and cue order.'); }
      $last = $at;
    }
    return new self((float)$distance, array_keys($keys), array_values($keys));
  }

  public function getPresentationFrame(float $frame): float
  {
    return self::interpolate($frame, $this->authored, $this->presentation);
  }

  public function getAuthoredFrame(float $frame): float
  {
    return self::interpolate($frame, $this->presentation, $this->authored);
  }

  /** @param list<int> $input
   * @param list<int> $output
   */
  private static function interpolate(float $frame, array $input, array $output): float
  {
    $lower = 0;
    $upper = count($input) - 1;
    if ($frame <= 0 || $frame >= $input[$upper]) { return $frame; }
    while ($upper - $lower > 1) {
      $middle = intdiv($lower + $upper, 2);
      if ($input[$middle] > $frame) { $upper = $middle; }
      else { $lower = $middle; }
    }
    $progress = ($frame - $input[$lower]) / ($input[$upper] - $input[$lower]);
    return $output[$lower] + ($output[$upper] - $output[$lower]) * $progress;
  }
}
