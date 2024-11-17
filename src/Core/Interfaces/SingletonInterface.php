<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * The singleton interface.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface SingletonInterface
{
  /**
   * Gets the instance of this singleton.
   *
   * @return self The instance of this singleton.
   */
  public static function getInstance(): self;
}