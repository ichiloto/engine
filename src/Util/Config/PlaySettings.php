<?php

namespace Ichiloto\Engine\Util\Config;

/**
 * The play settings.
 *
 * @package Ichiloto\Engine\Util
 */
class PlaySettings extends AbstractConfig
{
  /**
   * @inheritDoc
   */
  protected function load(): array
  {
    return $this->options;
  }

  /**
   * @inheritDoc
   */
  public function persist(): void
  {
    // Do nothing.
  }
}