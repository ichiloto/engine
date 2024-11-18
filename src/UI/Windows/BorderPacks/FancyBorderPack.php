<?php

namespace Ichiloto\Engine\UI\Windows\BorderPacks;

use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;

/**
 * Class FancyBorderPack. The fancy border pack.
 *
 * @package Ichiloto\Engine\UI\Windows\BorderPacks
 */
class FancyBorderPack implements BorderPackInterface
{

  /**
   * @inheritDoc
   */
  public static function getTopLeftCorner(): string
  {
    return 'o';
  }

  /**
   * @inheritDoc
   */
  public static function getTopRightCorner(): string
  {
    return 'o';
  }

  /**
   * @inheritDoc
   */
  public static function getBottomLeftCorner(): string
  {
    return 'o';
  }

  /**
   * @inheritDoc
   */
  public static function getBottomRightCorner(): string
  {
    return 'o';
  }

  /**
   * @inheritDoc
   */
  public static function getHorizontalBorder(): string
  {
    return '─';
  }

  /**
   * @inheritDoc
   */
  public static function getVerticalBorder(): string
  {
    return '│';
  }

  /**
   * @inheritDoc
   */
  public static function getTopHorizontalConnector(): string
  {
    return '─';
  }

  /**
   * @inheritDoc
   */
  public static function getBottomHorizontalConnector(): string
  {
    return '─';
  }

  /**
   * @inheritDoc
   */
  public static function getLeftVerticalConnector(): string
  {
    return '│';
  }

  /**
   * @inheritDoc
   */
  public static function getRightVerticalConnector(): string
  {
    return '│';
  }

  /**
   * @inheritDoc
   */
  public static function getCenterConnector(): string
  {
    return 'o';
  }
}