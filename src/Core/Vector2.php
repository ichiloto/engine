<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Core\Interfaces\CanEquate;
use Stringable;

class Vector2 implements CanCompare, Stringable
{
  /**
   * Vector2 constructor.
   *
   * @param float $x The x coordinate.
   * @param float $y The y coordinate.
   */
  public function __construct(protected float $x = 0, protected float $y = 0)
  {
  }

  /**
   * Shortcut for Vector2(0, 0).
   *
   * @return Vector2 Returns a new Vector2(0, 0).
   */
  public static function zero(): self
  {
    return new self(0, 0);
  }

  /**
   * Shortcut for Vector2(1, 1).
   *
   * @return Vector2 Returns a new Vector2(1, 1).
   */
  public static function one(): self
  {
    return new self(1, 1);
  }

  /**
   * Shortcut for Vector2(-1, 0).
   *
   * @return Vector2 Returns a new Vector2(-1, 0).
   */
  public static function left(): self
  {
    return new self(-1, 0);
  }

  /**
   * Shortcut for Vector2(1, 0).
   *
   * @return Vector2 Returns a new Vector2(1, 0).
   */
  public static function right(): self
  {
    return new self(1, 0);
  }

  /**
   * Gets a clone of the specified vector.
   *
   * @param self $original The original vector.
   * @return self Returns a clone of the specified vector.
   */
  public static function getClone(self $original): self
  {
    return new self($original->getX(), $original->getY());
  }

  /**
   * Shortcut for Vector2(0, -1).
   *
   * @return Vector2 Returns a new Vector2(0, -1).
   */
  public static function up(): self
  {
    return new self(0, -1);
  }

  /**
   * Shortcut for Vector2(0, 1).
   *
   * @return Vector2 Returns a new Vector2(0, 1).
   */
  public static function down(): self
  {
    return new self(0, 1);
  }


  /**
   * Adds the specified vectors.
   *
   * @param self ...$vectors The vectors to add.
   * @return self Returns the sum of the specified vectors.
   */
  public static function sum(self ...$vectors): self
  {
    $sum = new self();

    foreach ($vectors as $vector) {
      $sum->add($vector);
    }

    return $sum;
  }

  /**
   * Gets the x coordinate.
   *
   * @return float The x coordinate.
   */
  public function getX(): float
  {
    return $this->x;
  }

  /**
   * Gets the y coordinate.
   *
   * @return float The y coordinate.
   */
  public function getY(): float
  {
    return $this->y;
  }

  /**
   * Sets the x coordinate.
   *
   * @param float $x The x coordinate.
   */
  public function setX(float $x): void
  {
    $this->x = $x;
  }

  /**
   * Sets the y coordinate.
   *
   * @param float $y The y coordinate.
   */
  public function setY(float $y): void
  {
    $this->y = $y;
  }

  /**
   * Adds the specified vector to this vector.
   *
   * @param self $other The vector to add.
   */
  public function add(self $other): void
  {
    $this->setX($this->getX() + $other->getX());
    $this->setY($this->getY() + $other->getY());
  }

  /**
   * Subtracts the specified vector from this vector.
   *
   * @param self $other The vector to subtract.
   */
  public function subtract(self $other): void
  {
    $this->setX($this->getX() - $other->getX());
    $this->setY($this->getY() - $other->getY());
  }

  /**
   * Multiplies the specified vector with this vector.
   *
   * @param self $other The vector to multiply.
   */
  public function multiply(self $other): void
  {
    $this->setX($this->getX() * $other->getX());
    $this->setY($this->getY() * $other->getY());
  }

  /**
   * Divides the specified vector with this vector.
   *
   * @param self $other The vector to divide.
   */
  public function divide(self $other): void
  {
    $this->setX($this->getX() / $other->getX());
    $this->setY($this->getY() / $other->getY());
  }

  /**
   * @inheritDoc
   */
  public function compareTo(CanCompare $other): int
  {
    // TODO: Implement compareTo() method.
  }

  /**
   * @inheritDoc
   */
  public function greaterThan(CanCompare $other): bool
  {
    // TODO: Implement greaterThan() method.
  }

  /**
   * @inheritDoc
   */
  public function greaterThanOrEqual(CanCompare $other): bool
  {
    // TODO: Implement greaterThanOrEqual() method.
  }

  /**
   * @inheritDoc
   */
  public function lessThan(CanCompare $other): bool
  {
    // TODO: Implement lessThan() method.
  }

  /**
   * @inheritDoc
   */
  public function lessThanOrEqual(CanCompare $other): bool
  {
    // TODO: Implement lessThanOrEqual() method.
  }

  /**
   * @inheritDoc
   */
  public function equals(CanEquate $equatable): bool
  {
    return $this->getHash() === $equatable->getHash();
  }

  /**
   * @inheritDoc
   */
  public function notEquals(CanEquate $equatable): bool
  {
    return !$this->equals($equatable);
  }

  /**
   * @inheritDoc
   */
  public function getHash(): string
  {
    return uniqid(md5(__CLASS__) . '.' . md5($this->x . '.' . $this->y));
  }

  /**
   * @inheritDoc
   */
  public function __toString(): string
  {
    return "Vector2({$this->x}, {$this->y})";
  }
}