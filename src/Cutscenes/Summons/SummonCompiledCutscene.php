<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Represents compiled summon playback data ready for runtime consumption.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCompiledCutscene
{
  /**
   * @param array<int, array<string, mixed>> $playbackSegments
   * @param array<int, array<string, mixed>> $cueSchedule
   * @param array<string, mixed> $transitionCache
   * @param array<string, mixed> $defaults
   */
  public function __construct(
    public string $sourceId,
    public string $sourceHash,
    public int $compileVersion = 1,
    public int $fps = 24,
    public array $playbackSegments = [],
    public array $cueSchedule = [],
    public array $transitionCache = [],
    public array $defaults = [],
  )
  {
    $this->sourceId = trim($sourceId);
    $this->sourceHash = trim($sourceHash);
    $this->compileVersion = max(1, $compileVersion);
    $this->fps = max(1, $fps);
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      strval($data['sourceId'] ?? ''),
      strval($data['sourceHash'] ?? ''),
      intval($data['compileVersion'] ?? 1),
      intval($data['fps'] ?? 24),
      array_values(array_filter($data['playbackSegments'] ?? [], 'is_array')),
      array_values(array_filter($data['cueSchedule'] ?? [], 'is_array')),
      is_array($data['transitionCache'] ?? null) ? $data['transitionCache'] : [],
      is_array($data['defaults'] ?? null) ? $data['defaults'] : [],
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      'sourceId' => $this->sourceId,
      'sourceHash' => $this->sourceHash,
      'compileVersion' => $this->compileVersion,
      'fps' => $this->fps,
      'playbackSegments' => $this->playbackSegments,
      'cueSchedule' => $this->cueSchedule,
      'transitionCache' => $this->transitionCache,
      'defaults' => $this->defaults,
    ];
  }
}

