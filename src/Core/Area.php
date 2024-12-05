<?php

namespace Ichiloto\Engine\Core;

/**
 * The Area class.
 *
 * @package Ichiloto\Engine\Core
 */
class Area
{
  /**
   * Area constructor.
   *
   * @param float $width The width.
   * @param float $height The height.
   */
  public function __construct(
    public float $width,
    public float $height
  )
  {
  }
}