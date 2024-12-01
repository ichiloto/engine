<?php

namespace Ichiloto\Engine\Exceptions;

use Throwable;

/**
 * Class NotImplementedException. Represents an exception that is thrown when a feature is not implemented.
 *
 * @package Ichiloto\Engine\Exceptions
 */
class NotImplementedException extends IchilotoException
{
  /**
   * NotImplementedException constructor.
   *
   * @param string $feature The feature that is not implemented.
   * @param  int $code The exception code.
   * @param Throwable|null $previous The previous exception.
   */
  public function __construct(string $feature, int $code = 0, Throwable $previous = null)
  {
    parent::__construct("The feature '{$feature}' is not implemented.", $code, $previous);
  }
}