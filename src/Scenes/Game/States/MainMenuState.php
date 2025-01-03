<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\Commands\OpenAbilityMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenConfigMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenEquipmentMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenItemsMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenMagicMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenPartyOrderCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenQuitMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenSaveMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenStatusMenuCommand;
use Ichiloto\Engine\Core\Menu\Interfaces\MainMenuModeInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuItemInterface;
use Ichiloto\Engine\Core\Menu\MainMenu\CharacterSelectionMenu;
use Ichiloto\Engine\Core\Menu\MainMenu\MainMenu;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuCommandSelectionMode;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\AccountBalancePanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\InfoPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\LocationDetailPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\PlayTimePanel;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;
use Symfony\Component\Console\Output\ConsoleOutput;

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
class MainMenuState extends GameSceneState implements CanRender
{
  /**
   * The width of the main menu.
   */
  protected const int MAIN_MENU_WIDTH = 110;
  /**
   * The height of the main menu.
   */
  protected const int MAIN_MENU_HEIGHT = 35;
  /**
   * The width of the menu options.
   */
  protected const int MENU_OPTIONS_WIDTH = 30;
  /**
   * The height of the menu options.
   */
  protected const int MENU_OPTIONS_HEIGHT = 22;
  /**
   * @var InfoPanel|null The info panel.
   */
  protected(set) ?InfoPanel $infoPanel = null;
  /**
   * @var MenuInterface|null The menu.
   */
  protected(set) ?MenuInterface $menu = null;
  /**
   * @var MainMenu|null The main menu.
   */
  protected(set) ?MainMenu $mainMenu = null;
  /**
   * @var CharacterSelectionMenu|null The character selection menu.
   */
  protected(set) ?CharacterSelectionMenu $characterSelectionMenu = null;
  /**
   * @var PlayTimePanel|null The play time panel.
   */
  protected ?PlayTimePanel $playTimePanel = null;
  /**
   * @var AccountBalancePanel|null The account balance panel.
   */
  protected ?Window $accountBalancePanel = null;
  /**
   * @var LocationDetailPanel|null The location detail panel.
   */
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
  /**
   * @var float The next time to update the play time.
   */
  protected float $nextTimeUpdate = 0;
  /**
   * @var float The update interval.
   */
  protected float $updateInterval = 60; // 60 seconds.
  /**
   * @var MainMenuModeInterface|null The mode of the main menu.
   */
  protected ?MainMenuModeInterface $mode = null;
  /**
   * @var bool Whether the game can be saved.
   */
  protected bool $canSave {
    get {
      return $this->getGameScene()->mapManager?->canSave;
    }
  }
  /**
   * @var MenuCommandExecutionContext|null The main menu context.
   */
  protected(set) ?MenuCommandExecutionContext $mainMenuContext = null;
  /**
   * @var int The starting index.
   */
  public int $startingIndex = 0;

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->calculateMargins();
    $this->initializeMenuUI();
    $this->mainMenuContext = new MenuCommandExecutionContext(
      [
        'state' => $this,
        'mode' => $this->mode
      ],
      new ConsoleOutput(),
      $this->mainMenu,
      $this->getGameScene()
    );
    $this->setMode(new MainMenuCommandSelectionMode($this));
  }

  /**
   * @inheritDoc
   * @param SceneStateContext|null $context
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (Time::getTime() > $this->nextTimeUpdate) {
      $this->playTimePanel->updateTimeDisplay();
      $this->nextTimeUpdate = Time::getTime() + $this->updateInterval;
    }

    $this->mode->update();
  }

  /**
   * Initializes the main menu UI.
   *
   * @return void
   */
  protected function initializeMenuUI(): void
  {
    $this->borderPack = new DefaultBorderPack();

    $this->mainMenu = new MainMenu(
      $this->context->getScene(),
      '',
      '',
      rect: new Rect(
        $this->leftMargin,
        $this->topMargin + 3,
        self::MENU_OPTIONS_WIDTH,
        self::MENU_OPTIONS_HEIGHT
      ),
      borderPack: $this->borderPack
    );
    $this->mainMenu
      ->addItem(new OpenItemsMenuCommand($this->mainMenu))
      ->addItem(new OpenAbilityMenuCommand($this->mainMenu))
      ->addItem(new OpenEquipmentMenuCommand($this->mainMenu))
      ->addItem(new OpenMagicMenuCommand($this->mainMenu))
      ->addItem(new OpenStatusMenuCommand($this->mainMenu))
      ->addItem(new OpenPartyOrderCommand($this->mainMenu))
      ->addItem(new OpenConfigMenuCommand($this->mainMenu));

    if ($this->canSave) {
      $this->mainMenu->addItem(new OpenSaveMenuCommand($this->mainMenu));
    }
    $this->mainMenu->addItem(new OpenQuitMenuCommand($this->mainMenu));
    $this->mainMenu->updateWindowContent();

    $infoPanelPosition = new Vector2($this->leftMargin, $this->topMargin);
    $this->infoPanel = new InfoPanel($infoPanelPosition, $this->borderPack);
    $this->infoPanel->setText($this->mainMenu->getActiveItem()->getDescription());

    $playTimePanelPosition = new Vector2($this->leftMargin, $this->topMargin + 25);
    $this->playTimePanel = new PlayTimePanel($playTimePanelPosition, $this->borderPack);
    $this->playTimePanel->updateTimeDisplay();

    $accountBalancePosition = new Vector2($this->leftMargin, $this->topMargin + 28);
    $this->accountBalancePanel = new AccountBalancePanel($accountBalancePosition, $this->borderPack);
    $this->accountBalancePanel->setAmount($this->getGameScene()->party->accountBalance);

    $partyLocation = $this->getGameScene()->party->location;
    $locationDetailPosition = new Vector2($this->leftMargin, $this->topMargin + 31);
    $this->locationDetailPanel = new LocationDetailPanel($locationDetailPosition, $this->borderPack);
    $this->locationDetailPanel->setLocation($partyLocation?->name, $partyLocation?->region);

    $this->characterSelectionMenu = new CharacterSelectionMenu(
      $this->context->getScene(),
      '',
      '',
      rect: new Rect(
        $this->leftMargin + self::MENU_OPTIONS_WIDTH,
        $this->topMargin + 3,
        self::MAIN_MENU_WIDTH - self::MENU_OPTIONS_WIDTH,
        self::MAIN_MENU_HEIGHT - 3
      ),
      borderPack: $this->borderPack
    );
    $this->characterSelectionMenu->render();
  }

  /**
   * @return void
   */
  protected function calculateMargins(): void
  {
    $this->leftMargin = (get_screen_width() - self::MAIN_MENU_WIDTH) / 2;
    $this->topMargin = 0;
  }

  /**
   * Sets the mode of the main menu.
   *
   * @param MainMenuModeInterface $mode The main menu mode.
   * @return void
   */
  public function setMode(MainMenuModeInterface $mode): void
  {
    $this->mode?->exit();
    $this->mode = $mode;
    $this->mode->enter();
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->infoPanel->render();
    $this->mainMenu->render();
    $this->characterSelectionMenu->render();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->exit();
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->infoPanel->render();
    $this->playTimePanel->render();
    $this->accountBalancePanel->render();
    $this->locationDetailPanel->render();
    $this->mainMenu->render();
    $this->characterSelectionMenu->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    Console::clear();
  }
}