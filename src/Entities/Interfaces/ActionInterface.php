<?php

namespace Ichiloto\Engine\Entities\Interfaces;

/**
 * The ActionInterface interface.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface ActionInterface
{
  /**
   * Executes the action.
   *
   * @param ActionContextInterface $context The context of the action.
   */
  public function execute(ActionContextInterface $context): void;
}