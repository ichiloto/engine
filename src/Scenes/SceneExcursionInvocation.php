<?php

namespace Ichiloto\Engine\Scenes;

use Closure;
use Ichiloto\Engine\Scenes\Interfaces\ConfigurableSceneInterface;

/** @internal The retained caller belongs to SceneManager's shared suspension slot. */
final readonly class SceneExcursionInvocation
{
  public function __construct(
    public ConfigurableSceneInterface $scene,
    public SceneExcursionSession $session,
    private Closure $resolve,
  ) {}

  public function settle(SceneExcursionOutcome $outcome): void
  {
    ($this->resolve)($outcome);
  }
}
