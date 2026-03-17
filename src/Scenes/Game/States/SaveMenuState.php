<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\SaveSlotWindow;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Displays the in-game save screen and writes snapshot data to `.iedata` files.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class SaveMenuState extends GameSceneState implements CanRender
{
  protected const int SAVE_MENU_WIDTH = 110;
  protected const int SAVE_INFO_HEIGHT = 3;
  protected const int SAVE_HELP_HEIGHT = 4;
  protected const int SAVE_SLOT_COUNT = 5;

  /**
   * @var int The centered left margin.
   */
  protected int $leftMargin = 0;
  /**
   * @var int The centered top margin.
   */
  protected int $topMargin = 0;
  /**
   * @var int The active save-slot index.
   */
  protected int $activeSlotIndex = 0;
  /**
   * @var SaveSlot[] The currently displayed save slots.
   */
  protected array $slots = [];
  /**
   * @var SaveSlotWindow[] The visible save-slot windows.
   */
  protected array $slotWindows = [];
  /**
   * @var Window|null The top prompt window.
   */
  protected ?Window $infoWindow = null;
  /**
   * @var Window|null The bottom help and status window.
   */
  protected ?Window $helpWindow = null;
  /**
   * @var string|null The latest short status message.
   */
  protected ?string $statusMessage = null;

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->calculateMargins();
    $this->initializeUI();
    $this->refreshSlots();
    $this->render();
  }

  /**
   * Calculates the centered menu origin.
   *
   * @return void
   */
  protected function calculateMargins(): void
  {
    $menuWidth = min(self::SAVE_MENU_WIDTH, get_screen_width());
    $menuHeight = self::SAVE_INFO_HEIGHT + self::SAVE_HELP_HEIGHT + (SaveSlotWindow::HEIGHT * self::SAVE_SLOT_COUNT);

    $this->leftMargin = max(0, intdiv(get_screen_width() - $menuWidth, 2));
    $this->topMargin = max(0, intdiv(get_screen_height() - $menuHeight, 2));
  }

  /**
   * Creates the save-menu windows.
   *
   * @return void
   */
  protected function initializeUI(): void
  {
    $borderPack = new DefaultBorderPack();
    $width = min(self::SAVE_MENU_WIDTH, get_screen_width());

    $this->infoWindow = new Window(
      'Save',
      '',
      new Vector2($this->leftMargin, $this->topMargin),
      $width,
      self::SAVE_INFO_HEIGHT,
      $borderPack
    );

    $this->slotWindows = [];

    for ($slotIndex = 0; $slotIndex < self::SAVE_SLOT_COUNT; $slotIndex++) {
      $this->slotWindows[] = new SaveSlotWindow(
        new Vector2(
          $this->leftMargin,
          $this->topMargin + self::SAVE_INFO_HEIGHT + ($slotIndex * SaveSlotWindow::HEIGHT)
        ),
        $width,
        $borderPack
      );
    }

    $this->helpWindow = new Window(
      'Help',
      '',
      new Vector2(
        $this->leftMargin,
        $this->topMargin + self::SAVE_INFO_HEIGHT + (SaveSlotWindow::HEIGHT * self::SAVE_SLOT_COUNT)
      ),
      $width,
      self::SAVE_HELP_HEIGHT,
      $borderPack
    );
  }

  /**
   * Reloads the current save-slot summaries from disk.
   *
   * @return void
   */
  protected function refreshSlots(): void
  {
    $this->slots = $this->getGameScene()->sceneManager->saveManager->getSaveSlots(self::SAVE_SLOT_COUNT);
    $maxIndex = max(0, count($this->slots) - 1);
    $this->activeSlotIndex = clamp($this->activeSlotIndex, 0, $maxIndex);
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->infoWindow?->setContent([
      'Which file would you like to save to?',
    ]);
    $this->infoWindow?->render();

    foreach ($this->slotWindows as $slotIndex => $slotWindow) {
      $slotWindow->setSlot(
        $this->slots[$slotIndex] ?? SaveSlot::empty($slotIndex + 1, ''),
        $slotIndex === $this->activeSlotIndex
      );
      $slotWindow->render();
    }

    $statusText = $this->statusMessage ?? 'Choose a file.';
    $this->helpWindow?->setContent([
      $statusText,
      'Enter saves. Esc returns.',
    ]);
    $this->helpWindow?->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    Console::clear();
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    $vertical = Input::getAxis(AxisName::VERTICAL);

    if ($vertical > 0) {
      $this->activeSlotIndex = wrap($this->activeSlotIndex + 1, 0, count($this->slots) - 1);
      $this->statusMessage = null;
      $this->render();
      return;
    }

    if ($vertical < 0) {
      $this->activeSlotIndex = wrap($this->activeSlotIndex - 1, 0, count($this->slots) - 1);
      $this->statusMessage = null;
      $this->render();
      return;
    }

    if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      $this->setState($this->getGameScene()->mainMenuState);
      return;
    }

    if (Input::isButtonDown('confirm')) {
      $this->saveToActiveSlot();
    }
  }

  /**
   * Writes the current game snapshot into the active slot.
   *
   * @return void
   */
  protected function saveToActiveSlot(): void
  {
    $slot = $this->slots[$this->activeSlotIndex] ?? null;

    if (! $slot instanceof SaveSlot) {
      return;
    }

    $savedSlot = $this->getGameScene()->sceneManager->saveManager->save($this->getGameScene(), $slot->slot);
    $this->refreshSlots();
    $this->statusMessage = sprintf('Saved to File %d.', $savedSlot->slot);
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->refreshSlots();
    $this->render();
  }
}
