<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * The can compare interface.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanCompare extends CanEquate
{
  /**
   * Compares this object with the specified object for order.
   *
   * @param CanCompare $other The object to be compared.
   * @return int
   */
  public function compareTo(CanCompare $other): int;

  /**
   * Compares this object with the specified object for order.
   *
   * @param CanCompare $other The object to be compared.
   * @return bool True if this object is greater than the specified object, false otherwise.
   */
  public function greaterThan(CanCompare $other): bool;

  /**
   * Compares this object with the specified object for order.
   *
   * @param CanCompare $other The object to be compared.
   * @return bool True if this object is greater than or equal to the specified object, false otherwise.
   */
  public function greaterThanOrEqual(CanCompare $other): bool;

  /**
   * Compares this object with the specified object for order.
   *
   * @param CanCompare $other The object to be compared.
   * @return bool True if this object is less than the specified object, false otherwise.
   */
  public function lessThan(CanCompare $other): bool;

  /**
   * Compares this object with the specified object for order.
   *
   * @param CanCompare $other The object to be compared.
   * @return bool True if this object is less than or equal to the specified object, false otherwise.
   */
  public function lessThanOrEqual(CanCompare $other): bool;
}