<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * The CharacterPanel class. Represents the character panel.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Windows
 */
class CharacterPanel extends Window
{
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
}