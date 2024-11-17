<?php

namespace Ichiloto\Engine\IO\Console;


use InvalidArgumentException;

/**
 * Represents the cursor.
 */
final class Cursor
{
  /**
   * The cursor instance.
   *
   * @var Cursor|null
   */
  private static ?Cursor $instance = null;

  private function __construct()
  {
  }

  /**
   * Returns the cursor instance.
   *
   * @return Cursor
   */
  public static function getInstance(): Cursor
  {
    if (self::$instance === null) {
      self::$instance = new Cursor();
    }

    return self::$instance;
  }

  /**
   * Hides the cursor.
   *
   * @return void
   */
  public function hide(): void
  {
    echo "\033[?25l";
  }

  /**
   * Shows the cursor.
   *
   * @return void
   */
  public function show(): void
  {
    echo "\033[?25h";
  }

  /**
   * Moves the cursor to the specified coordinates.
   *
   * @param int $x The x coordinate.
   * @param int $y The y coordinate.
   * @return void
   * @throws InvalidArgumentException Thrown if the x or y coordinate is less than 0.
   */
  public function moveTo(int $x, int $y): void
  {
    if ($x < 0 || $y < 0) {
      throw new InvalidArgumentException('The x and y coordinates must be greater than or equal to 0.');
    }

    $x = max(1, $x);
    $y = max(1, $y);

    echo "\033[{$y};{$x}H";
  }

  /**
   * Moves the cursor up.
   *
   * @param int $amount The amount to move up.
   * @return void
   */
  public function moveUp(int $amount = 1): void
  {
    $this->validateAmount($amount);

    echo "\033[{$amount}A";
  }

  /**
   * Moves the cursor down.
   *
   * @param int $amount The amount to move down.
   * @return void
   */
  public function moveDown(int $amount = 1): void
  {
    $this->validateAmount($amount);

    echo "\033[{$amount}B";
  }

  /**
   * Moves the cursor left.
   *
   * @param int $amount The amount to move left.
   * @return void
   */
  public function moveLeft(int $amount = 1): void
  {
    $this->validateAmount($amount);

    echo "\033[{$amount}D";
  }

  /**
   * Moves the cursor right.
   *
   * @param int $amount The amount to move right.
   * @return void
   */
  public function moveRight(int $amount = 1): void
  {
    $this->validateAmount($amount);

    echo "\033[{$amount}C";
  }

  /**
   * Clears a line.
   *
   * @param int|null $x The x coordinate.
   * @param int|null $y The y coordinate.
   * @return void
   * @throws InvalidArgumentException Thrown if the x or y coordinate is less than 0.
   */
  public function clearLine(?int $x = null, ?int $y = null): void
  {
    if ($x !== null && $y !== null) {
      $this->moveTo($x, $y);
    }

    echo "\033[2K";
  }

  /**
   * Clears the line from the cursor.
   *
   * @return void
   */
  public function clearLineFromCursor(): void
  {
    echo "\033[1K";
  }

  /**
   * Validates the amount.
   *
   * @param int $amount The amount to validate.
   * @return void
   * @throws InvalidArgumentException Thrown if the amount is less than 0.
   */
  private function validateAmount(int $amount): void
  {
    if ($amount < 0) {
      throw new InvalidArgumentException('The amount must be greater than or equal to 0.');
    }
  }

  /**
   * Enables blinking.
   *
   * @return void
   */
  public function enableBlinking(): void
  {
    echo "\033[?12h";
  }

  /**
   * Disables blinking.
   *
   * @return void
   */
  public function disableBlinking(): void
  {
    echo "\033[?12l";
  }
}