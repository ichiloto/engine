<?php

namespace Ichiloto\Engine\Core\Menu;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuItemInterface;

/**
 * Represents a menu item.
 *
 * @package Ichiloto\Engine\Core\Menu
 */
abstract class MenuItem implements Interfaces\MenuItemInterface
{
  /**
   * Creates a new menu item instance.
   *
   * @param string $label The label of the menu item.
   * @param string $description The description of the menu item.
   * @param string $icon The icon of the menu item.
   */
  public function __construct(
    protected MenuInterface $menu,
    protected string $label,
    protected string $description,
    protected string $icon = ''
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    // Override this method in a subclass to implement the execution logic.
    return self::SUCCESS;
  }

  /**
   * @inheritDoc
   */
  public function __toString(): string
  {
    $output = '';

    if ($this->icon !== '') {
      $output .= "$this->icon ";
    }

    $output .= $this->label;

    return $output;
  }

  /**
   * @inheritDoc
   */
  public function getLabel(): string
  {
    return $this->label;
  }

  /**
   * @inheritDoc
   */
  public function setLabel(string $label): void
  {
    $this->label = $label;
  }

  /**
   * @inheritDoc
   */
  public function getIcon(): string
  {
    return $this->icon;
  }

  /**
   * @inheritDoc
   */
  public function setIcon(string $icon): void
  {
    $this->icon = $icon;
  }

  /**
   * @inheritDoc
   */
  public function getDescription(): string
  {
    return $this->description;
  }

  /**
   * @inheritDoc
   */
  public function setDescription(string $description): void
  {
    $this->description = $description;
  }
}