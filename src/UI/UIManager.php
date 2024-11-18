<?php

namespace Ichiloto\Engine\UI;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Interfaces\SingletonInterface;

/**
 * The UI manager.
 */
class UIManager implements SingletonInterface, CanRender, CanUpdate, CanResume, CanStart
{
  /**
   * @var UIManager The instance of the UI manager.
   */
  protected static UIManager $instance;

  /**
   * UIManager constructor.
   */
  protected function __construct()
  {
  }

  /**
   * @inheritDoc
   */
  public static function getInstance(): self
  {
    if (!isset(self::$instance)) {
      self::$instance = new self();
    }

    return self::$instance;
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

  public function start(): void
  {
    // TODO: Implement start() method.
  }

  public function stop(): void
  {
    // TODO: Implement stop() method.
  }

  public function update(): void
  {
    // TODO: Implement update() method.
  }
}