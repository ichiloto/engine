<?php

namespace Ichiloto\Engine\Exceptions;

/** The project's format is older or newer than this engine reads. */
class UnsupportedProjectFormatException extends IchilotoException
{
  public function __construct(string $message)
  {
    parent::__construct($message, IchilotoException::NOT_SUPPORTED);
  }
}
