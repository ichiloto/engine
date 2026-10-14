<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

/** One map-owned weighted choice, not a troop's permanent battle presentation. */
final readonly class EncounterEntry
{
  /** Optional graphics are validated by the graphical path, never by encounter selection. */
  private function __construct(public int $weight, private array $presentationSettings)
  {
  }

  public static function getFromValue(mixed $value): ?self
  {
    $weight = is_array($value) ? ($value['weight'] ?? null) : $value;
    if (!is_numeric($weight) || intval($weight) <= 0) {
      return null;
    }
    return new self(intval($weight), is_array($value)
      ? array_intersect_key($value, ['battleArena' => true]) : []);
  }

  public function getBattleSettings(array $mapSettings): array
  {
    return array_replace($mapSettings, $this->presentationSettings);
  }
}
