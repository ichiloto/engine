<?php

namespace Ichiloto\Engine\Scenes\Title;

use Ichiloto\Engine\Core\Menu\Commands\ContinueGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\NewGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenTitleOptionsCommand;
use Ichiloto\Engine\Core\Menu\Commands\QuitGameCommand;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\SaveSlotWindow;
use Ichiloto\Engine\UI\Windows\Window;
use Override;

/**
 * The title scene.
 *
 * @package Ichiloto\Engine\Scenes\Title
 */
class TitleScene extends AbstractScene
{
  protected const int TITLE_MENU_WIDTH = 16;
  protected const int TITLE_OPTIONS_MIN_WIDTH = 34;
  protected const int TITLE_OPTIONS_HORIZONTAL_PADDING = 4;
  protected const int TITLE_OPTIONS_COLUMN_GAP = 2;
  protected const string TITLE_OPTIONS_TITLE = 'Options';
  protected const string TITLE_OPTIONS_HELP = 'Esc:Back';
  protected const int CONTINUE_MENU_WIDTH = 110;
  protected const int CONTINUE_INFO_HEIGHT = 3;
  protected const int CONTINUE_HELP_HEIGHT = 4;
  protected const int CONTINUE_SLOT_COUNT = 5;

  /**
   * The title menu.
   *
   * @var TitleMenu
   */
  protected TitleMenu $menu;
  /**
   * @var ContinueGameCommand|null The continue command entry in the title menu.
   */
  protected ?ContinueGameCommand $continueCommand = null;
  /**
   * @var Window|null The compact options window shown from the title menu.
   */
  protected ?Window $optionsWindow = null;
  /**
   * @var TitleOptionsSettingsManager|null The title options settings manager.
   */
  protected ?TitleOptionsSettingsManager $optionsManager = null;
  /**
   * @var TitleOption[] The configurable title options.
   */
  protected array $options = [];
  /**
   * @var bool Whether the title scene is currently showing the options overlay.
   */
  protected bool $showingOptions = false;
  /**
   * @var bool Whether the title scene is currently showing the continue overlay.
   */
  protected bool $showingContinueMenu = false;
  /**
   * @var int The active row inside the options overlay.
   */
  protected int $activeOptionIndex = 0;
  /**
   * @var int The active save-slot index inside the continue overlay.
   */
  protected int $activeContinueSlotIndex = 0;
  /**
   * @var SaveSlot[] The currently visible save slots.
   */
  protected array $continueSlots = [];
  /**
   * @var SaveSlotWindow[] The continue overlay slot windows.
   */
  protected array $continueSlotWindows = [];
  /**
   * @var Window|null The continue overlay prompt window.
   */
  protected ?Window $continueInfoWindow = null;
  /**
   * @var Window|null The continue overlay help window.
   */
  protected ?Window $continueHelpWindow = null;
  /**
   * @var string|null A short status message shown in the continue overlay.
   */
  protected ?string $continueStatusMessage = null;

  /**
   * The header content.
   *
   * @var string
   */
  protected string $headerContent = '';
  /**
   * The header.
   *
   * @var array
   */
  protected array $headerLines = [];
  /**
   * The header height.
   *
   * @var int
   */
  protected int $headerHeight = 0;

