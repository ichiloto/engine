<?php

namespace Ichiloto\Engine\Scenes\Interfaces;

/** Optional configuration capability; ordinary SceneInterface implementations remain valid. */
interface ConfigurableSceneInterface extends SceneInterface
{
  public function configure(SceneConfigurationInterface $config): void;
}
