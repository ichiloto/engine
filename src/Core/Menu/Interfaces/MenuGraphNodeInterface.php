<?php

namespace Ichiloto\Engine\Core\Menu\Interfaces;

use Ichiloto\Engine\UI\Interfaces\FocusTargetInterface;

/**
 * MenuGraphNodeInterface is an interface implemented by all classes that represent a menu graph node.
 *
 * @package Ichiloto\Engine\Core\Menu\Interfaces
 */
interface MenuGraphNodeInterface extends FocusTargetInterface
{
  /**
   * Returns the top node.
   *
   * @return MenuGraphNodeInterface|null The top node.
   */
  public function getTop(): ?MenuGraphNodeInterface;

  /**
   * Sets the top node.
   *
   * @param MenuGraphNodeInterface|null $top The top node.
   * @return void
   */
  public function setTop(?MenuGraphNodeInterface $top): void;

  /**
   * Returns the right node.
   *
   * @return MenuGraphNodeInterface|null The right node.
   */
  public function getRight(): ?MenuGraphNodeInterface;

  /**
   * Sets the right node.
   *
   * @param MenuGraphNodeInterface|null $right The right node.
   * @return void
   */
  public function setRight(?MenuGraphNodeInterface $right): void;

  /**
   * Returns the bottom node.
   *
   * @return MenuGraphNodeInterface|null The bottom node.
   */
  public function getBottom(): ?MenuGraphNodeInterface;

  /**
   * Sets the bottom node.
   *
   * @param MenuGraphNodeInterface|null $bottom The bottom node.
   * @return void
   */
  public function setBottom(?MenuGraphNodeInterface $bottom): void;

  /**
   * Returns the left node.
   *
   * @return MenuGraphNodeInterface|null The left node.
   */
  public function getLeft(): ?MenuGraphNodeInterface;

  /**
   * Sets the left node.
   *
   * @param MenuGraphNodeInterface|null $left The left node.
   * @return void
   */
  public function setLeft(?MenuGraphNodeInterface $left): void;

  /**
   * Returns the menu.
   *
   * @return MenuInterface|null The menu.
   */
  public function getMenu(): ?MenuInterface;
}