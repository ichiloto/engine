<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * The can equate interface.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanEquate
{
  /**
   * Checks if the given value is equal to this value.
   *
   * @param CanEquate $equatable The value to check.
   * @return bool True if the given value is equal to this value, false otherwise.
   */
  public function equals(CanEquate $equatable): bool;

  /**
   * Checks if the given value is not equal to this value.
   *
   * @param CanEquate $equatable The value to check.
   * @return bool True if the given value is not equal to this value, false otherwise.
   */
  public function notEquals(CanEquate $equatable): bool;

  /**
   * Gets the hash of this value.
   *
   * @return string The hash of this value.
   */
  public function getHash(): string;
}