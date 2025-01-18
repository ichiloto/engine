<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Core\Interfaces\CanEquate;
use Stringable;

/**
 * Represents a 2D vector.
 *
 * @package Ichiloto\Engine\Core
 */
class Vector2 implements CanCompare, Stringable
{
  /**
   * @var string The hash of the vector.
   */
  protected(set) string $hash = '';

  /**
   * Vector2 constructor.
   *
   * @param float $x The x coordinate.
   * @param float $y The y coordinate.
   */
  public function __construct(
    public float $x = 0 {
      get {
        return $this->x;
      }
      set {
        $this->x = $value;
      }
    },
    public float $y = 0 {
      get {
        return $this->y;
      }
      set {
        $this->y = $value;
      }
    }
  )
  {
    $this->hash = md5(__CLASS__) . '.' . md5($this->x . '.' . $this->y);
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
    return new self($original->x, $original->y);
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
   * Subtracts the specified vectors.
   *
   * @param self ...$vectors The vectors to subtract.
   * @return self Returns the difference of the specified vectors.
   */
  public static function difference(self ...$vectors): self
  {
    $difference = new self();

    foreach ($vectors as $vector) {
      $vector->subtract($difference);
    }

    return $difference;
  }

  /**
   * Adds the specified vector to this vector.
   *
   * @param self $other The vector to add.
   */
  public function add(self $other): void
  {
    $this->x = $this->x + $other->x;
    $this->y = $this->y + $other->y;
  }

  /**
   * Subtracts the specified vector from this vector.
   *
   * @param self $other The vector to subtract.
   */
  public function subtract(self $other): void
  {
    $this->x = $this->x - $other->x;
    $this->y = $this->y - $other->y;
  }

  /**
   * Multiplies the specified vector with this vector.
   *
   * @param self $other The vector to multiply.
   */
  public function multiply(self $other): void
  {
    $this->x = $this->x * $other->x;
    $this->y = $this->y * $other->y;
  }

  /**
   * Divides the specified vector with this vector.
   *
   * @param self $other The vector to divide.
   */
  public function divide(self $other): void
  {
    $this->x = $this->x / $other->x;
    $this->y = $this->y / $other->y;
  }

  /**
   * @inheritDoc
   */
  public function compareTo(CanCompare $other): int
  {
    return $this->hash <=> $other->hash;
  }

  /**
   * @inheritDoc
   */
  public function greaterThan(CanCompare $other): bool
  {
    return $this->compareTo($other) > 0;
  }

  /**
   * @inheritDoc
   */
  public function greaterThanOrEqual(CanCompare $other): bool
  {
    return $this->compareTo($other) >= 0;
  }

  /**
   * @inheritDoc
   */
  public function lessThan(CanCompare $other): bool
  {
    return $this->compareTo($other) < 0;
  }

  /**
   * @inheritDoc
   */
  public function lessThanOrEqual(CanCompare $other): bool
  {
    return $this->compareTo($other) <= 0;
  }

  /**
   * @inheritDoc
   */
  public function equals(CanEquate $equatable): bool
  {
    return $this->hash === $equatable->hash;
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
  public function __toString(): string
  {
    return "Vector2({$this->x}, {$this->y})";
  }

  /**
   * Creates a new Vector2 from an array.
   *
   * @param int[]|float[]|array{x: int|float, y: int|float} $array
   * @return self
   */
  public static function fromArray(array $array): self
  {
    if (array_is_list($array)) {
      [$x, $y] = $array;
      return new self($x, $y);
    }

    return new self($array['x'], $array['y']);
  }
}