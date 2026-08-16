<?php

namespace Ichiloto\Engine\Entities\Stats;

use InvalidArgumentException;

/** One acquired, reversible permanent-growth entry with generic provenance. */
final readonly class PermanentStatModifier
{
  /** @param array<string, mixed> $metadata */
  public function __construct(
    public string $id,
    public StatKey $stat,
    public int $amount,
    public string $sourceType,
    public string $sourceId,
    public array $metadata = [],
  )
  {
    if (trim($this->id) === '') {
      throw new InvalidArgumentException('Permanent modifier id cannot be empty.');
    }

    if (trim($this->sourceType) === '' || trim($this->sourceId) === '') {
      throw new InvalidArgumentException('Permanent modifier source type and source id cannot be empty.');
    }
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return [
      'id' => $this->id,
      'stat' => $this->stat->value,
      'amount' => $this->amount,
      'sourceType' => $this->sourceType,
      'sourceId' => $this->sourceId,
      'metadata' => $this->metadata,
    ];
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data): self
  {
    if (! is_int($data['amount'] ?? null)) {
      throw new InvalidArgumentException('Permanent modifier amount must be an integer.');
    }

    return new self(
      strval($data['id'] ?? ''),
      StatKey::require(strval($data['stat'] ?? '')),
      $data['amount'],
      strval($data['sourceType'] ?? ''),
      strval($data['sourceId'] ?? ''),
      is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
    );
  }
}
