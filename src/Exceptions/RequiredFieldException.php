<?php

namespace Ichiloto\Engine\Exceptions;

use Throwable;

/**
 * Represents a required field exception.
 *
 * @package Ichiloto\Engine\Exceptions
 */
class RequiredFieldException extends IchilotoException
{
  /**
   * RequiredFieldException constructor.
   *
   * @param string $fieldName The name of the field.
   * @param int $code The exception code.
   * @param Throwable|null $previous The previous exception.
   */
  public function __construct(string $fieldName, int $code = 0, ?Throwable $previous = null)
  {
    parent::__construct("The field '$fieldName' is required.", $code, $previous);
  }
}