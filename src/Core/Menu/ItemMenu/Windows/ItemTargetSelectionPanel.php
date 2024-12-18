<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Windows;

use Ichiloto\Engine\Core\Interfaces\CanFocus;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

/**
 * The window that displays the target selection panel.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Windows
 */
class ItemTargetSelectionPanel extends Window implements CanFocus
{
  /**
   * @var int The index of the active character.
   */
  protected(set) int $activeIndex = -1;
  /**
   * @var Character|null The active character.
   */
  public ?Character $activeCharacter {
    get {
      return $this->targets[$this->activeIndex] ?? null;
    }
  }

  /**
   * @var Character[] The characters to display.
   */
  public array $targets = [] {
    get {
      return $this->targets;
    }

    set {
      $this->targets = $value;
      $this->updateContent();
    }
  }
  /**
   * @var int The total number of targets.
   */
  public int $totalTargets = 0 {
    get {
      return $this->totalTargets;
    }
  }

  /**
   * Creates a new instance of the ItemTargetSelectionPanel.
   *
   * @param ItemMenuState $state The state of the item menu.
   * @param Rect $area The area of the window.
   * @param BorderPackInterface $borderPack The border pack of the window.
   */
  public function __construct(
    protected ItemMenuState $state,
    Rect $area,
    BorderPackInterface $borderPack,
  )
  {
    parent::__construct(
      'Name',
      '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack,
    );
  }

  /**
   * Sets the targets to display.
   *
   * @param Character[] $targets The targets to display.
   *
   * @return void
   */
  public function setTargets(array $targets): void
  {
    $this->targets = $targets;
    $this->totalTargets = count($this->targets);
    $this->updateContent();
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = array_fill(0, $this->height - 2, '');
    foreach ($this->targets as $index => $character) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $content[$index] = sprintf(" %s %s", $prefix, $character->name);
    }

    $this->setContent($content);
    $this->render();
  }

  /**
   * @inheritdoc
   */
  public function focus(): void
  {
    $this->activeIndex = 0;
    $this->setTargets($this->state->getGameScene()->party->members->toArray());
  }

  /**
   * @inheritdoc
   */
  public function blur(): void
  {
    $this->activeIndex = -1;
    $this->setTargets([]);
  }

  /**
   * Sets the active item index.
   *
   * @param int $index The index of the active item.
   *
   * @return void
   */
  public function setActiveItemIndex(int $index): void
  {
    $this->activeIndex = $index;
    $this->updateContent();
  }
}