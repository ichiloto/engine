<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * AccountBalanceWindow is the window that displays the player's account balance.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Windows
 */
class AccountBalancePanel extends Window
{
  /**
   * The width of the window.
   */
  protected const int WIDTH = 30;
  /**
   * The height of the window.
   */
  protected const int HEIGHT = 3;
  /**
   * The width of the content.
   */
  protected int $contentWidth = 28;

  /**
   * AccountBalanceWindow constructor.
   *
   * @param Vector2 $position The position of the window.
   * @param BorderPackInterface $borderPack The border pack to use.
   */
  public function __construct(
    Vector2 $position,
    BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    $title = config(ProjectConfig::class, 'vocab.currency.name', 'Gold');
    parent::__construct(
      $title,
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $borderPack
    );

    $this->contentWidth = self::WIDTH - 4;
  }

  /**
   * Sets the amount of currency to display.
   *
   * @param int $amount The amount of currency to display.
   * @return void
   */
  public function setAmount(int $amount): void
  {
    $symbol = config(ProjectConfig::class, 'vocab.currency.symbol', 'G');
    $paddedAmount = sprintf("%{$this->contentWidth}s", "{$amount} {$symbol}");
    $this->setContent([$paddedAmount]);
    $this->render();
  }
}