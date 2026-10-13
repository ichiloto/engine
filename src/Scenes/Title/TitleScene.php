<?php

namespace Ichiloto\Engine\Scenes\Title;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\Commands\ContinueGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\NewGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenTitleOptionsCommand;
use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\Core\Menu\Commands\QuitGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\ShowCreditsCommand;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\SaveSlotWindow;
use Ichiloto\Engine\UI\Windows\SaveSlotMenuLayout;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Override;
use Throwable;

/**
 * The title scene.
 *
 * @package Ichiloto\Engine\Scenes\Title
 */
class TitleScene extends AbstractScene implements CanvasProviderInterface
{
  use TitleCanvasPresentation;
  use TitleCredits;
  protected const int TITLE_MENU_WIDTH = 16;
  protected const int TITLE_OPTIONS_MIN_WIDTH = 34;
  protected const int TITLE_OPTIONS_HORIZONTAL_PADDING = 4;
  protected const int TITLE_OPTIONS_COLUMN_GAP = 2;
  protected const int TITLE_OPTIONS_STATUS_ROWS = 2;
  protected const string TITLE_OPTIONS_TITLE = 'Options';
  protected const string TITLE_OPTIONS_HELP = 'Esc:Back';
  protected const int CONTINUE_MENU_WIDTH = 110;
  protected const int CONTINUE_INFO_HEIGHT = 3;
  protected const int CONTINUE_HELP_HEIGHT = 4;

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
   * @var GameSetting[] The configurable title options.
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
   * @var SaveSlot[] The complete read-only load catalog.
   */
  protected array $continueSlots = [];
  /**
   * @var SaveSlotWindow[] The continue overlay slot windows.
   */
  protected array $continueSlotWindows = [];
  protected ?SaveSlotMenuLayout $continueViewport = null;
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
    Console::clear();
    $this->headerContent = $this->loadHeaderContent();
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
      ->addItem(new OpenTitleOptionsCommand($this->menu));

    // The credits entry only appears when the project authors them.
    if (ShowCreditsCommand::projectHasCredits()) {
      $this->menu->addItem(new ShowCreditsCommand($this->menu));
    }

    $this->menu->addItem(new QuitGameCommand($this->menu));

    $this->initializeOptionsWindow();
    $this->initializeContinueMenuWindows();
    $this->resetTitleInteractionState();
    $this->syncContinueAvailability();
    $this->resetTitlePresentation();

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
    $this->advanceTitlePresentation();

    if ($this->creditsPlayback !== null) {
      $this->updateCredits();
      return;
    }

    if (Input::isButtonDown('info') && ($this->showingOptions || $this->showingContinueMenu)) {
      $this->titleInfoText?->advance();
      return;
    }

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
   * Loads the title header art, falling back to a built-in banner when the
   * project has not provided a `Graphics/System/title.txt` asset yet.
   *
   * @return string
   */
  protected function loadHeaderContent(): string
  {
    try {
      return graphics('System/title', false);
    } catch (Throwable $exception) {
      Debug::warn($exception->getMessage());

      return $this->buildFallbackHeader();
    }
  }

