<?php

namespace Ichiloto\Engine\Scenes;

use Ichiloto\Engine\Scenes\Interfaces\SceneExcursionResultInterface;
use Throwable;

final readonly class SceneExcursionOutcome
{
  private function __construct(
    public SceneExcursionStatus $status,
    public ?SceneExcursionResultInterface $result = null,
    public ?Throwable $failure = null,
  ) {}

  public static function complete(SceneExcursionResultInterface $result): self
  {
    return new self(SceneExcursionStatus::COMPLETED, $result);
  }

  public static function cancel(SceneExcursionResultInterface $result): self
  {
    return new self(SceneExcursionStatus::CANCELLED, $result);
  }

  public static function abandon(): self
  {
    return new self(SceneExcursionStatus::ABANDONED);
  }

  public static function fail(Throwable $failure): self
  {
    return new self(SceneExcursionStatus::FAILED, failure: $failure);
  }
}
