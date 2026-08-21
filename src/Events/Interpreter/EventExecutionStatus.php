<?php

namespace Ichiloto\Engine\Events\Interpreter;

/**
 * The lifecycle of one in-memory event-script execution session.
 */
enum EventExecutionStatus: string
{
  case RUNNING = 'running';
  case YIELDED = 'yielded';
  case SUSPENDED = 'suspended';
  case COMPLETED = 'completed';
  case FAILED = 'failed';
}
