<?php

namespace Ichiloto\Engine\UI\Windows\Interfaces;

/**
 * Interface ProgressBarStyleInterface. The interface for progress bar styles.
 *
 * @package Ichiloto\Engine\UI\Windows\Interfaces
 */
interface ProgressBarStyleInterface
{
  /**
   * The left cap.
   */
  public string $leftCap {
    get;
  }

  /**
   * The right cap.
   */
  public string $rightCap {
    get;
  }

  /**
   * The fill.
   */
  public string $fill {
    get;
  }

  /**
   * The empty.
   */
  public string $empty {
    get;
  }
}