  /**
   * @inheritDoc
   */
  #[Override]
  public function start(): void
  {
    $gameLoader = GameLoader::getInstance($this->getGame());
    $menuHeight = 3;

    parent::start();
    $this->headerContent = graphics('System/title', false);
    $this->headerLines = explode("\n", $this->headerContent);
    $this->headerHeight = count($this->headerLines);
    $this->optionsManager = new TitleOptionsSettingsManager();
    $this->options = $this->optionsManager->getOptions();

    $leftMargin = intval((get_screen_width() - self::TITLE_MENU_WIDTH) / 2);
    $topMargin = $this->headerHeight + 2;

    $this->menu = new TitleMenu(
      $this,
      '',
      '',
      rect: new Rect($leftMargin, $topMargin, self::TITLE_MENU_WIDTH, $menuHeight)
    );
    $this->continueCommand = new ContinueGameCommand($this->menu, $gameLoader);

    $this
      ->menu
      ->addItem(new NewGameCommand($this->menu, $gameLoader))
      ->addItem($this->continueCommand)
      ->addItem(new OpenTitleOptionsCommand($this->menu))
      ->addItem(new QuitGameCommand($this->menu));

    $this->initializeOptionsWindow();
    $this->initializeContinueMenuWindows();
    $this->syncContinueAvailability();

    $this->renderHeader();
    usleep(300);
    $this->menu->render();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function update(): void
  {
    parent::update();

    if ($this->showingOptions) {
      $this->updateOptionsMenu();
      return;
    }

    if ($this->showingContinueMenu) {
      $this->updateContinueMenu();
      return;
    }

    $this->menu->update();
  }

  /**
   * Renders the header.
   *
   * @return void
   */
  public function renderHeader(): void
  {
    $headerWidth = 0;

    foreach ($this->headerLines as $line) {
      $headerWidth = max($headerWidth, mb_strlen($line));
    }

    $x = intval((get_screen_width() - $headerWidth) / 2);
    $y = 2;

    $this->camera->draw($this->headerContent, $x, $y);
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    Console::clear();
    $this->syncContinueAvailability();

    if ($this->showingContinueMenu) {
      $this->renderContinueMenu();
      return;
    }

    if ($this->showingOptions) {
      $this->renderOptionsMenu();
      return;
    }

    usleep(300);
    $this->renderHeader();
    usleep(300);
    $this->menu->render();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    Console::clear();
  }

  /**
   * Re-centers the title layout after a terminal resize.
   *
   * @param int $width The new terminal width.
   * @param int $height The new terminal height.
   * @return void
   */
  #[Override]
  public function onScreenResize(int $width, int $height): void
  {
    parent::onScreenResize($width, $height);

    if (! isset($this->menu)) {
      return;
    }

    $this->menu->setPosition(new Vector2(max(0, intdiv(get_screen_width() - self::TITLE_MENU_WIDTH, 2)), $this->headerHeight + 2));
    $this->initializeOptionsWindow();
    $this->initializeContinueMenuWindows();

    Console::clear();

    if ($this->showingContinueMenu) {
      $this->renderContinueMenu();
      return;
    }

    if ($this->showingOptions) {
      $this->renderOptionsMenu();
      return;
    }

    $this->renderHeader();
    $this->menu->render();
  }

  /**
   * Opens the compact title-screen options overlay.
   *
   * @return void
   */
  public function openOptionsMenu(): void
  {
    if (! $this->optionsManager?->isCursorMemoryEnabled()) {
      $this->activeOptionIndex = 0;
    }

    Console::clear();
    $this->initializeOptionsWindow();
    $this->showingOptions = true;
    $this->renderOptionsMenu();
  }

  /**
   * Opens the title-screen continue overlay.
   *
   * @return void
   */
  public function openContinueMenu(): void
  {
    $this->refreshContinueSlots();
    $this->activeContinueSlotIndex = $this->resolveDefaultContinueSlotIndex();
    $this->continueStatusMessage = null;
    Console::clear();
    $this->initializeContinueMenuWindows();
    $this->showingContinueMenu = true;
    $this->renderContinueMenu();
  }

  /**
   * Closes the continue overlay and restores the title screen.
   *
   * @return void
   */
  public function closeContinueMenu(): void
  {
    $this->showingContinueMenu = false;
    $this->continueStatusMessage = null;
    Console::clear();
    $this->renderHeader();
    $this->menu->render();
  }

  /**
   * Synchronizes the Continue command's disabled state with the save directory.
   *
   * @return void
   */
  public function syncContinueAvailability(): void
  {
    if (! $this->continueCommand instanceof ContinueGameCommand) {
      return;
    }

    if ($this->sceneManager->saveManager->hasSaveFiles(true)) {
      $this->continueCommand->enable();
    } else {
      $this->continueCommand->disable();
    }

    if (! $this->showingOptions && ! $this->showingContinueMenu) {
      $this->menu?->updateWindowContent();
    }
  }

  /**
   * Builds the centered options window used by the title scene.
   *
   * @return void
   */
  protected function initializeOptionsWindow(): void
  {
    ['width' => $width, 'height' => $height] = $this->resolveOptionsWindowSize();

    $this->optionsWindow = new Window(
      self::TITLE_OPTIONS_TITLE,
      self::TITLE_OPTIONS_HELP,
      new Vector2(
        max(0, intdiv(get_screen_width() - $width, 2)),
        max(0, intdiv(get_screen_height() - $height, 2))
      ),
      $width,
      $height,
      new DefaultBorderPack()
    );
  }

  /**
   * Builds the continue overlay windows used by the title scene.
   *
   * @return void
   */
  protected function initializeContinueMenuWindows(): void
  {
    $borderPack = new DefaultBorderPack();
    $width = min(self::CONTINUE_MENU_WIDTH, get_screen_width());
    $menuHeight = self::CONTINUE_INFO_HEIGHT + self::CONTINUE_HELP_HEIGHT + (SaveSlotWindow::HEIGHT * self::CONTINUE_SLOT_COUNT);
    $leftMargin = max(0, intdiv(get_screen_width() - $width, 2));
    $topMargin = max(0, intdiv(get_screen_height() - $menuHeight, 2));

    $this->continueInfoWindow = new Window(
      'Continue',
      '',
      new Vector2($leftMargin, $topMargin),
      $width,
      self::CONTINUE_INFO_HEIGHT,
      $borderPack
    );

    $this->continueSlotWindows = [];

    for ($slotIndex = 0; $slotIndex < self::CONTINUE_SLOT_COUNT; $slotIndex++) {
      $this->continueSlotWindows[] = new SaveSlotWindow(
        new Vector2(
          $leftMargin,
          $topMargin + self::CONTINUE_INFO_HEIGHT + ($slotIndex * SaveSlotWindow::HEIGHT)
        ),
        $width,
        $borderPack
      );
    }

    $this->continueHelpWindow = new Window(
      'Help',
      '',
      new Vector2(
        $leftMargin,
        $topMargin + self::CONTINUE_INFO_HEIGHT + (SaveSlotWindow::HEIGHT * self::CONTINUE_SLOT_COUNT)
      ),
      $width,
      self::CONTINUE_HELP_HEIGHT,
      $borderPack
    );
  }

  /**
   * Handles the options overlay input loop.
   *
   * @return void
   */
  protected function updateOptionsMenu(): void
  {
    $vertical = Input::getAxis(AxisName::VERTICAL);

    if ($vertical > 0) {
      $this->activeOptionIndex = wrap($this->activeOptionIndex + 1, 0, count($this->options));
      $this->renderOptionsMenu();
      return;
    }

    if ($vertical < 0) {
      $this->activeOptionIndex = wrap($this->activeOptionIndex - 1, 0, count($this->options));
      $this->renderOptionsMenu();
      return;
    }

    if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      $this->closeOptionsMenu();
      return;
    }

    $horizontal = Input::getAxis(AxisName::HORIZONTAL);

    if ($horizontal > 0) {
      $this->changeActiveOption(1);
      return;
    }

    if ($horizontal < 0) {
      $this->changeActiveOption(-1);
      return;
    }

    if (Input::isButtonDown('confirm')) {
      $this->activateSelectedOption();
    }
  }

