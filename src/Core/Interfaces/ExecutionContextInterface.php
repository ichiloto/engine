<?php

namespace Ichiloto\Engine\Core\Interfaces;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * ExecutionContextInterface is an interface implemented by all classes that represent the execution context of a command.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface ExecutionContextInterface
{
  /**
   * Returns the arguments.
   *
   * @return array<string, mixed> The arguments.
   */
  public array $args {
    get;
  }

  /**
   * Returns the output.
   *
   * @return OutputInterface The output.
   */
  public OutputInterface $output {
    get;
  }
}