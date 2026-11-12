<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use InvalidArgumentException;

/** Cell-art head and repeating shaft on one captured Terminal lane, with no image dependency. */
final class BattleGlyphLaneTrack
{
  public static function compile(array $track, int $length, string $playback, EffectCadence $cadence, mixed $captureFrame): array
  {
    self::validateFields($track, ['id', 'type', 'facing', 'keyframes'], ['presentation']);
    $facing = is_string($track['facing']) ? EffectHorizontalFacing::tryFrom($track['facing']) : null;
    if ($facing === null || $playback !== 'once' || $cadence !== EffectCadence::FIXED
      || !is_int($captureFrame) || $captureFrame < 0 || $captureFrame >= $length) {
      throw new InvalidArgumentException('A glyph lane requires facing and a targetCaptureFrame in a fixed once battle timeline.');
    }
    $frames = $track['keyframes'];
    if (!is_array($frames) || !array_is_list($frames) || $frames === [] || count($frames) > min(10000, $length)) {
      throw new InvalidArgumentException('Glyph lane samples require a bounded nonempty list.');
    }
    $keys = []; $end = $captureFrame;
    foreach ($frames as $key) {
      self::validateFields($key, ['frame', 'payload'], ['duration', 'visible', 'color', 'zIndex']);
      $at = $key['frame']; $duration = $key['duration'] ?? 1;
      if (!is_int($at) || !is_int($duration) || $at < $end || $at >= $length || $duration < 1 || $duration > $length - $at
        || (array_key_exists('visible', $key) && !is_bool($key['visible']))
        || (array_key_exists('zIndex', $key) && $key['zIndex'] !== 0)) {
        throw new InvalidArgumentException('Glyph lane samples must follow capture in ordered nonoverlapping intervals.');
      }
      self::validateFields($key['payload'], ['head', 'shaft']);
      $head = self::copyRows($key['payload']['head']); $shaft = self::copyRows($key['payload']['shaft']);
      if (count($head) !== count($shaft)) { throw new InvalidArgumentException('Glyph lane head and shaft require the same odd row count.'); }
      $color = $key['color'] ?? null;
      if ($color !== null && (!is_string($color) || !array_any(Color::cases(),
        static fn(Color $value): bool => strtolower($value->name) === strtolower($color)))) {
        throw new InvalidArgumentException('Glyph lane colour requires a named Terminal colour.');
      }
      $keys[] = ['frame' => $at, 'duration' => $duration, 'visible' => $key['visible'] ?? true,
        ...($color === null ? [] : ['color' => $color]),
        'payload' => ['head' => $head, 'shaft' => $shaft, 'facing' => $facing->value,
          'anchor' => 'target-group']];
      $end = $at + $duration;
    }
    return ['id' => $track['id'], 'type' => 'glyph-lane', 'presentation' => 'terminal', 'keyframes' => $keys];
  }

  private static function validateFields(mixed $data, array $required, array $optional = []): void
  {
    if (!is_array($data) || array_diff($required, array_keys($data)) !== []
      || array_diff(array_keys($data), [...$required, ...$optional]) !== [] || in_array(null, $data, true)) {
      throw new InvalidArgumentException('Invalid glyph lane descriptor fields.');
    }
  }

  private static function copyRows(mixed $rows): array
  {
    if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 9 || count($rows) % 2 !== 1) {
      throw new InvalidArgumentException('Glyph lane stencils need one to nine odd rows.');
    }
    $copy = []; $width = null;
    foreach ($rows as $row) {
      if (!is_string($row) || strlen($row) > 256 || preg_match('//u', $row) !== 1 || preg_match('/[\p{Cc}\p{Cf}]/u', $row)) {
        throw new InvalidArgumentException('Glyph lane rows require printable UTF-8 without control sequences.');
      }
      $span = TerminalText::displayWidth($row);
      $width ??= $span;
      if ($span < 1 || $span > 32 || $span !== $width) { throw new InvalidArgumentException('Glyph lane rows need an equal width in one to32cells.'); }
      $copy[] = $row;
    }
    if (trim(implode('', $copy)) === '') { throw new InvalidArgumentException('A glyph lane stencil needs visible cell art.'); }
    return $copy;
  }
}
