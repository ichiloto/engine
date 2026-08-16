<?php

namespace Ichiloto\Engine\Entities\Stats;

use InvalidArgumentException;
use JsonSerializable;

/** Saved acquired growth. Definitions and equipment never enter this ledger. */
final class PermanentGrowthLedger implements JsonSerializable
{
  /** @var array<string, PermanentStatModifier> */
  private array $entries = [];

  /**
   * Grants once. An identical repeat is idempotent; conflicting reuse fails.
   */
  public function grant(PermanentStatModifier $entry): bool
  {
    $id = trim($entry->id);
    $existing = $this->entries[$id] ?? null;

    if ($existing !== null) {
      if ($existing->toArray() !== $entry->toArray()) {
        throw new InvalidArgumentException(sprintf('Permanent modifier id "%s" has conflicting content.', $id));
      }

      return false;
    }

    $this->entries[$id] = $entry;
    return true;
  }

  public function has(string $id): bool
  {
    return isset($this->entries[trim($id)]);
  }

  public function get(string $id): ?PermanentStatModifier
  {
    return $this->entries[trim($id)] ?? null;
  }

  public function remove(string $id): bool
  {
    $id = trim($id);

    if (! isset($this->entries[$id])) {
      return false;
    }

    unset($this->entries[$id]);
    return true;
  }

  public function totalFor(StatKey $stat): int
  {
    return array_sum(array_map(
      static fn(PermanentStatModifier $entry): int => $entry->stat === $stat ? $entry->amount : 0,
      $this->entries,
    ));
  }

  /** @return PermanentStatModifier[] */
  public function all(): array
  {
    return array_values($this->entries);
  }

  /** @return array<int, array<string, mixed>> */
  public function jsonSerialize(): array
  {
    return array_map(
      static fn(PermanentStatModifier $entry): array => $entry->toArray(),
      array_values($this->entries),
    );
  }

  /** @param array<int, mixed> $data */
  public static function fromArray(array $data): self
  {
    $ledger = new self();

    foreach ($data as $row) {
      if (! is_array($row)) {
        throw new InvalidArgumentException('Permanent growth ledger entries must be arrays.');
      }

      $ledger->grant(PermanentStatModifier::fromArray($row));
    }

    return $ledger;
  }
}
