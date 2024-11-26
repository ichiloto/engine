<?php

namespace Ichiloto\Engine\Exceptions;

use Throwable;

/**
 * Class OutOfBounds
 *
 * @package Ichiloto\Engine\Exceptions
 */
class OutOfBounds extends IchilotoException
{
  public function __construct(string $what, ?Throwable $previous = null)
  {
    parent::__construct("$what is out of bounds!", IchilotoException::OUT_OF_BOUNDS, $previous);
  }
}