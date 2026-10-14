<?php

namespace Ichiloto\Engine\Core\ProjectState;

use Ichiloto\Engine\Exceptions\SaveCompatibilityException;

/** A project payload or its registered compatibility policy cannot be restored. */
final class ProjectStateException extends SaveCompatibilityException
{
}