  /**
   * Builds a simple title banner for projects that do not ship custom art yet.
   *
   * @return string
   */
  protected function buildFallbackHeader(): string
  {
    $title = strtoupper((string) (config(ProjectConfig::class, 'title', 'ICHILOTO') ?? 'ICHILOTO'));
    $lineWidth = max(strlen($title) + 12, 32);
    $border = str_repeat('=', $lineWidth);

    return implode("\n", [
      $border,
      str_pad($title, $lineWidth, ' ', STR_PAD_BOTH),
      str_pad('FORGE YOUR LEGEND', $lineWidth, ' ', STR_PAD_BOTH),
      $border,
    ]);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function getBackgroundMusic(): ?string
  {
    return $this->getConfiguredBackgroundMusic('audio.bgm.title');
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    Console::clear();
    $this->setTitlePresentationSuspended(false);
    $this->syncContinueAvailability();

    if ($this->creditsPlayback !== null) { return; }

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
    $this->setTitlePresentationSuspended(true);
    Console::clear();
  }

  #[Override]
  public function stop(): void
  {
    $this->resetCredits();
    $this->stopTitlePresentation();
    parent::stop();
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

    if ($this->creditsPlayback !== null) { return; }

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
    $this->optionStatusMessage = null;
    $this->advanceTitlePresentation();
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
    $this->advanceTitlePresentation();
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
    $this->advanceTitlePresentation();
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
      $this->getOptionsMenuTitle(),
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
    $layout = $this->continueViewport = $this->createContinueMenuLayout();
    $width = $layout->width;
    $infoHeight = $layout->infoHeight;
    $helpHeight = $layout->helpHeight;
    $visibleCount = $layout->visibleCount;
    $leftMargin = $layout->left;
    $topMargin = $layout->top;

    $this->continueInfoWindow = new Window(
      $this->getContinueMenuTitle(),
      '',
      new Vector2($leftMargin, $topMargin),
      $width,
      $infoHeight,
      $borderPack,
      heightPolicy: WindowHeightPolicy::FIXED
    );

    $this->continueSlotWindows = [];

    for ($slotIndex = 0; $slotIndex < $visibleCount; $slotIndex++) {
      $this->continueSlotWindows[] = new SaveSlotWindow(
        new Vector2(
          $leftMargin,
          $topMargin + $infoHeight + ($slotIndex * SaveSlotWindow::HEIGHT)
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
        $topMargin + $infoHeight + (SaveSlotWindow::HEIGHT * $visibleCount)
      ),
      $width,
      $helpHeight,
      $borderPack,
      heightPolicy: WindowHeightPolicy::FIXED
    );
  }

  protected function createContinueMenuLayout(): SaveSlotMenuLayout
  {
    return new SaveSlotMenuLayout(get_screen_width(), get_screen_height(), count($this->continueSlots),
      self::CONTINUE_MENU_WIDTH, self::CONTINUE_INFO_HEIGHT, self::CONTINUE_HELP_HEIGHT);
  }

  /**
   * Handles the options overlay input loop.
   *
   * @return void
   */
  protected function updateOptionsMenu(): void
  {
    $vertical = Input::getAxis(AxisName::VERTICAL);
    $audioManager = $this->getGame()->audioManager;

    if ($vertical > 0) {
      $this->activeOptionIndex = wrap($this->activeOptionIndex + 1, 0, count($this->options));
      $audioManager->playSystemSound(SystemSound::CURSOR);
      $this->renderOptionsMenu();
      return;
    }

    if ($vertical < 0) {
      $this->activeOptionIndex = wrap($this->activeOptionIndex - 1, 0, count($this->options));
      $audioManager->playSystemSound(SystemSound::CURSOR);
      $this->renderOptionsMenu();
      return;
    }

    if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      $audioManager->playSystemSound(SystemSound::CANCEL);
      $this->closeOptionsMenu();
      return;
    }

    $horizontal = Input::getAxis(AxisName::HORIZONTAL);

    if ($horizontal > 0) {
      $audioManager->playSystemSound(SystemSound::CURSOR);
      $this->changeActiveOption(1);
      return;
    }

    if ($horizontal < 0) {
      $audioManager->playSystemSound(SystemSound::CURSOR);
      $this->changeActiveOption(-1);
      return;
    }

    if (Input::isButtonDown('confirm')) {
      $audioManager->playSystemSound(SystemSound::CONFIRM);
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
    $audioManager = $this->getGame()->audioManager;

    if ($vertical > 0) {
      $this->activeContinueSlotIndex = wrap($this->activeContinueSlotIndex + 1, 0, count($this->continueSlots) - 1);
      $audioManager->playSystemSound(SystemSound::CURSOR);
      $this->continueStatusMessage = null;
      $this->renderContinueMenu();
      return;
    }

    if ($vertical < 0) {
      $this->activeContinueSlotIndex = wrap($this->activeContinueSlotIndex - 1, 0, count($this->continueSlots) - 1);
      $audioManager->playSystemSound(SystemSound::CURSOR);
      $this->continueStatusMessage = null;
      $this->renderContinueMenu();
      return;
    }

    if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      $audioManager->playSystemSound(SystemSound::CANCEL);
      $this->closeContinueMenu();
      return;
    }

    if (Input::isButtonDown('confirm')) {
      $audioManager->playSystemSound(SystemSound::CONFIRM);
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
    $this->advanceTitlePresentation();
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
    if ($this->continueViewport != $this->createContinueMenuLayout()) {
      Console::clear();
      $this->initializeContinueMenuWindows();
    }
    [$first, $last] = $this->continueViewport->getVisibleRange($this->activeContinueSlotIndex);
    $range = sprintf('%d-%d / %d', $first + 1, $last, count($this->continueSlots));
    $this->continueInfoWindow?->setTitle($this->getContinueMenuTitle() . '  ' . $range);
    $this->continueInfoWindow?->setContent([
      get_message('prompt.load', 'Choose a save file to continue from.'),
    ]);
    $this->continueInfoWindow?->render();

    foreach ($this->continueSlotWindows as $visibleIndex => $slotWindow) {
      $slotIndex = $first + $visibleIndex;
      if (!isset($this->continueSlots[$slotIndex])) {
        $slotWindow->erase();
        continue;
      }
      $slotWindow->setSlot(
        $this->continueSlots[$slotIndex],
        $slotIndex === $this->activeContinueSlotIndex
      );
      $slotWindow->render();
    }

    $statusText = $this->continueStatusMessage ?? 'Choose a file.';
    $hints = $this->getContinueControlHelp();
    $helpHeight = $this->continueHelpWindow?->getHeight() ?? self::CONTINUE_HELP_HEIGHT;
    $this->continueHelpWindow?->setTitle($helpHeight <= 2 ? $statusText : 'Help');
    $this->continueHelpWindow?->setHelp($helpHeight < 4 ? $hints : '');
    $this->continueHelpWindow?->setContent([
      $statusText,
      $hints,
    ]);
    $this->continueHelpWindow?->render();
  }

  protected function getContinueControlHelp(): string
  {
    $hints = [ActionHints::resolve('confirm', 'Load'), ActionHints::resolve('back', 'Back')];
    return implode('  ', array_map(static fn($hint): string =>
      ($hint->control?->label ?? 'Unbound') . ': ' . $hint->label, $hints));
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

    if (! $option instanceof GameSetting || ! $this->optionsManager instanceof TitleOptionsSettingsManager) {
      return;
    }


    try {
      $this->optionsManager->cycle($option, $step);
      $this->optionStatusMessage = null;
    } catch (Throwable $error) {
      Debug::warn('Could not save title settings: ' . $error->getMessage());
      $this->optionStatusMessage = "Could not save settings.\nChoice kept for this session.";
    }
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
    $status = ($this->titleInfoText ??= new MenuInfoText())->getPage('', $this->optionStatusMessage, $availableWidth);
    array_push($content, ...array_pad($status->lines, self::TITLE_OPTIONS_STATUS_ROWS, ''));

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
      TerminalText::displayWidth($this->getOptionsMenuTitle()) + 3,
      TerminalText::displayWidth(self::TITLE_OPTIONS_HELP) + 3,
    );
    $height = count($this->options) + 3 + self::TITLE_OPTIONS_STATUS_ROWS;

    return [
      'width' => min(get_screen_width(), $width),
      'height' => min(get_screen_height(), $height),
    ];
  }

  /**
   * Returns the shared Terminal and canvas options heading.
   */
  protected function getOptionsMenuTitle(): string
  {
    return Vocabulary::getTerm('game.options', Vocabulary::getTerm('command.options', self::TITLE_OPTIONS_TITLE));
  }

  protected function getContinueMenuTitle(): string
  {
    return Vocabulary::getTerm('game.continue', Vocabulary::getTerm('command.continue', 'Continue'));
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
    $selectedPath = $this->continueSlots[$this->activeContinueSlotIndex]->path ?? null;
    $this->continueSlots = $this->sceneManager->saveManager->getLoadSlots();
    foreach ($this->continueSlots as $index => $slot) {
      if ($slot->path === $selectedPath) {
        $this->activeContinueSlotIndex = $index;
        break;
      }
    }
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
      if (! $slot->isEmpty && $slot->isLoadable) {
        return $slotIndex;
      }
    }

    return 0;
  }

