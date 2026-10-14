<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Effects expand paint bounds, never the authoritative local text grid. */
final readonly class CanvasGlyphEffects
{
  public function __construct(
    public float $outlineWidth,
    public PresentationColor $outlineColor,
    public float $shadowOffsetX,
    public float $shadowOffsetY,
    public float $shadowSigma,
    public float $shadowOpacity,
    public PresentationColor $shadowColor,
  ) {
    foreach ([$outlineWidth, $shadowOffsetX, $shadowOffsetY, $shadowSigma, $shadowOpacity] as $value) {
      if (!is_finite($value)) { throw new InvalidArgumentException('Glyph effects must be finite.'); }
    }
    if ($outlineWidth < 0 || $outlineWidth > 4 || abs($shadowOffsetX) > 8 || abs($shadowOffsetY) > 8
      || $shadowSigma < 0 || $shadowSigma > 4 || $shadowOpacity < 0 || $shadowOpacity > 1) {
      throw new InvalidArgumentException('Glyph effects exceed the bounded contour or shadow limits.');
    }
  }

  public static function createFromArray(mixed $data): self
  {
    self::assertFields($data, ['outline', 'shadow']);
    self::assertFields($data['outline'], ['width', 'color']);
    self::assertFields($data['shadow'], ['offsetX', 'offsetY', 'sigma', 'opacity', 'color']);
    foreach ([$data['outline']['width'], $data['shadow']['offsetX'], $data['shadow']['offsetY'],
      $data['shadow']['sigma'], $data['shadow']['opacity']] as $value) {
      if (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Glyph effects require numbers without coercion.');
      }
    }
    return new self($data['outline']['width'], PresentationColor::createFromArray($data['outline']['color']),
      $data['shadow']['offsetX'], $data['shadow']['offsetY'], $data['shadow']['sigma'],
      $data['shadow']['opacity'], PresentationColor::createFromArray($data['shadow']['color']));
  }

  /** @return array{left: int, top: int, right: int, bottom: int} */
  public function padding(): array
  {
    $radius = $this->outlineWidth + ceil(3 * $this->shadowSigma);
    return ['left' => (int)ceil($radius + max(0, -$this->shadowOffsetX)),
      'top' => (int)ceil($radius + max(0, -$this->shadowOffsetY)),
      'right' => (int)ceil($radius + max(0, $this->shadowOffsetX)),
      'bottom' => (int)ceil($radius + max(0, $this->shadowOffsetY))];
  }

  public function toArray(): array
  {
    return ['outline' => ['width' => $this->outlineWidth, 'color' => $this->outlineColor->toArray()],
      'shadow' => ['offsetX' => $this->shadowOffsetX, 'offsetY' => $this->shadowOffsetY,
        'sigma' => $this->shadowSigma, 'opacity' => $this->shadowOpacity, 'color' => $this->shadowColor->toArray()]];
  }

  private static function assertFields(mixed $data, array $required): void
  {
    if (!is_array($data) || array_diff($required, array_keys($data)) !== []
      || array_diff(array_keys($data), $required) !== []) {
      throw new InvalidArgumentException('Glyph effects require plain descriptors with no missing or unsupported fields.');
    }
  }
}
