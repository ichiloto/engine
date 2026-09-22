<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Shared strict boundaries for plain compositing descriptors. */
final class CanvasCompositeValues
{
  public static function validateKeys(mixed $data, array $required, array $optional = []): void
  {
    if (!is_array($data) || array_diff($required, array_keys($data)) !== []
      || array_diff(array_keys($data), [...$required, ...$optional]) !== [] || in_array(null, $data, true)) {
      throw new InvalidArgumentException('Invalid composite descriptor fields.');
    }
  }

  public static function getNumber(mixed $value, float $min, float $max): float
  {
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) {
      throw new InvalidArgumentException('Composite value is outside its finite range.');
    }
    return (float)$value;
  }

  public static function getList(mixed $values, int $min, int $max): array
  {
    if (!is_array($values) || !array_is_list($values) || count($values) < $min || count($values) > $max) {
      throw new InvalidArgumentException('Composite list exceeds its bounds.');
    }
    return $values;
  }

  public static function getPoint(mixed $value, float $min = -16384, float $max = 16384): array
  {
    return array_map(static fn($n) => self::getNumber($n, $min, $max), self::getList($value, 2, 2));
  }

  public static function getRectangle(mixed $value, bool $normalized = false): array
  {
    self::validateKeys($value, ['x', 'y', 'width', 'height']);
    $max = $normalized ? 1 : 16384;
    $rect = new CanvasRectangle(...array_map(static fn($key) => self::getNumber($value[$key], 0, $max),
      ['x', 'y', 'width', 'height']));
    $rect->assertWithin($max, $max);
    return $rect->toArray();
  }

  public static function getBoolean(mixed $value): bool
  {
    if (!is_bool($value)) { throw new InvalidArgumentException('Composite flag must be boolean.'); }
    return $value;
  }

  public static function getAsset(mixed $value): string
  {
    if (!is_string($value) || strlen($value) > 4096) { throw new InvalidArgumentException('Invalid composite PNG path.'); }
    SpriteValidation::validateAssetPath($value);
    return $value;
  }

  public static function getColor(mixed $value): array
  {
    $kind = is_array($value) ? ($value['kind'] ?? '') : '';
    $keys = match ($kind) { 'rgb' => ['r', 'g', 'b'], 'ansi16', 'ansi256' => ['index'],
      default => throw new InvalidArgumentException('Invalid composite color kind.') };
    self::validateKeys($value, ['kind', ...$keys]);
    $parts = array_map(static function ($key) use ($value): int {
      if (!is_int($value[$key])) { throw new InvalidArgumentException('Composite color components must be integers.'); }
      return $value[$key];
    }, $keys);
    return match ($kind) {
      'rgb' => PresentationColor::rgb($parts[0], $parts[1], $parts[2])->toArray(),
      'ansi16' => PresentationColor::ansi16($parts[0])->toArray(),
      'ansi256' => PresentationColor::ansi256($parts[0])->toArray(),
    };
  }
}
