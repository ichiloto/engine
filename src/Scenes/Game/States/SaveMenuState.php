<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\UI\Windows\SaveSlotPage;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\IO\Saves\SaveFeedback;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\MenuCanvasState;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\SaveLoadMenuPresentation;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\SaveSlotWindow;
use Ichiloto\Engine\UI\Windows\SaveSlotMenuLayout;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/**
 * Displays the in-game save screen and writes snapshot data to `.iedata` files.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class SaveMenuState extends GameSceneState implements CanRender, CanvasProviderInterface
{
  use MenuCanvasState;

  protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas
  {
    return SaveLoadMenuPresentation::compose($this->slots, $this->activeSlotIndex, $theme,
      $this->menuInfoText, $this->statusMessage, $time, title: Vocabulary::getTerm('game.save', 'Save'),
      prompt: get_message('prompt.save', 'Which file would you like to save to?'), statusColor: $this->statusColor);
  }

  protected const int SAVE_MENU_WIDTH = 110;
  protected const int SAVE_INFO_HEIGHT = 3;
  protected const int SAVE_HELP_HEIGHT = 4;
  protected ?SaveSlotMenuLayout $saveViewport = null;

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
  protected string $statusColor = 'accent';

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->resetMenuPresentation();
    $this->statusMessage = null;
    $this->statusColor = 'accent';
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->saveViewport = null;
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
    $this->saveViewport = $this->createSaveMenuLayout();
    $this->leftMargin = $this->saveViewport->left;
    $this->topMargin = $this->saveViewport->top;
  }

  protected function createSaveMenuLayout(): SaveSlotMenuLayout
  {
    return new SaveSlotMenuLayout(get_screen_width(), get_screen_height(), count($this->slots),
      self::SAVE_MENU_WIDTH, self::SAVE_INFO_HEIGHT, self::SAVE_HELP_HEIGHT);
  }

  /**
   * Creates the save-menu windows.
   *
   * @return void
   */
  protected function initializeUI(): void
  {
    $borderPack = new DefaultBorderPack();
    $this->calculateMargins();
    $layout = $this->saveViewport;
    $width = $layout->width;

    $this->infoWindow = new Window(
      Vocabulary::getTerm('game.save', 'Save'),
      '',
      new Vector2($this->leftMargin, $this->topMargin),
      $width,
      $layout->infoHeight,
      $borderPack,
      heightPolicy: WindowHeightPolicy::FIXED
    );

    $this->slotWindows = [];

    for ($slotIndex = 0; $slotIndex < $layout->visibleCount; $slotIndex++) {
      $this->slotWindows[] = new SaveSlotWindow(
        new Vector2(
          $this->leftMargin,
          $this->topMargin + $layout->infoHeight + ($slotIndex * $layout->slotHeight)
        ),
        $width,
        $borderPack,
        $layout->slotHeight
      );
    }

    $this->helpWindow = new Window(
      'Help',
      '',
      new Vector2(
        $this->leftMargin,
        $this->topMargin + $layout->infoHeight + ($layout->slotHeight * $layout->visibleCount)
      ),
      $width,
      $layout->helpHeight,
      $borderPack,
      heightPolicy: WindowHeightPolicy::FIXED
    );
  }

  /**
   * Reloads the current save-slot summaries from disk.
   *
   * @return void
   */
  protected function refreshSlots(): void
  {
    $this->slots = $this->getGameScene()->sceneManager->saveManager->getSaveSlots();
    $maxIndex = max(0, count($this->slots) - 1);
    $this->activeSlotIndex = clamp($this->activeSlotIndex, 0, $maxIndex);
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    if ($this->saveViewport != $this->createSaveMenuLayout()) {
      Console::clear();
      $this->initializeUI();
    }
    [$first, $last] = $this->saveViewport->getVisibleRange($this->activeSlotIndex);
    $this->infoWindow?->setTitle(Vocabulary::getTerm('game.save', 'Save') . '  '
      . sprintf('%d-%d / %d', $first + 1, $last, count($this->slots)));
    $this->infoWindow?->setContent([
      get_message('prompt.save', 'Which file would you like to save to?'),
    ]);
    $this->infoWindow?->render();

    foreach ($this->slotWindows as $visibleIndex => $slotWindow) {
      $slotIndex = $first + $visibleIndex;
      if (!isset($this->slots[$slotIndex])) {
        $slotWindow->erase();
        continue;
      }
      $slotWindow->setSlot(
        $this->slots[$slotIndex],
        $slotIndex === $this->activeSlotIndex
      );
      $slotWindow->render();
    }

    $statusText = $this->statusMessage ?? 'Choose a file.';
    $hints = $this->getSaveControlHelp();
    $helpHeight = $this->helpWindow?->getHeight() ?? self::SAVE_HELP_HEIGHT;
    $this->helpWindow?->setTitle($helpHeight <= 2 ? $statusText : 'Help');
    $this->helpWindow?->setHelp($hints);
    $page = $this->menuInfoText->getPage($this->statusMessage === null
      ? ($this->slots[$this->activeSlotIndex] ?? null)?->getDescription() ?? 'Choose a file.' : '',
      $this->statusMessage, max(1, ($this->helpWindow?->getWidth() ?? 80) - 4));
    $this->helpWindow?->setContent($page->lines);
    $this->helpWindow?->render();
  }

  protected function getSaveControlHelp(): string
  {
    $hints = [ActionHints::resolve('confirm', 'Save'), ActionHints::resolve('back', 'Back')];
    return implode('  ', array_map(static fn($hint): string =>
      ($hint->control?->label ?? 'Unbound') . ': ' . $hint->label, $hints));
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
    if (Input::isButtonDown('info')) { $this->menuInfoText->advance(); $this->render(); return; }
    foreach (['menu_page_previous' => -1, 'menu_page_next' => 1] as $action => $direction) {
      if (Input::isButtonDown($action)) {
        $this->activeSlotIndex = SaveSlotPage::getAdjacentIndex(
          count($this->slots), $this->activeSlotIndex, $direction, $this->saveViewport?->visibleCount ?? SaveSlotPage::SIZE);
        $this->statusMessage = null;
        $this->render();
        return;
      }
    }
    $vertical = Input::getAxis(AxisName::VERTICAL);

    if ($vertical > 0) {
      $this->activeSlotIndex = wrap($this->activeSlotIndex + 1, 0, count($this->slots) - 1);
      play_sound(SystemSound::CURSOR);
      $this->statusMessage = null;
      $this->render();
      return;
    }

    if ($vertical < 0) {
      $this->activeSlotIndex = wrap($this->activeSlotIndex - 1, 0, count($this->slots) - 1);
      play_sound(SystemSound::CURSOR);
      $this->statusMessage = null;
      $this->render();
      return;
    }

    if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      play_sound(SystemSound::CANCEL);
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

    try {
      $savedSlot = $this->getGameScene()->sceneManager->saveManager->save($this->getGameScene(), $slot->slot);
    } catch (ActiveEventSaveException $exception) {
      $this->statusMessage = SaveFeedback::describeWriteFailure($exception);
      $this->statusColor = 'decrease';
      Debug::warn('Manual save unavailable: ' . $exception->getMessage());
      $this->render();
      alert($this->statusMessage, get_message('save.unavailable_title', '%1 Unavailable', Vocabulary::getTerm('game.save', 'Save')));
      return;
    } catch (RuntimeException $exception) {
      Debug::warn(sprintf('Manual save failed: %s', $exception->getMessage()));
      $this->statusMessage = SaveFeedback::describeWriteFailure($exception);
      $this->statusColor = 'decrease';
      $this->render();
      alert($this->statusMessage, get_message('save.unavailable_title', '%1 Unavailable', Vocabulary::getTerm('game.save', 'Save')));
      return;
    }

    $this->getGameScene()->getGame()->audioManager->playSystemSound(SystemSound::SAVE);
    $this->refreshSlots();
    $this->statusMessage = sprintf('Saved to File %d.', $savedSlot->slot);
    $this->statusColor = 'increase';
    $this->render();
    alert($this->statusMessage, get_message('save.complete_title', '%1 Complete', Vocabulary::getTerm('game.save', 'Save')));
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
