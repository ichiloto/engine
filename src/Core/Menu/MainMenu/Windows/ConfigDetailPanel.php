<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSetting;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Shows the short description area beneath the config settings list.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Windows
 */
class ConfigDetailPanel extends Window
{
  /**
   * @param Rect $rect The rectangle occupied by the panel.
   * @param BorderPackInterface $borderPack The border pack to use.
   */
  public function __construct(
    Rect $rect,
    BorderPackInterface $borderPack = new DefaultBorderPack(),
  )
  {
    parent::__construct(
      'Description',
      '',
      new Vector2($rect->getX(), $rect->getY()),
      $rect->getWidth(),
      $rect->getHeight(),
      $borderPack
    );
  }

  /**
   * Displays the selected setting description and the latest status message.
   *
   * @param MainMenuSetting $setting The selected setting.
   * @param string|null $statusMessage An optional status message to append.
   * @return void
   */
  public function showSetting(MainMenuSetting $setting, ?string $statusMessage = null): void
  {
    $availableWidth = max(0, $this->width - 4);
    $lines = explode("\n", wrap_text($setting->description, max(1, $availableWidth)));

    if ($statusMessage) {
      $lines[] = $statusMessage;
    }

    $lines = array_slice(array_pad($lines, $this->height - 2, ''), 0, $this->height - 2);
    $this->setContent($lines);
    $this->render();
  }
}
