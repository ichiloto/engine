<?php

namespace Ichiloto\Engine\UI\Elements\ProgressBar;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\Elements\ProgressBar\Styles\DefaultProgressBarStyle;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;
use Ichiloto\Engine\UI\Windows\Interfaces\ProgressBarStyleInterface;

/**
 * Class ProgressBar. Represents a progress bar.
 *
 * @package Ichiloto\Engine\UI\Elements
 */
class ProgressBar implements UIElementInterface
{
  /**
   * @inheritDoc
   */
  protected(set) bool $isActive = true;
  /**
   * The fill percentage of the progress bar.
   */
  public float $fillPercentage = 0.0 {
    set {
      $this->fillPercentage = clamp($value, 0.0, 1.0);
    }
  } // 0.0 - 1.0
  /**
   * The number of units in the progress bar.
   */
  protected int $filledUnits {
    get {
      return (int) ($this->fillPercentage * $this->units);
    }
  }
  /**
   * The number of empty units in the progress bar.
   */
  protected int $emptyUnits {
    get {
      return $this->units - $this->filledUnits;
    }
  }

  /**
   * ProgressBar constructor.
   *
   * @param Camera $camera The camera.
   * @param int $units The number of units in the progress bar.
   * @param Vector2 $position The position of the progress bar.
   * @param ProgressBarStyleInterface $style The style of the progress bar.
   */
  public function __construct(
    protected Camera $camera,
    protected int $units,
    protected Vector2 $position = new Vector2(0, 0),
    protected ProgressBarStyleInterface $style = new DefaultProgressBarStyle()
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $this->isActive = true;
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    $this->isActive = false;
  }

  /**
   * Gets the render of the progress bar.
   *
   * @return string The render.
   */
  public function getRender(): string
  {
    $bar = str_repeat($this->style->fill, $this->filledUnits);
    $bar .= str_repeat($this->style->empty, $this->emptyUnits);
    return $this->style->leftCap . $bar . $this->style->rightCap;
  }

  /**
   * Gets a blank plate.
   *
   * @return string The blank plate.
   */
  public function getBlankPlate(): string
  {
    return str_repeat(' ', $this->units + 2);
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->camera->draw($this->getRender(), $this->position->x, $this->position->y);
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->camera->draw($this->getBlankPlate(), $this->position->x, $this->position->y);
  }

  /**
   * Fills the progress bar by the given percentage.
   *
   * @param float $percentage The percentage to fill by.
   * @param bool $renderAfterFill Whether to render after filling.
   */
  public function fill(float $percentage, bool $renderAfterFill = false): void
  {
    $this->fillPercentage = $percentage;
    if ($renderAfterFill) {
      $this->render();
    }
  }

  /**
   * Fills the progress bar by the given number of units.
   *
   * @param int $units The number of units to fill by.
   */
  public function fillByUnits(int $units, bool $renderAfterFill = false): void
  {
    $percentage = $units / $this->units;
    $this->fill($percentage, $renderAfterFill);
  }

  /**
   * Progresses the progress bar by the given percentage.
   *
   * @param float $percentage The percentage to progress by.
   * @param bool $renderAfterProgress Whether to render after progressing.
   */
  public function progress(float $percentage, bool $renderAfterProgress = false): void
  {
    $this->fillPercentage += $percentage;
    if ($renderAfterProgress) {
      $this->render();
    }
  }

  /**
   * Progresses the progress bar by the given number of units.
   *
   * @param int $units The number of units to progress by.
   * @param bool $renderAfterProgress Whether to render after progressing.
   */
  public function progressByUnits(int $units = 1, bool $renderAfterProgress = false): void
  {
    $this->progress($units / $this->units, $renderAfterProgress);
  }
}