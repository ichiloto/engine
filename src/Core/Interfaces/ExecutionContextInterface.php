<?php

namespace Ichiloto\Engine\Core\Interfaces;

use Symfony\Component\Console\Output\OutputInterface;

interface ExecutionContextInterface
{
  /**
   * Returns the arguments.
   *
   * @return array<string, mixed> The arguments.
   */
  public function getArgs(): array;

  /**
   * Returns the output.
   *
   * @return OutputInterface The output.
   */
  public function getOutput(): OutputInterface;
}