<?php

namespace Ichiloto\Engine\Entities\Actions;

use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Entities\Interfaces\ActionInterface;

/**
 * The FieldAction class.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
abstract class FieldAction implements ActionInterface
{
  protected function markAccepted(ActionContextInterface $context): void
  {
    if ($context instanceof FieldActionContext) {
      $context->markAccepted();
    }
  }
}