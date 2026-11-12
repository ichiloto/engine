<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Menu\MasteryMenu\Modes;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\UI\Presentation\MasteryMenuLevel;

/**
 * The top of the Mastery menu: Left and Right choose a discipline (a card
 * suit, a school), Confirm opens its families and Cancel leaves the menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MasteryMenu\Modes
 */
class MasteryDisciplineSelectionMode extends MasteryMenuMode
{
  public MasteryMenuLevel $level {
    get => MasteryMenuLevel::DISCIPLINE;
  }

  protected function confirm(): void
  {
    if (! $this->state->hasFamilies) {
      play_sound(SystemSound::BUZZER);
      return;
    }
    $this->openMode(new MasteryFamilySelectionMode($this->state));
  }

  protected function move(int $across, int $down): void
  {
    if ($across !== 0) {
      $this->state->selectRegionByOffset($across);
    }
  }
}
