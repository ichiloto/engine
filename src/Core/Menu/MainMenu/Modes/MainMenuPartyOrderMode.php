<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Modes;

use Ichiloto\Engine\Core\Menu\MainMenu\CharacterSelectionMenu;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Handles party reordering inside the main menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Modes
 */
class MainMenuPartyOrderMode extends MainMenuMode
{
  /**
   * @var int|null The index of the member chosen as the swap source.
   */
  protected ?int $sourceIndex = null;
  /**
   * @var string The default help text shown while waiting for the first selection.
   */
  protected const string DEFAULT_HELP_TEXT = 'Select the party member to move.';
  /**
   * @var string The default info text shown while waiting for the first selection.
   */
  protected const string DEFAULT_INFO_TEXT = 'Choose the party member to reposition.';

  /**
   * Returns the character selection menu.
   *
   * @return CharacterSelectionMenu The character selection menu.
   */
  protected function getCharacterSelectionMenu(): CharacterSelectionMenu
  {
    return $this->mainMenuState->characterSelectionMenu;
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->sourceIndex = null;
    $this->getCharacterSelectionMenu()->refreshMembers();
    $this->getCharacterSelectionMenu()->setHelpText(self::DEFAULT_HELP_TEXT);
    $this->mainMenuState->infoPanel?->setText(self::DEFAULT_INFO_TEXT);
    $this->getCharacterSelectionMenu()->focus();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->sourceIndex = null;
    $this->getCharacterSelectionMenu()->setHelpText(CharacterSelectionMenu::DEFAULT_HELP_TEXT);
    $this->getCharacterSelectionMenu()->blur();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->handleNavigation();
    $this->handleActions();
  }

  /**
   * Handles vertical navigation between party members.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if ($v > 0) {
      $this->getCharacterSelectionMenu()->selectNext();
      return;
    }

    if ($v < 0) {
      $this->getCharacterSelectionMenu()->selectPrevious();
    }
  }

  /**
   * Handles confirm/cancel actions for the reorder flow.
   *
   * @return void
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown('cancel')) {
      $this->handleCancel();
    }

    if (Input::isButtonDown('confirm')) {
      $this->handleConfirm();
    }
  }

  /**
   * Handles the cancel action, backing out of the current reorder step.
   *
   * @return void
   */
  protected function handleCancel(): void
  {
    if ($this->sourceIndex !== null) {
      $this->sourceIndex = null;
      $this->getCharacterSelectionMenu()->clearMarkedPanel();
      $this->getCharacterSelectionMenu()->setHelpText(self::DEFAULT_HELP_TEXT);
      $this->mainMenuState->infoPanel?->setText(self::DEFAULT_INFO_TEXT);
      return;
    }

    $this->mainMenuState->setMode(new MainMenuCommandSelectionMode($this->mainMenuState));
  }

  /**
   * Handles the confirm action for choosing the swap source/destination.
   *
   * @return void
   */
  protected function handleConfirm(): void
  {
    $activeCharacter = $this->getCharacterSelectionMenu()->activeCharacter;
    $activeIndex = $this->getCharacterSelectionMenu()->getActivePanelIndex();

    if (! $activeCharacter instanceof Character || $activeIndex < 0) {
      return;
    }

    if ($this->sourceIndex === null) {
      $this->sourceIndex = $activeIndex;
      $this->getCharacterSelectionMenu()->markPanelByIndex($activeIndex);
      $this->getCharacterSelectionMenu()->setHelpText(sprintf('Choose where to move %s.', $activeCharacter->name));
      $this->mainMenuState->infoPanel?->setText(sprintf('Select the destination for %s.', $activeCharacter->name));
      return;
    }

    if ($activeIndex === $this->sourceIndex) {
      $this->sourceIndex = null;
      $this->getCharacterSelectionMenu()->clearMarkedPanel();
      $this->getCharacterSelectionMenu()->setHelpText(self::DEFAULT_HELP_TEXT);
      $this->mainMenuState->infoPanel?->setText(self::DEFAULT_INFO_TEXT);
      return;
    }

    $party = $this->mainMenuState->getGameScene()->party;
    $members = $party->members->toArray();
    $sourceMember = $members[$this->sourceIndex] ?? null;
    $destinationMember = $members[$activeIndex] ?? null;

    if (! $sourceMember instanceof Character || ! $destinationMember instanceof Character) {
      return;
    }

    $party->swapMembers($this->sourceIndex, $activeIndex);
    $this->sourceIndex = null;
    $this->getCharacterSelectionMenu()->clearMarkedPanel();
    $this->getCharacterSelectionMenu()->refreshMembers();
    $this->getCharacterSelectionMenu()->focusPanelByIndex($activeIndex);
    $this->getCharacterSelectionMenu()->setHelpText(
      sprintf('%s and %s swapped. Select another party member to move.', $sourceMember->name, $destinationMember->name)
    );
    $this->mainMenuState->infoPanel?->setText(
      sprintf('%s moved to position %d.', $sourceMember->name, $activeIndex + 1)
    );
  }
}
