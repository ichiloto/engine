<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanExecute is an interface implemented by all classes that can execute.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanExecute
{
  /**
   * Executes the object.
   *
   * @param ExecutionContextInterface $context The context of the execution.
   * @return int The result of the execution.
   */
  public function execute(ExecutionContextInterface $context): int;
}