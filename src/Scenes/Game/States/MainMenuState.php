<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenu;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\AccountBalancePanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\InfoPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\LocationDetailPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\PlayTimePanel;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

/**
 * The MainMenu state allows the player to access the in-game menu for managing inventory, checking the map, viewing stats, and saving the game.
 *
 * Feature:
 * - Inventory management.
 * - Party management (e.g., swapping characters or equipment).
 * - Accessing the map or quest log.
 * - Saving or loading the game.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class MainMenuState extends GameSceneState
{
  /**
   * The width of the main menu.
   */
  protected const int MAIN_MENU_WIDTH = 110;
  /**
   * The height of the main menu.
   */
  protected const int MAIN_MENU_HEIGHT = 35;
  protected const int MENU_OPTIONS_WIDTH = 30;
  protected const int MENU_OPTIONS_HEIGHT = 22;
  /**
   * @var InfoPanel|null The info panel.
   */
  protected ?InfoPanel $infoPanel = null;
  /**
   * @var MainMenu|null The main menu.
   */
  protected ?MainMenu $mainMenu = null;
  protected ?PlayTimePanel $playTimePanel = null;
  protected ?Window $accountBalancePanel = null;
  protected ?LocationDetailPanel $locationDetailPanel = null;
  /**
   * @var Window|null The party status panel.
   */
  protected ?Window $partyStatusPanel = null;
  /**
   * @var int The left margin of the main menu.
   */
  protected int $leftMargin = 0;
  /**
   * @var int The right margin of the main menu.
   */
  protected int $topMargin = 0;
  /**
   * @var BorderPackInterface|null The border pack.
   */
  protected ?BorderPackInterface $borderPack = null;
  protected float $nextTimeUpdate = 0;
  protected float $updateInterval = 60; // 60 seconds.

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();

    // Calculate left and top margins.
    $this->leftMargin = (Console::getWidth() - self::MAIN_MENU_WIDTH) / 2;
    $this->topMargin = 0;

    // Initialize the main menu UI.
    $this->borderPack = new DefaultBorderPack();

    $infoPanelPosition = new Vector2($this->leftMargin, $this->topMargin);
    $this->infoPanel = new InfoPanel($infoPanelPosition, $this->borderPack);
    $this->infoPanel->setText('');

    $playTimePanelPosition = new Vector2($this->leftMargin, $this->topMargin + 26);
    $this->playTimePanel = new PlayTimePanel($playTimePanelPosition, $this->borderPack);
    $this->playTimePanel->updateTimeDisplay();

    $accountBalancePosition = new Vector2($this->leftMargin, $this->topMargin + 29);
    $this->accountBalancePanel = new AccountBalancePanel($accountBalancePosition, $this->borderPack);
    $this->accountBalancePanel->setAmount(500);

    $locationDetailPosition = new Vector2($this->leftMargin, $this->topMargin + 32);
    $this->locationDetailPanel = new LocationDetailPanel($locationDetailPosition, $this->borderPack);
    $this->locationDetailPanel->setLocation('Town Square', 'Happyville');

    // Display the main menu UI.
  }

  /**
   * @inheritDoc
   * @throws NotFoundException
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (InputManager::isAnyKeyPressed([KeyCode::ESCAPE])) {
      $this->setState($this->context->getScene()->fieldState ?? throw new NotFoundException('FieldState'));
    }

    Debug::log("Time: " . Time::getTime());
    if (Time::getTime() > $this->nextTimeUpdate) {
      $this->playTimePanel->updateTimeDisplay();
      $this->nextTimeUpdate = Time::getTime() + $this->updateInterval;
    }
  }
}