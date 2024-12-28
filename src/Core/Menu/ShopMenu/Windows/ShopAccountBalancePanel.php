<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Windows;

use Ichiloto\Engine\Core\Menu\ShopMenu\ShopMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Represents the shop account balance panel.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Windows
 */
class ShopAccountBalancePanel extends Window
{
  /**
   * ShopAccountBalancePanel constructor.
   *
   * @param ShopMenu $shopMenu
   * @param Rect $area
   * @param BorderPackInterface $borderPack
   */
  public function __construct(
    protected ShopMenu $shopMenu,
    Rect $area,
    BorderPackInterface $borderPack
  )
  {
    parent::__construct(
      config(ProjectConfig::class, 'vocab.currency.name', 'Gold'),
      '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack
    );
  }

  /**
   * Set the balance of the shop account.
   *
   * @param int $newBalance
   */
  public function setBalance(int $newBalance): void
  {
    $span = $this->width - 4;
    $symbol = config(ProjectConfig::class, 'vocab.currency.symbol', 'G');
    $content = [
      sprintf("%{$span}s", "{$newBalance} {$symbol}")
    ];
    $this->setContent($content);
    $this->render();
  }
}