<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputBindings;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Lets the player rebind the game's controls.
 *
 * Selecting an action listens for the next key and binds it, taking effect
 * immediately and writing back to the project's input configuration.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class ControlsMenuState extends GameSceneState
{
  protected const int MENU_WIDTH = 110;
  protected const int LIST_PANEL_HEIGHT = 24;
  protected const int INFO_PANEL_HEIGHT = 4;

  /**
   * @var array<int, array{action: string, description: string, keys: KeyCode[]}> The rebindable actions.
   */
  protected array $rows = [];
  /**
   * @var int The active row.
   */
  protected int $activeIndex = 0;
  /**
   * @var int The scroll offset.
   */
  protected int $scrollOffset = 0;
  /**
   * @var bool Whether the screen is waiting for a key to bind.
   */
  protected bool $listening = false;
  /**
   * @var string The message shown under the list.
   */
  protected string $status = '';
  protected int $leftMargin = 0;
  protected int $topMargin = 0;
  protected ?BorderPackInterface $borderPack = null;
  protected ?Window $listPanel = null;
  protected ?Window $infoPanel = null;
  protected InputBindings $bindings;

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->bindings = new InputBindings();
    $this->activeIndex = 0;
    $this->scrollOffset = 0;
    $this->listening = false;
    $this->status = 'Select an action and press enter to rebind it.';
    $this->reloadRows();
    $this->calculateMargins();
    $this->initializeUI();
    $this->refreshUI();
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if ($this->listening) {
      $this->captureBinding();

      return;
    }

    $this->handleNavigation();

    if (Input::isButtonDown('confirm')) {
      $this->listening = true;
      $this->status = sprintf('Press a key for "%s". Escape cancels.', $this->activeRow()['action'] ?? '');
      // The confirm key is still down; ignore it so it does not bind itself.
      InputManager::resetState(true);
      $this->refreshUI();

      return;
    }

    if (Input::isAnyKeyPressed([KeyCode::R, KeyCode::r])) {
      $this->status = $this->bindings->restoreDefaults()
        ? 'Controls restored to the defaults.'
        : 'Controls restored for this session; the file could not be written.';
      $this->reloadRows();
      $this->refreshUI();

      return;
    }

    if (Input::isButtonDown('back') || Input::isButtonDown('cancel')) {
      $this->setState($this->getGameScene()->mainMenuState);
    }
  }

  /**
   * Binds the next key pressed to the active action.
   *
   * @return void
   */
  protected function captureBinding(): void
  {
    $key = InputManager::getPressedKeyCode();

    if ($key === null) {
      return;
    }

    $this->listening = false;

    if ($key === KeyCode::ESCAPE) {
      $this->status = 'Rebinding cancelled.';
      $this->refreshUI();

      return;
    }

    $action = $this->activeRow()['action'] ?? '';

    $this->status = $this->bindings->rebind($action, $key)
      ? sprintf('%s is now bound to %s.', ucfirst(str_replace('_', ' ', $action)), $key->name)
      : sprintf('%s could not be rebound.', ucfirst(str_replace('_', ' ', $action)));

    $this->reloadRows();
    InputManager::resetState(true);
    $this->refreshUI();
  }

  /**
   * Returns the active row.
   *
   * @return array{action: string, description: string, keys: KeyCode[]}|null The row.
   */
  protected function activeRow(): ?array
  {
    return $this->rows[$this->activeIndex] ?? null;
  }

  /**
   * Reloads the bindings shown.
   *
   * @return void
   */
  protected function reloadRows(): void
  {
    $this->rows = $this->bindings->all();
    $this->activeIndex = min($this->activeIndex, max(0, count($this->rows) - 1));
  }

  /**
   * Moves the selection.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $vertical = Input::getAxis(AxisName::VERTICAL);

    if (abs($vertical) < 0.1 || $this->rows === []) {
      return;
    }

    $this->activeIndex = wrap(
      $this->activeIndex + ($vertical > 0 ? 1 : -1),
      0,
      count($this->rows) - 1
    );

    $visibleRows = self::LIST_PANEL_HEIGHT - 2;

    if ($this->activeIndex < $this->scrollOffset) {
      $this->scrollOffset = $this->activeIndex;
    } elseif ($this->activeIndex >= $this->scrollOffset + $visibleRows) {
      $this->scrollOffset = $this->activeIndex - $visibleRows + 1;
    }

    $this->refreshUI();
  }

  /**
   * Centers the screen inside the terminal.
   *
   * @return void
   */
  protected function calculateMargins(): void
  {
    $totalHeight = self::LIST_PANEL_HEIGHT + self::INFO_PANEL_HEIGHT;
    $this->leftMargin = max(0, intdiv(get_screen_width() - self::MENU_WIDTH, 2));
    $this->topMargin = max(0, intdiv(get_screen_height() - $totalHeight, 2));
  }

  /**
   * Builds the screen's windows.
   *
   * @return void
   */
  protected function initializeUI(): void
  {
    $this->borderPack = new DefaultBorderPack();

    $this->listPanel = new Window(
      'Controls',
      'enter:Rebind  r:Defaults  c:Cancel',
      new Vector2($this->leftMargin, $this->topMargin),
      self::MENU_WIDTH,
      self::LIST_PANEL_HEIGHT,
      $this->borderPack
    );

    $this->infoPanel = new Window(
      'Info',
      '',
      new Vector2($this->leftMargin, $this->topMargin + self::LIST_PANEL_HEIGHT),
      self::MENU_WIDTH,
      self::INFO_PANEL_HEIGHT,
      $this->borderPack
    );
  }

  /**
   * Redraws the screen.
   *
   * @return void
   */
  protected function refreshUI(): void
  {
    $innerWidth = self::MENU_WIDTH - 4;
    $visibleRows = self::LIST_PANEL_HEIGHT - 2;
    $content = [];

    foreach (array_slice($this->rows, $this->scrollOffset, $visibleRows, true) as $index => $row) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $action = TerminalText::padRight(ucfirst(str_replace('_', ' ', $row['action'])), 20);
      $keys = TerminalText::padRight($this->bindings->describeKeys($row['action']), 24);
      $line = TerminalText::padRight(
        TerminalText::truncateToWidth(" {$prefix} {$action}{$keys}{$row['description']}", $innerWidth),
        $innerWidth
      );

      $content[] = $index === $this->activeIndex ? SelectionStyle::apply($line) : $line;
    }

    $this->listPanel?->setContent(array_pad($content, $visibleRows, ''));
    $this->listPanel?->render();

    $this->infoPanel?->setContent([
      ' ' . $this->status,
      '',
    ]);
    $this->infoPanel?->render();
  }
}