  /**
   * Handles the continue overlay input loop.
   *
   * @return void
   */
  protected function updateContinueMenu(): void
  {
    $vertical = Input::getAxis(AxisName::VERTICAL);

    if ($vertical > 0) {
      $this->activeContinueSlotIndex = wrap($this->activeContinueSlotIndex + 1, 0, count($this->continueSlots) - 1);
      $this->continueStatusMessage = null;
      $this->renderContinueMenu();
      return;
    }

    if ($vertical < 0) {
      $this->activeContinueSlotIndex = wrap($this->activeContinueSlotIndex - 1, 0, count($this->continueSlots) - 1);
      $this->continueStatusMessage = null;
      $this->renderContinueMenu();
      return;
    }

    if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      $this->closeContinueMenu();
      return;
    }

    if (Input::isButtonDown('confirm')) {
      $this->loadActiveContinueSlot();
    }
  }

  /**
   * Closes the options overlay and restores the title screen.
   *
   * @return void
   */
  protected function closeOptionsMenu(): void
  {
    $this->showingOptions = false;
    Console::clear();
    $this->renderHeader();
    $this->menu->render();
  }

  /**
   * Renders the continue overlay.
   *
   * @return void
   */
  protected function renderContinueMenu(): void
  {
    $this->refreshContinueSlots();
    $this->continueInfoWindow?->setContent([
      'Choose a save file to continue from.',
    ]);
    $this->continueInfoWindow?->render();

    foreach ($this->continueSlotWindows as $slotIndex => $slotWindow) {
      $slotWindow->setSlot(
        $this->continueSlots[$slotIndex] ?? SaveSlot::empty($slotIndex + 1, ''),
        $slotIndex === $this->activeContinueSlotIndex
      );
      $slotWindow->render();
    }

    $statusText = $this->continueStatusMessage ?? 'Choose a file.';
    $this->continueHelpWindow?->setContent([
      $statusText,
      'Enter loads. Esc returns.',
    ]);
    $this->continueHelpWindow?->render();
  }

  /**
   * Applies a directional change to the selected option.
   *
   * @param int $step The direction to move. Use `1` for next and `-1` for previous.
   * @return void
   */
  protected function changeActiveOption(int $step): void
  {
    $option = $this->options[$this->activeOptionIndex] ?? null;

    if (! $option instanceof TitleOption || ! $this->optionsManager instanceof TitleOptionsSettingsManager) {
      return;
    }

    $this->optionsManager->cycle($option, $step);
    $this->renderOptionsMenu();
  }

  /**
   * Confirms the currently selected row in the options overlay.
   *
   * @return void
   */
  protected function activateSelectedOption(): void
  {
    if ($this->isBackRowSelected()) {
      $this->closeOptionsMenu();
      return;
    }

    $this->changeActiveOption(1);
  }

  /**
   * Returns whether the Back row currently has focus.
   *
   * @return bool True when the Back row is selected.
   */
  protected function isBackRowSelected(): bool
  {
    return $this->activeOptionIndex === count($this->options);
  }

  /**
   * Renders the title-screen options overlay.
   *
   * @return void
   */
  protected function renderOptionsMenu(): void
  {
    if (
      ! $this->optionsManager instanceof TitleOptionsSettingsManager ||
      ! $this->optionsWindow instanceof Window
    ) {
      return;
    }

    ['width' => $windowWidth, 'height' => $windowHeight] = $this->resolveOptionsWindowSize();
    ['label' => $labelWidth, 'value' => $valueWidth] = $this->resolveOptionsColumnWidths();
    $availableWidth = max(0, $windowWidth - self::TITLE_OPTIONS_HORIZONTAL_PADDING);
    $content = [];

    foreach ($this->options as $index => $option) {
      $prefix = $index === $this->activeOptionIndex ? '>' : ' ';
      $label = TerminalText::padRight($option->label, $labelWidth);
      $value = TerminalText::padLeft($this->optionsManager->getCurrentChoiceLabel($option), $valueWidth);
      $line = TerminalText::padRight(
        "{$prefix} {$label}" . str_repeat(' ', self::TITLE_OPTIONS_COLUMN_GAP) . $value,
        $availableWidth
      );

      if ($index === $this->activeOptionIndex) {
        $line = SelectionStyle::apply($line);
      }

      $content[] = $line;
    }

    $backPrefix = $this->isBackRowSelected() ? '>' : ' ';
    $backLine = TerminalText::padRight("{$backPrefix} Back", $availableWidth);

    if ($this->isBackRowSelected()) {
      $backLine = SelectionStyle::apply($backLine);
    }

    $content[] = $backLine;

    $this->optionsWindow->setContent(array_pad($content, $windowHeight - 2, ''));
    $this->optionsWindow->render();
  }

  /**
   * Resolves the centered options window size from the current labels and values.
   *
   * @return array{width: int, height: int} The window width and height.
   */
  protected function resolveOptionsWindowSize(): array
  {
    ['label' => $labelWidth, 'value' => $valueWidth] = $this->resolveOptionsColumnWidths();
    $contentWidth = 2 + $labelWidth + self::TITLE_OPTIONS_COLUMN_GAP + $valueWidth;
    $width = max(
      self::TITLE_OPTIONS_MIN_WIDTH,
      $contentWidth + self::TITLE_OPTIONS_HORIZONTAL_PADDING,
      TerminalText::displayWidth(self::TITLE_OPTIONS_TITLE) + 3,
      TerminalText::displayWidth(self::TITLE_OPTIONS_HELP) + 3,
    );
    $height = count($this->options) + 3;

    return [
      'width' => min(get_screen_width(), $width),
      'height' => min(get_screen_height(), $height),
    ];
  }

  /**
   * Resolves the label and value widths used by the options rows.
   *
   * @return array{label: int, value: int} The label and value column widths.
   */
  protected function resolveOptionsColumnWidths(): array
  {
    $labelWidth = TerminalText::displayWidth('Back');
    $valueWidth = 0;

    if (! $this->optionsManager instanceof TitleOptionsSettingsManager) {
      return ['label' => $labelWidth, 'value' => $valueWidth];
    }

    foreach ($this->options as $option) {
      $labelWidth = max($labelWidth, TerminalText::displayWidth($option->label));

      foreach ($this->optionsManager->getChoiceLabels($option) as $choiceLabel) {
        $valueWidth = max($valueWidth, TerminalText::displayWidth($choiceLabel));
      }
    }

    return ['label' => $labelWidth, 'value' => $valueWidth];
  }

  /**
   * Reloads the slot data displayed in the continue overlay.
   *
   * @return void
   */
  protected function refreshContinueSlots(): void
  {
    $this->continueSlots = $this->sceneManager->saveManager->getSaveSlots(self::CONTINUE_SLOT_COUNT);
    $this->activeContinueSlotIndex = clamp(
      $this->activeContinueSlotIndex,
      0,
      max(0, count($this->continueSlots) - 1)
    );
  }

  /**
   * Returns the slot index that should receive focus when opening Continue.
   *
   * @return int The default slot index.
   */
  protected function resolveDefaultContinueSlotIndex(): int
  {
    foreach ($this->continueSlots as $slotIndex => $slot) {
      if (! $slot->isEmpty) {
        return $slotIndex;
      }
    }

    return 0;
  }

  /**
   * Loads the selected save slot into the game scene.
   *
   * @return void
   */
  protected function loadActiveContinueSlot(): void
  {
    $slot = $this->continueSlots[$this->activeContinueSlotIndex] ?? null;

    if (! $slot instanceof SaveSlot || $slot->isEmpty) {
      $this->continueStatusMessage = 'That file is empty.';
      $this->renderContinueMenu();
      return;
    }

    $gameLoader = GameLoader::getInstance($this->getGame());
    $sceneManager = $this->sceneManager;
    $currentScene = $sceneManager->loadScene(\Ichiloto\Engine\Scenes\Game\GameScene::class)->currentScene;

    if (! $currentScene instanceof \Ichiloto\Engine\Scenes\Game\GameScene) {
      return;
    }

    $currentScene->configure($gameLoader->loadSavedGame($slot->path));
  }
}
