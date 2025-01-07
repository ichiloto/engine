<?php

namespace Ichiloto\Engine\UI\Elements;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\Elements\Styles\DefaultProgressBarStyle;
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
   * @inheritDoc
   */
  public function render(): void
  {
    $bar = str_repeat($this->style->fill, $this->filledUnits);
    $bar .= str_repeat($this->style->empty, $this->emptyUnits);
    $content = $this->style->leftCap . $bar . $this->style->rightCap;
    $this->camera->draw($content, $this->position->x, $this->position->y);
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $blank = str_repeat(' ', $this->units + 2);
    $this->camera->draw($blank, $this->position->x, $this->position->y);
  }

  public function fill(float $percentage): void
  {
    $this->fillPercentage = $percentage;
    $this->render();
  }

  public function fillByUnits(int $units): void
  {
    $percentage = $units / $this->units;
    $this->fill($percentage);
  }

  public function progress(float $percentage): void
  {
    $this->fillPercentage += $percentage;
    $this->render();
  }

  public function progressByUnits(int $units = 1): void
  {
    $this->progress($units / $this->units);
  }
}