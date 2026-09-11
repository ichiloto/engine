<?php

namespace Ichiloto\Engine\Audio;

/** A project-authored scenario, optionally restricted to named maps. */
final readonly class FieldMusicRule
{
  public function __construct(
    public string $id,
    public ?string $track,
    public int $priority,
    public array $conditions,
    public array $maps,
  ) {}
}
