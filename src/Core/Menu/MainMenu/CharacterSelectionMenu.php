<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu;

use Ichiloto\Engine\Core\Menu\MainMenu\Windows\CharacterPanel;
use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Windows\Window;
use function Termwind\render;

class CharacterSelectionMenu extends Menu
{
  /**
   * The total number of panels.
   */
  protected const int TOTAL_PANELS = 4;
  /**
   * The height of the panel.
   */
  protected const int PANEL_HEIGHT = 7;
  /**
   * @var CharacterPanel[] The character panels.
   */
  protected array $characterPanels = [];
  /**
   * @var Window The help window.
   */
  protected Window $helpWindow;

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $scene = $this->getScene();
    assert($scene instanceof GameScene);

    $membersArray = $scene->party->members->toArray();

    for($index = 0; $index < self::TOTAL_PANELS; $index++) {
      $leftMargin = $this->rect->getX();
      $topMargin = $this->rect->getY() + ($index * self::PANEL_HEIGHT);
      $panel = new CharacterPanel(
        new Rect($leftMargin, $topMargin, $this->rect->getWidth(), self::PANEL_HEIGHT)
      );

      if ($member = $membersArray[$index] ?? null) {
        assert($member instanceof Character);
        $panel->setDetails(
          $member->name,
          $member->level,
          "{$member->stats->currentHp} / {$member->stats->totalHp}",
          "{$member->stats->currentMp} / {$member->stats->totalMp}");
      }
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