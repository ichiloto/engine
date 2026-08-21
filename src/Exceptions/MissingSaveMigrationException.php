<?php

namespace Ichiloto\Engine\Exceptions;

/** A required adjacent schema or content migration was not registered. */
class MissingSaveMigrationException extends SaveCompatibilityException
{
}
