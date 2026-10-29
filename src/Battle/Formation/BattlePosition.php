<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Formation;

use InvalidArgumentException;

/** Logical combat position; art placement and terminal coordinates do not determine it. */
final readonly class BattlePosition
{
  public function __construct(public BattleRow $row, public int $lane)
  {
    if ($lane < 0) { throw new InvalidArgumentException('A battle lane must be a nonnegative integer.'); }
  }

  public static function fromArray(mixed $data, string $context = 'Battle position'): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['row', 'lane']) !== []
      || !is_string($data['row'] ?? null) || ($row = BattleRow::tryFrom($data['row'])) === null
      || !is_int($data['lane'] ?? null) || $data['lane'] < 0) {
      throw new InvalidArgumentException("{$context} requires row front/back and a nonnegative integer lane.");
    }
    return new self($row, $data['lane']);
  }

  /** @return array{row: string, lane: int} */
  public function toArray(): array { return ['row' => $this->row->value, 'lane' => $this->lane]; }

  /** @param list<self|null> $positions */
  public static function validateDistinct(array $positions): void
  {
    if (!array_is_list($positions)) { throw new InvalidArgumentException('Logical battle positions must be a list.'); }
    $occupied = [];
    foreach ($positions as $position) {
      if ($position === null) { continue; }
      if (!$position instanceof self) { throw new InvalidArgumentException('Logical battle positions must be BattlePosition values or null.'); }
      $key = $position->row->value . ':' . $position->lane;
      if (isset($occupied[$key])) { throw new InvalidArgumentException('Two participants on one side cannot occupy the same logical battle position.'); }
      $occupied[$key] = true;
    }
  }
}
