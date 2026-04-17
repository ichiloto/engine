<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Represents one authored cue in a summon cutscene timeline.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCue
{
  /**
   * @param array<string, mixed> $payload
   */
  public function __construct(
    public string $id,
    public int $frame = 0,
    public string $type = 'showMessage',
    public array $payload = [],
  )
  {
    $this->id = trim($id);
    $this->frame = max(0, $frame);
    $this->type = trim($type) !== '' ? trim($type) : 'showMessage';
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      strval($data['id'] ?? ''),
      intval($data['frame'] ?? 0),
      strval($data['type'] ?? 'showMessage'),
      is_array($data['payload'] ?? null) ? array_filter($data['payload'], static fn(mixed $item): bool => true) : [],
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      'id' => $this->id,
      'frame' => $this->frame,
      'type' => $this->type,
      'payload' => $this->payload,
    ];
  }
}

