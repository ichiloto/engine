<?php

namespace Ichiloto\Engine\Events\Interpreter;

/**
 * What happened when the interpreter attempted one command.
 */
enum EventCommandResult: string
{
  case COMPLETED = 'completed';
  case YIELDED = 'yielded';
  case SUSPENDED = 'suspended';
  case FAILED = 'failed';
}
