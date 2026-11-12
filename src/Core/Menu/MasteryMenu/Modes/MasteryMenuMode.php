<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Menu\MasteryMenu\Modes;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\Interfaces\MainMenuModeInterface;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\Game\States\MasteryMenuState;
use Ichiloto\Engine\UI\Presentation\MasteryMenuLevel;

/**
 * One level of the Mastery menu, as the Shop, Item and Equipment menus have
 * their modes: the arrows move within it, Confirm opens the next level down
 * and Cancel returns to the mode it was opened from, or leaves the menu from
 * the top. Every level reads the same semantic actions, so the screen
 * teaches one small set of controls.
 *
 * @package Ichiloto\Engine\Core\Menu\MasteryMenu\Modes
 */
abstract class MasteryMenuMode implements MainMenuModeInterface
{
  /** @var MasteryMenuMode|null The mode Cancel returns to; none at the top, where Cancel leaves. */
  public ?MasteryMenuMode $previousMode = null;

  /** The level this mode puts the focus on. */
  abstract public MasteryMenuLevel $level { get; }

  public function __construct(protected MasteryMenuState $state)
  {
  }

  /** @inheritDoc */
  public function enter(): void
  {
  }

  /** @inheritDoc */
  public function exit(): void
  {
  }

  /** @inheritDoc */
  public function update(): void
  {
    if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      $this->navigateToPreviousMode();
      return;
    }
    if (Input::isButtonPressed('confirm')) {
      $this->confirm();
      return;
    }
    $across = Input::getAxis(AxisName::HORIZONTAL) <=> 0.0;
    $down = Input::getAxis(AxisName::VERTICAL) <=> 0.0;
    if ($across !== 0 || $down !== 0) {
      $this->move($across, $down);
    }
  }

  /** Confirm on this level's focus. */
  abstract protected function confirm(): void;

  /** The arrows: a step across and a step down, each -1, 0 or 1. */
  abstract protected function move(int $across, int $down): void;

  /** Opens the next level down; Cancel there comes back here. */
  protected function openMode(MasteryMenuMode $mode): void
  {
    play_sound(SystemSound::CONFIRM);
    $mode->previousMode = $this;
    $this->state->setMode($mode);
  }

  /** Cancel: back one level, or out of the menu from the top. */
  protected function navigateToPreviousMode(): void
  {
    play_sound(SystemSound::CANCEL);
    if ($this->previousMode === null) {
      $this->state->leave();
      return;
    }
    $this->state->setMode($this->previousMode);
  }
}
