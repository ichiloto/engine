<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\ItemMenu\Windows\InfoPanel;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\SelectShopMenuCommandMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopInventorySelectionMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopMenuMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopMerchandiseSelectionMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\ShopMenu;
use Ichiloto\Engine\Core\Menu\ShopMenu\Windows\ShopAccountBalancePanel;
use Ichiloto\Engine\Core\Menu\ShopMenu\Windows\ShopInfoPanel;
use Ichiloto\Engine\Core\Menu\ShopMenu\Windows\ShopItemDetailPanel;
use Ichiloto\Engine\Core\Menu\ShopMenu\Windows\ShopMainPanel;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\CommandPanel;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;

/**
 * ShopState class. This state allows players to interact with in-game shops.
 *
 * Key Features:
 * - Item Listings: Display items available for purchase, along with their prices and descriptions.
 * - Currency Transactions: Deduct currency for purchases and add items to the inventory. Enable selling items for currency.
 * - Inventory Updates: Reflect changes immediately in the player's inventory.
 *
 * Interactions with Other States:
 * - Returns to FieldState after the transaction is complete.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class ShopState extends GameSceneState
{
  /**
   * The width of the shop menu.
   */
  const int SHOP_MENU_WIDTH = 110;
  /**
   * The height of the shop menu.
   */
  const int SHOP_MENU_HEIGHT = 35;
  const int INFO_PANEL_HEIGHT = 3;
  const int COMMAND_PANEL_WIDTH = 80;
  const int COMMAND_PANEL_HEIGHT = 3;
  const int ACCOUNT_BALANCE_PANEL_WIDTH = 30;
  const int ACCOUNT_BALANCE_PANEL_HEIGHT = 3;
  const int MAIN_PANEL_WIDTH = 55;
  const int MAIN_PANEL_HEIGHT = 29;
  const int DETAIL_PANEL_WIDTH = 55;
  const int DETAIL_PANEL_HEIGHT = 29;

  /**
   * @var BorderPackInterface|null The border pack for the shop menu.
   */
  protected ?BorderPackInterface $borderPack = null;
  /**
   * The width of the shop info panel.
   */
  protected(set) ?InfoPanel $infoPanel = null;
  /**
   * @var CommandPanel|null The command panel for the shop menu. Displays the available actions.
   */
  protected(set) ?CommandPanel $commandPanel = null;
  /**
   * @var ShopAccountBalancePanel|null The account balance panel for the shop menu. Displays the player's currency.
   */
  protected(set) ?ShopAccountBalancePanel $accountBalancePanel = null;
  /**
   * @var ShopMainPanel|null The main panel for the shop menu. Displays the items available for purchase/sale.
   */
  protected(set) ?ShopMainPanel $mainPanel = null;
  /**
   * @var ShopItemDetailPanel|null The item detail panel for the shop menu. Displays the details of the selected item.
   */
  protected(set) ?ShopItemDetailPanel $detailPanel = null;

  /**
   * @var InventoryItem[] The items available for purchase in the shop.
   */
  public array $merchandise = [] {
    set {
      $this->merchandise = $value;
    }
  }

  /**
   * @var Inventory The player's inventory.
   */
  public Inventory $inventory {
    get {
      return $this->getGameScene()->party->inventory;
    }
  }
  /**
   * @var int The index of the selected item.
   */
  protected int $leftMargin = 0;
  /**
   * @var int The index of the selected item.
   */
  protected int $topMargin = 0;
  /**
   * @var ShopMenu|null The shop menu.
   */
  protected(set) ?ShopMenu $shopMenu = null;

  /**
   * @var int The player's current balance.
   */
  protected int $balance {
    get {
      return $this->getGameScene()->party->gold;
    }
  }
  /**
   * @var ShopMenuMode|null The mode of the shop menu.
   */
  protected(set) ?ShopMenuMode $mode = null;

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (Input::isButtonDown("quit")) {
      $this->quitGame();
    }

    $this->mode->update();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->calculateMargins();
    $this->initializeUI();
    $this->setMode(new SelectShopMenuCommandMode($this));
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // Do nothing
  }

  /**
   * Calculates the margins for the shop interface.
   *
   * @return void
   */
  protected function calculateMargins(): void
  {
    $this->leftMargin = (get_screen_width() - self::SHOP_MENU_WIDTH) / 2;
    $this->topMargin = 0;
  }

  /**
   * Initializes the user interface for the shop.
   *
   * @return void
   */
  protected function initializeUI(): void
  {
    $this->borderPack = new DefaultBorderPack();

    $this->shopMenu = new ShopMenu($this->getGameScene(), '', '');
    $this->shopMenu
      ->addItem(new class($this, $this->shopMenu) extends MenuItem {
        public function __construct(
          protected ShopState $state,
          MenuInterface $menu
        )
        {
          parent::__construct(
            $menu,
            'Buy',
            'Buy items from the shop.',
          );
        }

        public function execute(?ExecutionContextInterface $context = null): int
        {
          $this->state->setMode(new ShopMerchandiseSelectionMode($this->state));
          return self::SUCCESS;
        }
      })
      ->addItem(new class($this, $this->shopMenu) extends MenuItem {
        public function __construct(
          protected ShopState $state,
          MenuInterface $menu,
        )
        {
          parent::__construct(
            $menu,
            'Sell',
            'Sell items to the shop.',
          );
        }

        public function execute(?ExecutionContextInterface $context = null): int
        {
          $this->state->setMode(new ShopInventorySelectionMode($this->state));
          return self::SUCCESS;
        }
      })
      ->addItem(new class($this, $this->shopMenu) extends MenuItem {
        public function __construct(
          protected ShopState $state,
          MenuInterface $menu,
        )
        {
          parent::__construct($menu, 'Cancel', 'Exit the shop.');
        }

        public function execute(?ExecutionContextInterface $context = null): int
        {
          $this->state->setState($this->state->getGameScene()->fieldState);
          return self::SUCCESS;
        }
      });

    // Initialize Panels
    $this->infoPanel = new InfoPanel(
      $this->shopMenu,
      new Rect($this->leftMargin, $this->topMargin, self::SHOP_MENU_WIDTH, self::INFO_PANEL_HEIGHT),
      $this->borderPack
    );

    $this->commandPanel = new CommandPanel(
      '',
      '',
      $this->shopMenu,
      new Rect($this->leftMargin, $this->topMargin + self::INFO_PANEL_HEIGHT, self::COMMAND_PANEL_WIDTH, self::COMMAND_PANEL_HEIGHT),
      $this->borderPack
    );

    $this->accountBalancePanel = new ShopAccountBalancePanel(
      $this->shopMenu,
      new Rect($this->leftMargin + self::COMMAND_PANEL_WIDTH, $this->topMargin + self::INFO_PANEL_HEIGHT, self::ACCOUNT_BALANCE_PANEL_WIDTH, self::ACCOUNT_BALANCE_PANEL_HEIGHT),
      $this->borderPack
    );

    $this->mainPanel = new ShopMainPanel(
      $this->shopMenu,
      new Rect($this->leftMargin, $this->topMargin + self::INFO_PANEL_HEIGHT + self::COMMAND_PANEL_HEIGHT, self::MAIN_PANEL_WIDTH, self::MAIN_PANEL_HEIGHT),
      $this->borderPack
    );

    $this->detailPanel = new ShopItemDetailPanel(
      $this->shopMenu,
      new Rect($this->leftMargin + self::MAIN_PANEL_WIDTH, $this->topMargin + self::INFO_PANEL_HEIGHT + self::COMMAND_PANEL_HEIGHT, self::DETAIL_PANEL_WIDTH, self::DETAIL_PANEL_HEIGHT),
      $this->borderPack
    );

    $this->renderPanels();
  }

  /**
   * Renders the panels for the shop interface.
   *
   * @return void
   */
  public function renderPanels(): void
  {
    $this->infoPanel->setText($this->shopMenu->getActiveItem()->getDescription());
    $this->commandPanel->focus();
    $this->accountBalancePanel->setBalance($this->balance);
    $this->mainPanel->render();
    $this->detailPanel->render();
  }

  /**
   * Sets the mode of the shop menu.
   *
   * @param ShopMenuMode|null $mode The mode of the shop menu.
   * @return void
   */
  public function setMode(?ShopMenuMode $mode): void
  {
    $this->mode?->exit();
    $this->mode = $mode;
    $this->mode->enter();
  }
}