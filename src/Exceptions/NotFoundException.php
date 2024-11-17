<?php

namespace Ichiloto\Engine\Exceptions;

use Throwable;

/**
 * Class NotFoundException
 *
 * @package Ichiloto\Engine\Exceptions
 */
class NotFoundException extends IchilotoException
{
  public function __construct(string $what, ?Throwable $previous = null)
  {
    parent::__construct("$what not found!", IchilotoException::NOT_FOUND, $previous);
  }
}