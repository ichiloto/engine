<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows;

use Ichiloto\Engine\Core\Menu\EquipmentMenu\EquipmentMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the equipment info panel.
 *
 * @package Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows
 */
class EquipmentInfoPanel extends Window
{
  /**
   * Creates a new instance of the equipment info panel.
   *
   * @param EquipmentMenu $equipmentMenu The equipment menu.
   * @param Rect $area The area of the info panel.
   * @param BorderPackInterface $borderPack The border pack of the info panel.
   */
  public function __construct(
    protected EquipmentMenu $equipmentMenu,
    Rect $area,
    BorderPackInterface $borderPack
  )
  {
    parent::__construct(
      'Info',
      '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack
    );
  }

  /**
   * Sets the text of the info panel.
   *
   * @param string $text The text to set.
   */
  public function setText(string $text): void
  {
    $lines = explode("\n", $text);
    $lineCount = count($lines);

    if ($lineCount === 1) {
      $lines[] = '';
    }

    if ($lineCount > 2) {
      $lines = array_slice($lines, 0, 2);
    }

    $this->setContent($lines);
    $this->render();
  }
}