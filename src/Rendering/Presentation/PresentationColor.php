<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

final readonly class PresentationColor
{
  private function __construct(
    public PresentationColorKind $kind,
    private int $first,
    private int $second = 0,
    private int $third = 0,
  ) {}

  public static function ansi16(int $index): self
  {
    self::range($index, 15);
    return new self(PresentationColorKind::ANSI16, $index);
  }

  public static function ansi256(int $index): self
  {
    self::range($index);
    return new self(PresentationColorKind::ANSI256, $index);
  }

  public static function rgb(int $r, int $g, int $b): self
  {
    foreach ([$r, $g, $b] as $component) { self::range($component); }
    return new self(PresentationColorKind::RGB, $r, $g, $b);
  }

  public static function createFromArray(mixed $data): self
  {
    if (!is_array($data) || !is_string($data['kind'] ?? null)) {
      throw new InvalidArgumentException('Presentation color requires a typed color descriptor.');
    }
    $fields = match ($data['kind']) {
      'rgb' => ['r', 'g', 'b'],
      'ansi16', 'ansi256' => ['index'],
      default => throw new InvalidArgumentException('Presentation color supports rgb, ansi16 and ansi256 only.'),
    };
    if (array_diff(['kind', ...$fields], array_keys($data)) !== []
      || array_diff(array_keys($data), ['kind', ...$fields]) !== []) {
      throw new InvalidArgumentException('Presentation color descriptor contains missing or unsupported fields.');
    }
    foreach ($fields as $field) {
      if (!is_int($data[$field])) {
        throw new InvalidArgumentException('Presentation color components require integers without coercion.');
      }
    }
    return match ($data['kind']) {
      'rgb' => self::rgb($data['r'], $data['g'], $data['b']),
      'ansi16' => self::ansi16($data['index']),
      'ansi256' => self::ansi256($data['index']),
    };
  }

  /** @return array<string, int|string> */
  public function toArray(): array
  {
    return $this->kind === PresentationColorKind::RGB
      ? ['kind' => $this->kind->value, 'r' => $this->first, 'g' => $this->second, 'b' => $this->third]
      : ['kind' => $this->kind->value, 'index' => $this->first];
  }

  private static function range(int $value, int $max = 255): void
  {
    if ($value < 0 || $value > $max) {
      throw new InvalidArgumentException("Presentation colour component must be in 0..{$max}.");
    }
  }
}
