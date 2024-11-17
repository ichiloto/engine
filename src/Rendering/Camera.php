<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

class Camera implements CanStart, CanResume, CanRender
{
  /**
   * Camera constructor.
   *
   * @param SceneInterface $scene The scene that this camera is rendering.
   */
  public function __construct(
    protected SceneInterface $scene,
    protected int $width = DEFAULT_SCREEN_WIDTH,
    protected int $height = DEFAULT_SCREEN_HEIGHT
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    // TODO: Implement start() method.
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    // TODO: Implement stop() method.
  }

  public function render(): void
  {
    // TODO: Implement render() method.
  }

  public function erase(): void
  {
    // TODO: Implement erase() method.
  }

  public function resume(): void
  {
    // TODO: Implement resume() method.
  }

  public function suspend(): void
  {
    // TODO: Implement suspend() method.
  }
}