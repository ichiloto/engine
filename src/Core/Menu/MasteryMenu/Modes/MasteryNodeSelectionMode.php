<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Menu\MasteryMenu\Modes;

use Ichiloto\Engine\UI\Presentation\MasteryMenuLevel;

/**
 * A family's tree: the arrows move along it, Confirm learns the focused
 * node (held where input reports held keys, asked and then pressed again
 * where it does not) and Cancel returns to the families, on the family the
 * focus is in.
 *
 * @package Ichiloto\Engine\Core\Menu\MasteryMenu\Modes
 */
class MasteryNodeSelectionMode extends MasteryMenuMode
{
  public MasteryMenuLevel $level {
    get => MasteryMenuLevel::TREE;
  }

  public function exit(): void
  {
    $this->state->focusNodeFamily();
  }

  protected function confirm(): void
  {
    $this->state->confirmLearn();
  }

  protected function move(int $across, int $down): void
  {
    $this->state->moveFocus($across, $down);
  }
}
