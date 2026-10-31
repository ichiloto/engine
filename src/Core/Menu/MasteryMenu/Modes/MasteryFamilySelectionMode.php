<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Menu\MasteryMenu\Modes;

use Ichiloto\Engine\UI\Presentation\MasteryMenuLevel;

/**
 * The shown discipline's families: the arrows move across them, Confirm
 * opens the focused family's tree and Cancel returns to the disciplines.
 *
 * @package Ichiloto\Engine\Core\Menu\MasteryMenu\Modes
 */
class MasteryFamilySelectionMode extends MasteryMenuMode
{
  public MasteryMenuLevel $level {
    get => MasteryMenuLevel::FAMILY;
  }

  protected function confirm(): void
  {
    if ($this->state->openFamily()) {
      $this->openMode(new MasteryNodeSelectionMode($this->state));
    }
  }

  protected function move(int $across, int $down): void
  {
    $this->state->moveFamilyFocus($across, $down);
  }
}
