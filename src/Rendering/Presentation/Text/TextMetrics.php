<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Text;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use InvalidArgumentException;

final readonly class TextMetrics
{
  public function __construct(
    public float $advance,
    public float $baseline,
    public TextBounds $lineBounds,
    public ?TextBounds $inkBounds,
    public TextBounds $paintBounds,
  ) {
    foreach ([$advance, $baseline] as $value) {
      if (!is_finite($value) || $value < 0 || $value > CanvasValidation::MAX_EXTENT) {
        throw new InvalidArgumentException('Text advance and baseline must be finite and in 0..16384 logical pixels.');
      }
    }
  }

  /** @param array<array-key, mixed> $data */
  public static function fromArray(array $data): self
  {
    $fields = ['advance', 'baseline', 'lineBounds', 'inkBounds', 'paintBounds'];
    if (array_diff($fields, array_keys($data)) !== [] || array_diff(array_keys($data), $fields) !== []) {
      throw new InvalidArgumentException('Text metrics require exactly advance, baseline, lineBounds, inkBounds and paintBounds.');
    }
    $advance = $data['advance'];
    $baseline = $data['baseline'];
    if ((!is_int($advance) && !is_float($advance)) || (!is_int($baseline) && !is_float($baseline))) {
      throw new InvalidArgumentException('Text advance and baseline require numbers without coercion.');
    }
    return new self((float)$advance, (float)$baseline, self::requireBounds($data['lineBounds']),
      $data['inkBounds'] === null ? null : self::requireBounds($data['inkBounds']), self::requireBounds($data['paintBounds']));
  }

  private static function requireBounds(mixed $data): TextBounds
  {
    if (!is_array($data)) {
      throw new InvalidArgumentException('Text metric bounds require rectangle fields.');
    }
    return TextBounds::fromArray($data);
  }

  /**
   * @return array{advance: float, baseline: float,
   *   lineBounds: array{x: float, y: float, width: float, height: float},
   *   inkBounds: array{x: float, y: float, width: float, height: float}|null,
   *   paintBounds: array{x: float, y: float, width: float, height: float}}
   */
  public function toArray(): array
  {
    return ['advance' => $this->advance, 'baseline' => $this->baseline, 'lineBounds' => $this->lineBounds->toArray(),
      'inkBounds' => $this->inkBounds?->toArray(), 'paintBounds' => $this->paintBounds->toArray()];
  }
}
