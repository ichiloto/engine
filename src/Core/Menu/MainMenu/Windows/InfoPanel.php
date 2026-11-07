<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * InfoPanel is the window that displays information about the currently selected item and helpful tips.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Windows
 */
class InfoPanel extends Window
{
  private string $description = '';
  private ?string $tutorialHint = null;

  /**
   * InfoPanel constructor.
   *
   * @param Vector2 $position The position of the window.
   */
  public function __construct(
    Vector2 $position,
    BorderPackInterface $borderPack = new DefaultBorderPack(),
    int $width = 110,
    int $height = 3
  )
  {
    parent::__construct('Info', '', $position, $width, $height, $borderPack);
  }

  /**
   * Sets the text of the window.
   *
   * @param string $text The text to set.
   * @return void
   */
  public function setText(string $text): void
  {
    $this->description = $text;
    $this->refreshText();
  }

  public function getDescription(): string
  {
    return $this->description;
  }

  public function showTutorialHint(?string $hint): void
  {
    if ($hint === $this->tutorialHint) {
      return;
    }
    $this->tutorialHint = $hint;
    $this->refreshText();
  }

  private function refreshText(): void
  {
    $this->setContent($this->tutorialHint === null
      ? [$this->description]
      : TerminalText::wrapToWidth($this->tutorialHint, $this->getContentWidth()));
    $this->render();
  }
}
