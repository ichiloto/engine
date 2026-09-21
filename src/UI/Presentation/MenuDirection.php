<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/** Shared direction symbols for navigation, quantity controls and value comparisons. */
enum MenuDirection: string
{
  case LEFT = 'previous';
  case RIGHT = 'next';
  case UP = 'up';
  case DOWN = 'down';

  public function getGlyph(): string
  {
    return match ($this) {
      self::LEFT => "\u{2190}", self::RIGHT => "\u{2192}",
      self::UP => "\u{2191}", self::DOWN => "\u{2193}",
    };
  }

  /** Exact semantic lookup, never unrelated Unknown artwork. */
  public function getImages(?MenuIconRegistry $icons, string $id, CanvasRectangle $bounds, int $layer): array
  {
    $asset = $icons?->icons['navigation.' . $this->value] ?? null;
    return $asset === null ? [] : $icons->contain($id, $asset, $bounds, $layer, $bounds);
  }
}