  /**
   * Resets the title scene to a neutral interactive state.
   *
   * Returning to title should not preserve the previously focused menu item or
   * carry confirm input across the scene transition.
   *
   * @return void
   */
  protected function resetTitleInteractionState(): void
  {
    $this->resetCredits();
    $this->showingOptions = false;
    $this->showingContinueMenu = false;
    $this->activeOptionIndex = 0;
    $this->activeContinueSlotIndex = 0;
    $this->continueStatusMessage = null;
    $this->menu?->setActiveItemByIndex(0);
    $this->menu?->updateWindowContent();
    InputManager::resetState(true);
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

    if (! $slot->isLoadable) {
      $this->continueStatusMessage = $slot->statusMessage ?? 'That save file cannot be loaded.';
      $this->renderContinueMenu();
      return;
    }

    $gameLoader = GameLoader::getInstance($this->getGame());
    $sceneManager = $this->sceneManager;

    try {
      $gameConfig = $gameLoader->loadSavedGame($slot->path);
    } catch (Throwable) {
      $this->continueStatusMessage = "That save file cannot be loaded.";
      $this->renderContinueMenu();
      return;
    }

    try {
      $sceneManager->loadSavedGame($gameConfig);
    } catch (Throwable $error) {
      $sceneManager->loadScene(self::class);
      $this->openContinueMenu();
      $this->continueStatusMessage = 'Continue unavailable: ' . $error->getMessage();
      Debug::warn($this->continueStatusMessage);
      $this->renderContinueMenu();
    }
  }
}
