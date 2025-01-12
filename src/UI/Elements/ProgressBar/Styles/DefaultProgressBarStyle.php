<?php

namespace Ichiloto\Engine\UI\Elements\ProgressBar\Styles;

use Ichiloto\Engine\UI\Windows\Interfaces\ProgressBarStyleInterface;

/**
 * Class DefaultProgressBarStyle. Represents the default progress bar style.
 *
 * @package Ichiloto\Engine\UI\Elements\Styles
 */
class DefaultProgressBarStyle implements ProgressBarStyleInterface
{
  /**
   * @inheritDoc
   */
  public string $leftCap {
    get {
      return '[';
    }
  }

  /**
   * @inheritDoc
   */
  public string $rightCap {
    get {
      return ']';
    }
  }

  /**
   * @inheritDoc
   */
  public string $fill {
    get {
      return '■';
    }
  }

  /**
   * @inheritDoc
   */
  public string $empty {
    get {
      return ' ';
    }
  }
}