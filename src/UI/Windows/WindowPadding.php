<?php

namespace Ichiloto\Engine\UI\Windows;

/**
 * Represents the window's padding.
 *
 * @package Ichiloto\Engine\UI\Windows
 */
class WindowPadding
{
  /**
   * Creates a new instance of the WindowPadding class.
   *
   * @param int $topPadding The top padding.
   * @param int $rightPadding The right padding.
   * @param int $bottomPadding The bottom padding.
   * @param int $leftPadding The left padding.
   */
  public function __construct(
    protected int $topPadding = 0,
    protected int $rightPadding = 0,
    protected int $bottomPadding = 0,
    protected int $leftPadding = 0
  )
  {
  }

  /**
   * Returns the top padding.
   *
   * @return int The top padding.
   */
  public function getTopPadding(): int
  {
    return $this->topPadding;
  }

  /**
   * Sets the top padding.
   *
   * @param int $topPadding The top padding.
   * @return void
   */
  public function setTopPadding(int $topPadding): void
  {
    $this->topPadding = $topPadding;
  }

  /**
   * Returns the right padding.
   *
   * @return int The right padding.
   */
  public function getRightPadding(): int
  {
    return $this->rightPadding;
  }

  /**
   * Sets the right padding.
   *
   * @param int $rightPadding The right padding.
   * @return void
   */
  public function setRightPadding(int $rightPadding): void
  {
    $this->rightPadding = $rightPadding;
  }

  /**
   * Returns the bottom padding.
   *
   * @return int The bottom padding.
   */
  public function getBottomPadding(): int
  {
    return $this->bottomPadding;
  }

  /**
   * Sets the bottom padding.
   *
   * @param int $bottomPadding The bottom padding.
   * @return void
   */
  public function setBottomPadding(int $bottomPadding): void
  {
    $this->bottomPadding = $bottomPadding;
  }

  /**
   * Returns the left padding.
   *
   * @return int The left padding.
   */
  public function getLeftPadding(): int
  {
    return $this->leftPadding;
  }

  /**
   * Sets the left padding.
   *
   * @param int $leftPadding The left padding.
   * @return void
   */
  public function setLeftPadding(int $leftPadding): void
  {
    $this->leftPadding = $leftPadding;
  }
}