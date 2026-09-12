<?php

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
