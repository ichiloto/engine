<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu;

use Ichiloto\Engine\Core\Menu\MainMenu\Windows\CharacterPanel;
use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\Window;
use function Termwind\render;

class CharacterSelectionMenu extends Menu
{
  protected const int TOTAL_PANELS = 4;
  protected const int PANEL_HEIGHT = 7;
  protected array $characterPanels = [];

  protected Window $helpWindow;

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    for($index = 0; $index < self::TOTAL_PANELS; $index++) {
      $leftMargin = $this->rect->getX();
      $topMargin = $this->rect->getY() + ($index * self::PANEL_HEIGHT);
      $panel = new CharacterPanel(
        new Rect($leftMargin, $topMargin, $this->rect->getWidth(), self::PANEL_HEIGHT)
      );
      $panel->setDetails('Squall', 7, '9999 / 9999', '99 / 99');
      $this->characterPanels[$index] = $panel;
    }

    $this->helpWindow = new Window(
      '',
      'c:Cancel',
      new Vector2($this->rect->getX(), $this->rect->getY() + (self::PANEL_HEIGHT * self::TOTAL_PANELS)),
      $this->rect->getWidth(),
      4
    );
    $this->helpWindow->setContent(['Use the arrow keys to select a character.', '']);
    $this->helpWindow->render();
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    // Do nothing
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    /** @var CharacterPanel $characterPanel */
    foreach ($this->characterPanels as $characterPanel) {
      $characterPanel->render();
    }
    $this->helpWindow->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    /** @var CharacterPanel $characterPanel */
    foreach ($this->characterPanels as $characterPanel) {
      $characterPanel->erase();
    }
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // Do nothing
  }
}