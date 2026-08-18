<?php

namespace Ichiloto\Engine\Entities\Actions;

use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Triggers\CinematicEventTrigger;

/** Starts a first-class Cinematic through its map trigger. */
final class RunCinematicAction extends FieldAction
{
  public function __construct(
    protected CinematicEventTrigger $trigger,
  )
  {
  }

  /** @inheritDoc */
  public function execute(ActionContextInterface $context): void
  {
    $this->trigger->startSession($context->scene);
  }
}
