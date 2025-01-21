<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu;

use Ichiloto\Engine\Core\Menu\MainMenu\Windows\CharacterPanel;
use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

/**
 * Represents the character selection menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu
 */
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
   * @var int The selected panel index.
   */
  protected int $activePanelIndex = 0;
  /**
   * @var Character|null The active character.
   */
  public ?Character $activeCharacter {
    get {
      return $this->partyMembers[$this->activePanelIndex] ?? null;
    }
  }
  /**
   * @var Character[] The party members.
   */
  protected array $partyMembers = [];

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $scene = $this->getScene();
    assert($scene instanceof GameScene);

    $this->partyMembers = $scene->party->members->toArray();

    for($index = 0; $index < self::TOTAL_PANELS; $index++) {
      $leftMargin = $this->rect->getX();
      $topMargin = $this->rect->getY() + ($index * self::PANEL_HEIGHT);
      $panel = new CharacterPanel(
        new Rect($leftMargin, $topMargin, $this->rect->getWidth(), self::PANEL_HEIGHT)
      );

      if ($member = $this->partyMembers[$index] ?? null) {
        assert($member instanceof Character);
        $panel->setDetails(
          $member->name,
          $member->level,
          "{$member->effectiveStats->currentHp} / {$member->effectiveStats->totalHp}",
          "{$member->effectiveStats->currentMp} / {$member->effectiveStats->totalMp}");
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

  /**
   * Selects the previous character.
   *
   * @return void
   */
  public function selectPrevious(): void
  {
    $this->characterPanels[$this->activePanelIndex]?->blur();
    $index = wrap($this->activePanelIndex - 1, 0, self::TOTAL_PANELS - 1);
    $this->selectPanelByIndex($index);
    $this->characterPanels[$this->activePanelIndex]?->focus();
  }

  /**
   * Selects the next character.
   *
   * @return void
   */
  public function selectNext(): void
  {
    $this->characterPanels[$this->activePanelIndex]?->blur();
    $index = wrap($this->activePanelIndex + 1, 0, self::TOTAL_PANELS - 1);
    $this->selectPanelByIndex($index);
    $this->characterPanels[$this->activePanelIndex]?->focus();
  }

  /**
   * Selects the character by index.
   *
   * @param int $index The index of the character to select.
   * @return void
   */
  public function selectPanelByIndex(int $index): void
  {
    $this->activePanelIndex = $index;
  }

  /**
   * @inheritdoc
   */
  public function focus(): void
  {
    $this->selectPanelByIndex(0);
    $this->characterPanels[$this->activePanelIndex]?->focus();
    parent::focus();
  }

  /**
   * @inheritdoc
   */
  public function blur(): void
  {
    $this->characterPanels[$this->activePanelIndex]?->blur();
    $this->selectPanelByIndex(-1);
    parent::blur();
  }
}