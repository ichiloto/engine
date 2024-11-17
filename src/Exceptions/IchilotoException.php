<?php

namespace Ichiloto\Engine\Exceptions;

use Exception;

/**
 * Class IchilotoException
 *
 * @package Ichiloto\Engine\Exceptions
 */
class IchilotoException extends Exception
{
  public const int GENERAL = 0;
  public const int INVALID_ARGUMENT = 1;
  public const int NOT_FOUND = 2;
  public const int NOT_IMPLEMENTED = 3;
  public const int NOT_SUPPORTED = 4;
  public const int OUT_OF_BOUNDS = 5;
  public const int PERMISSION_DENIED = 6;
  public const int RUNTIME = 7;
}