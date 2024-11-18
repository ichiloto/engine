<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanExecute is an interface implemented by all classes that can execute.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanExecute
{
  const int SUCCESS = 0;
  const int FAILURE = 1;
  const int INVALID = 2;

  /**
   * Executes the object.
   *
   * @param ExecutionContextInterface|null $context The context of the execution.
   * @return int The result of the execution.
   */
  public function execute(?ExecutionContextInterface $context = null): int;
}