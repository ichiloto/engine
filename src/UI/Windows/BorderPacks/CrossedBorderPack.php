<?php

namespace Ichiloto\Engine\UI\Windows\BorderPacks;

use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;

/**
 * Class CrossedBorderPack. The crossed border pack.
 *
 * @package Ichiloto\Engine\UI\Windows\BorderPacks
 */
class CrossedBorderPack implements BorderPackInterface
{
  /**
   * @inheritDoc
   */
  public static function getTopLeftCorner(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getTopRightCorner(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getBottomLeftCorner(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getBottomRightCorner(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getHorizontalBorder(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getVerticalBorder(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getTopHorizontalConnector(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getBottomHorizontalConnector(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getLeftVerticalConnector(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getRightVerticalConnector(): string
  {
    return 'x';
  }

  /**
   * @inheritDoc
   */
  public static function getCenterConnector(): string
  {
    return 'x';
  }
}