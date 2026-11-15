<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasColorTransform, CanvasCompositeValues};
use InvalidArgumentException;

/** One complete treatment state. Null explicitly retains a drawing's original colour. */
final readonly class BattleFieldColorState
{
  /** @param array<int|string, ?CanvasColorTransform> $effects */
  private function __construct(public ?CanvasColorTransform $field, public ?CanvasColorTransform $battlers,
    public array $effects) {}

  public static function fromArray(array $payload): self
  {
    self::assertFields($payload, ['field', 'battlers', 'effects']);
    $effects = [];
    foreach (CanvasCompositeValues::getList($payload['effects'], 0, 32) as $entry) {
      if (!is_array($entry)) { throw new InvalidArgumentException('Field colour effect selections require descriptors.'); }
      self::assertFields($entry, ['track', 'transform']);
      if (!is_string($entry['track'])) { throw new InvalidArgumentException('Field colour requires stable effect track IDs.'); }
      EffectTimelineLibrary::assertId($entry['track']);
      if (array_key_exists($entry['track'], $effects)) { throw new InvalidArgumentException('Field colour effect selections must be unique.'); }
      $effects[$entry['track']] = self::parseTransform($entry['transform']);
    }
    return new self(self::parseTransform($payload['field']), self::parseTransform($payload['battlers']), $effects);
  }

  /** Palette nulls mean original colour; composite descriptors retain their separate non-null contract. */
  private static function assertFields(array $data, array $required): void
  {
    if (array_diff($required, array_keys($data)) !== [] || array_diff(array_keys($data), $required) !== []) {
      throw new InvalidArgumentException('Field colour state requires exactly its declared fields.');
    }
  }

  private static function parseTransform(mixed $value): ?CanvasColorTransform
  {
    if ($value === null) { return null; }
    if (!is_array($value)) { throw new InvalidArgumentException('Field colour requires an affine transform or explicit null.'); }
    CanvasCompositeValues::validateKeys($value, ['matrix', 'offset']);
    if (!is_array($value['matrix']) || !is_array($value['offset'])) {
      throw new InvalidArgumentException('Field colour requires numeric RGB matrix and offset arrays.');
    }
    return new CanvasColorTransform($value['matrix'], $value['offset']);
  }

  public function toArray(): array
  {
    $effects = [];
    foreach ($this->effects as $track => $transform) { $effects[] = ['track' => (string)$track, 'transform' => $transform?->toArray()]; }
    return ['field' => $this->field?->toArray(), 'battlers' => $this->battlers?->toArray(), 'effects' => $effects];
  }
}
