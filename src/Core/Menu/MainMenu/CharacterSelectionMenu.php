<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu;

use Ichiloto\Engine\Core\Menu\MainMenu\Windows\CharacterPanel;
use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the character selection menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu
 */
class CharacterSelectionMenu extends Menu
{
  /**
   * The default help text shown beneath the character panels.
   */
  public const string DEFAULT_HELP_TEXT = 'Use the arrow keys to select a character.';
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
   * @var int|null The panel currently marked as the source selection.
   */
  protected ?int $markedPanelIndex = null;
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
   * @var string The help text currently shown beneath the panels.
   */
  protected string $helpText = self::DEFAULT_HELP_TEXT;

  /**
   * Returns the active panel index.
   *
   * @return int The active panel index.
   */
  public function getActivePanelIndex(): int
  {
    return $this->activePanelIndex;
  }

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $scene = $this->getScene();
    assert($scene instanceof GameScene);

    for($index = 0; $index < self::TOTAL_PANELS; $index++) {
      $leftMargin = $this->rect->getX();
      $topMargin = $this->rect->getY() + ($index * self::PANEL_HEIGHT);
      $panel = new CharacterPanel(
        new Rect($leftMargin, $topMargin, $this->rect->getWidth(), self::PANEL_HEIGHT)
      );
      $this->characterPanels[$index] = $panel;
    }

    $this->helpWindow = new Window(
      '',
      'c:Cancel',
      new Vector2($this->rect->getX(), $this->rect->getY() + (self::PANEL_HEIGHT * self::TOTAL_PANELS)),
      $this->rect->getWidth(),
      4
    );
    $this->refreshMembers();
    $this->setHelpText($this->helpText);
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
    $index = $this->getAdjacentSelectablePanelIndex(-1);

    if ($index !== null) {
      $this->focusPanelByIndex($index);
    }
  }

  /**
   * Selects the next character.
   *
   * @return void
   */
  public function selectNext(): void
  {
    $index = $this->getAdjacentSelectablePanelIndex(1);

    if ($index !== null) {
      $this->focusPanelByIndex($index);
    }
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
   * Focuses the specified character panel.
   *
   * @param int $index The panel index to focus.
   * @return void
   */
  public function focusPanelByIndex(int $index): void
  {
    ($this->characterPanels[$this->activePanelIndex] ?? null)?->blur();
    $this->selectPanelByIndex($index);
    ($this->characterPanels[$this->activePanelIndex] ?? null)?->focus();
  }

  /**
   * Marks a panel as the current swap source.
   *
   * @param int $index The panel index to mark.
   * @return void
   */
  public function markPanelByIndex(int $index): void
  {
    $this->clearMarkedPanel();
    $this->markedPanelIndex = $index;
    ($this->characterPanels[$index] ?? null)?->mark();
  }

  /**
   * Clears the current swap-source marker, if any.
   *
   * @return void
   */
  public function clearMarkedPanel(): void
  {
    if ($this->markedPanelIndex === null) {
      return;
    }

    ($this->characterPanels[$this->markedPanelIndex] ?? null)?->unmark();
    $this->markedPanelIndex = null;
  }

  /**
   * Updates the help text shown beneath the selection panels.
   *
   * @param string $text The help text to display.
   * @return void
   */
  public function setHelpText(string $text): void
  {
    $this->helpText = $text;
    $this->helpWindow->setContent([$text, '']);
    $this->helpWindow->render();
  }

  /**
   * Refreshes the panel content from the current party order.
   *
   * @return void
   */
  public function refreshMembers(): void
  {
    $scene = $this->getScene();
    assert($scene instanceof GameScene);

    $this->partyMembers = $scene->party->members->toArray();

    foreach ($this->characterPanels as $index => $panel) {
      $member = $this->partyMembers[$index] ?? null;

      if ($member instanceof Character) {
        $panel->setDetails(
          $member->name,
          $member->level,
          "{$member->effectiveStats->currentHp} / {$member->effectiveStats->totalHp}",
          "{$member->effectiveStats->currentMp} / {$member->effectiveStats->totalMp}"
        );
        continue;
      }

      $panel->clearDetails();
    }

    if (! in_array($this->activePanelIndex, $this->getSelectablePanelIndexes(), true)) {
      $this->activePanelIndex = $this->getSelectablePanelIndexes()[0] ?? -1;
    }
  }

  /**
   * @inheritdoc
   */
  public function focus(): void
  {
    $this->clearMarkedPanel();
    $this->activePanelIndex = $this->getSelectablePanelIndexes()[0] ?? -1;
    ($this->characterPanels[$this->activePanelIndex] ?? null)?->focus();
    parent::focus();
  }

  /**
   * @inheritdoc
   */
  public function blur(): void
  {
    ($this->characterPanels[$this->activePanelIndex] ?? null)?->blur();
    $this->clearMarkedPanel();
    $this->selectPanelByIndex(-1);
    parent::blur();
  }

  /**
   * Returns the selectable panel indexes for the current party size.
   *
   * @return int[] The selectable panel indexes.
   */
  protected function getSelectablePanelIndexes(): array
  {
    return array_keys(array_filter(
      $this->partyMembers,
      static fn(mixed $member): bool => $member instanceof Character
    ));
  }

  /**
   * Returns the next selectable panel index in the requested direction.
   *
   * @param int $step The navigation step. Use `1` for next and `-1` for previous.
   * @return int|null The adjacent selectable index, if any.
   */
  protected function getAdjacentSelectablePanelIndex(int $step): ?int
  {
    $selectableIndexes = $this->getSelectablePanelIndexes();

    if (empty($selectableIndexes)) {
      return null;
    }

    $currentPosition = array_search($this->activePanelIndex, $selectableIndexes, true);

    if (! is_int($currentPosition)) {
      return $selectableIndexes[0];
    }

    $nextPosition = wrap($currentPosition + $step, 0, count($selectableIndexes) - 1);

    return $selectableIndexes[$nextPosition];
  }
}
