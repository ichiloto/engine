<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;

class Camera implements CanStart, CanResume, CanRender
{
  public function __construct(
    protected
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