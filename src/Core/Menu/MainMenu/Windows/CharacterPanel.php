<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Interfaces\CanFocus;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * The CharacterPanel class. Represents the character panel.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Windows
 */
class CharacterPanel extends Window implements CanFocus
{
  /**
   * @var bool Whether this panel currently has navigation focus.
   */
  protected bool $isFocused = false;
  /**
   * @var bool Whether this panel is marked as the first swap selection.
   */
  protected bool $isMarked = false;

  /**
   * CharacterPanel constructor.
   *
   * @param Rect $rect The rectangle.
   * @param BorderPackInterface $borderPack The border pack.
   */
  public function __construct(
    Rect $rect,
    BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    parent::__construct(
      '',
      '',
      new Vector2($rect->getX(), $rect->getY()),
      $rect->getWidth(),
      $rect->getHeight(),
      $borderPack
    );
  }

  /**
   * @param string $name
   * @param int $level
   * @param string $hp
   * @param string $mp
   * @return void
   */
  public function setDetails(
    string $name,
    int $level,
    string $hp,
    string $mp
  ): void
  {
    $leftMargin = 18;
    $this->setContent([
      sprintf("%{$leftMargin}s%s", ' ', $name),
      sprintf("%{$leftMargin}sLv %12d", ' ', $level),
      sprintf("%{$leftMargin}sHP %12s", ' ', $hp),
      sprintf("%{$leftMargin}sMP %12s", ' ', $mp),
      ''
    ]);
    $this->render();
  }

  /**
   * Clears the panel content when no party member occupies the slot.
   *
   * @return void
   */
  public function clearDetails(): void
  {
    $this->setContent(array_fill(0, $this->height - 2, ''));
    $this->render();
  }

  /**
   * @inheritdoc
   */
  public function focus(): void
  {
    $this->isFocused = true;
    $this->applyHighlightState();
  }

  /**
   * @inheritdoc
   */
  public function blur(): void
  {
    $this->isFocused = false;
    $this->applyHighlightState();
  }

  /**
   * Marks the panel as the locked-in source selection.
   *
   * @return void
   */
  public function mark(): void
  {
    $this->isMarked = true;
    $this->applyHighlightState();
  }

  /**
   * Removes the locked-in source selection marker.
   *
   * @return void
   */
  public function unmark(): void
  {
    $this->isMarked = false;
    $this->applyHighlightState();
  }

  /**
   * Applies the current focus/mark visual state to the panel.
   *
   * @return void
   */
  protected function applyHighlightState(): void
  {
    $this->setForegroundColor(
      $this->isFocused || $this->isMarked
        ? SelectionStyle::resolveColor()
        : Color::WHITE
    );
  }
}
