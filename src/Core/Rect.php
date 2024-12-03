<?php

namespace Ichiloto\Engine\Core;

use Stringable;

/**
 * Rect class. Represents a rectangular object.
 *
 * @package Ichiloto\Engine\Core
 */
class Rect implements Stringable
{
  /**
   * Gets the position of the rectangle.
   *
   * @return Vector2 The position of the rectangle.
   */
  public Vector2 $position {
    get {
      return new Vector2($this->x, $this->y);
    }
  }

  /**
   * Gets the size of the rectangle.
   *
   * @return Area The size of the rectangle.
   */
  public Area $size {
    get {
      return new Area($this->width, $this->height);
    }
  }

  /**
   * Creates a new rectangle.
   *
   * @param int $x      The x coordinate of the rectangle.
   * @param int $y      The y coordinate of the rectangle.
   * @param int $width  The width of the rectangle.
   * @param int $height The height of the rectangle.
   */
  public function __construct(
    protected int $x = 0,
    protected int $y = 0,
    protected int $width = 1,
    protected int $height = 1
  )
  {
  }

  /**
   * Gets the x coordinate of the rectangle.
   *
   * @return int The x coordinate of the rectangle.
   */
  public function getX(): int
  {
    return $this->x;
  }

  /**
   * Gets the y coordinate of the rectangle.
   *
   * @return int The y coordinate of the rectangle.
   */
  public function getY(): int
  {
    return $this->y;
  }

  /**
   * Gets the width of the rectangle.
   *
   * @return int The width of the rectangle.
   */
  public function getWidth(): int
  {
    return $this->width;
  }

  /**
   * Gets the height of the rectangle.
   *
   * @return int The height of the rectangle.
   */
  public function getHeight(): int
  {
    return $this->height;
  }

  /**
   * Sets the x coordinate of the rectangle.
   *
   * @param int $x The x coordinate of the rectangle.
   *
   * @return static
   */
  public function setX(int $x): static
  {
    $this->x = $x;

    return $this;
  }

  /**
   * Sets the y coordinate of the rectangle.
   *
   * @param int $y The y coordinate of the rectangle.
   *
   * @return static
   */
  public function setY(int $y): static
  {
    $this->y = $y;

    return $this;
  }

  /**
   * Sets the width of the rectangle.
   *
   * @param int $width The width of the rectangle.
   *
   * @return static
   */
  public function setWidth(int $width): static
  {
    $this->width = $width;

    return $this;
  }

  /**
   * Sets the height of the rectangle.
   *
   * @param int $height The height of the rectangle.
   *
   * @return static
   */
  public function setHeight(int $height): static
  {
    $this->height = $height;

    return $this;
  }

  /**
   * Gets the top of the rectangle.
   *
   * @return int The top of the rectangle.
   */
  public function getTop(): int
  {
    return $this->y;
  }

  /**
   * Gets the bottom of the rectangle.
   *
   * @return int The bottom of the rectangle.
   */
  public function getBottom(): int
  {
    return $this->y + $this->height;
  }

  /**
   * Gets the left side of the rectangle.
   *
   * @return int The left side of the rectangle.
   */
  public function getLeft(): int
  {
    return $this->x;
  }

  /**
   * Gets the right side of the rectangle.
   *
   * @return int The right side of the rectangle.
   */
  public function getRight(): int
  {
    return $this->x + $this->width;
  }

  /**
   * Determines whether the rectangle contains the given point.
   *
   * @param Vector2 $point The point to check.
   *
   * @return bool Returns true if the rectangle contains the given point; otherwise, false.
   */
  public function contains(Vector2 $point): bool
  {
    return $point->x >= $this->getLeft()
      && $point->x <= $this->getRight()
      && $point->y >= $this->getTop()
      && $point->y <= $this->getBottom();
  }

  /**
   * @param array{x: int, y: int, width: int, height: int} $data
   * @return Rect
   */
  public static function fromArray(array $data): Rect
  {
    return new Rect($data['x'], $data['y'], $data['width'], $data['height']);
  }

  /**
   * @inheritDoc
   */
  public function __toString(): string
  {
    return "Rect(x: {$this->x}, y: {$this->y}, width: {$this->width}, height: {$this->height})";
  }
}