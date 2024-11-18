<?php

namespace Ichiloto\Engine\UI\Windows\Interfaces;


/**
 * The interface BorderPackInterface.
 */
interface BorderPackInterface
{
  /**
   * Gets the top left corner.
   *
   * @return string Returns the top left corner.
   */
  public static function getTopLeftCorner(): string;

  /**
   * Gets the top right corner.
   *
   * @return string Returns the top right corner.
   */
  public static function getTopRightCorner(): string;

  /**
   * Gets the bottom left corner.
   *
   * @return string Returns the bottom left corner.
   */
  public static function getBottomLeftCorner(): string;

  /**
   * Gets the bottom right corner.
   *
   * @return string Returns the bottom right corner.
   */
  public static function getBottomRightCorner(): string;

  /**
   * Gets the horizontal border.
   *
   * @return string Returns the horizontal border.
   */
  public static function getHorizontalBorder(): string;

  /**
   * Gets the vertical border.
   *
   * @return string Returns the vertical border.
   */
  public static function getVerticalBorder(): string;

  /**
   * Gets the top horizontal connector.
   *
   * @return string Returns the top horizontal connector.
   */
  public static function getTopHorizontalConnector(): string;

  /**
   * Gets the bottom horizontal connector.
   *
   * @return string Returns the bottom horizontal connector.
   */
  public static function getBottomHorizontalConnector(): string;

  /**
   * Gets the left vertical connector.
   *
   * @return string Returns the left vertical connector.
   */
  public static function getLeftVerticalConnector(): string;

  /**
   * Gets the right vertical connector.
   *
   * @return string Returns the right vertical connector.
   */
  public static function getRightVerticalConnector(): string;

  /**
   * Gets the top left connector.
   *
   * @return string Returns the top left connector.
   */
  public static function getCenterConnector(): string;
}