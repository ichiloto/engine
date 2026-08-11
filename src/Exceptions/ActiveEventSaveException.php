<?php

namespace Ichiloto\Engine\Exceptions;

use RuntimeException;

/**
 * A save was requested while an in-memory story continuation was unstable.
 */
class ActiveEventSaveException extends RuntimeException
{
}
