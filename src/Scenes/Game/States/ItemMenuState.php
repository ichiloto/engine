<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\ItemMenu\ItemMenu;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\DiscardItemMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectIemMenuCommandMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\ItemMenuMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\UseItemMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\ViewKeyItemsMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Windows\ItemInfoPanel;
use Ichiloto\Engine\Core\Menu\ItemMenu\Windows\ItemCommandPanel;
use Ichiloto\Engine\Core\Menu\ItemMenu\Windows\ItemSelectionPanel;
use Ichiloto\Engine\Core\Menu\ItemMenu\Windows\ItemTargetSelectionPanel;
use Ichiloto\Engine\Core\Menu\ItemMenu\Windows\ItemTargetStatusPanel;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\Util\Debug;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * The ItemMenu state allows the player to manage items in their inventory.
 *
 * Feature:
 * - Viewing items in the inventory.
 * - Using items.
 * - Discarding items.
 * - Sorting items.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class ItemMenuState extends GameSceneState implements CanRender
{
  /**
   * The width of the item menu.
   */
  protected const int ITEM_MENU_WIDTH = 110;
  /**
   * The height of the item menu.
   */
  protected const int ITEM_MENU_HEIGHT = 35;
  /**
   * The height of the primary window.
   */
  protected const int COMMAND_PANEL_HEIGHT = 3;
  /**
   * The height of the secondary window.
   */
  protected const int SELECTION_PANEL_HEIGHT = 28;
  /**
   * The width of the secondary window.
   */
  protected const int SELECTION_PANEL_WIDTH = 70;
  /**
   * The width of the target selection panel.
   */
  protected const int TARGET_SELECTION_PANEL_WIDTH = self::ITEM_MENU_WIDTH - self::SELECTION_PANEL_WIDTH;
  /**
   * The height of the target status panel.
   */
  protected const int TARGET_STATUS_PANEL_HEIGHT = 4;
  /**
   * The height of the target selection panel.
   */
  protected const int TARGET_SELECTION_PANEL_HEIGHT = self::SELECTION_PANEL_HEIGHT - self::TARGET_STATUS_PANEL_HEIGHT;
  /**
   * The width of the target status panel.
   */
  protected const int TARGET_STATUS_PANEL_WIDTH = self::TARGET_SELECTION_PANEL_WIDTH;
  /**
   * The height of the info panel.
   */
  protected const int INFO_PANEL_HEIGHT = 4;
  /**
   * @var ItemCommandPanel|null The item menu commands panel.
   */
  protected(set) ?ItemCommandPanel $itemMenuCommandsPanel = null;
  /**
   * @var ItemSelectionPanel|null The secondary window.
   */
  protected(set) ?ItemSelectionPanel $selectionPanel = null;
  /**
   * @var ItemTargetSelectionPanel|null The target selection panel.
   */
  protected(set) ?ItemTargetSelectionPanel $targetSelectionPanel = null;
  /**
   * @var ItemTargetStatusPanel|null The status panel.
   */
  protected(set) ?ItemTargetStatusPanel $statusPanel = null;
  /**
   * @var ItemInfoPanel|null The info panel.
   */
  protected(set) ?ItemInfoPanel $infoPanel = null;
  /**
   * @var int The left margin.
   */
  protected int $leftMargin = 0;
  /**
   * @var int The top margin.
   */
  protected int $topMargin = 0;
  /**
   * @var BorderPackInterface|null The border pack.
   */
  protected ?BorderPackInterface $borderPack = null;
  /**
   * @var MenuCommandExecutionContext|null The main menu context.
   */
  protected(set) ?MenuCommandExecutionContext $itemMenuContext = null;
  /**
   * @var ItemMenu|null The item menu.
   */
  protected(set) ?ItemMenu $itemMenu = null;
  /**
   * @var ItemMenuMode|null The mode.
   */
  protected(set) ?ItemMenuMode $mode = null;

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->calculateMargins();
    $this->initializeMenuUI();
    $this->itemMenuContext = new MenuCommandExecutionContext(
      [],
      new ConsoleOutput(),
      $this->itemMenu,
      $this->getGameScene()
    );
    $this->setMode(new SelectIemMenuCommandMode($this));
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    $this->mode->update();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->getGameScene()->mainMenuState->mainMenu->setActiveItemByIndex(0);
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->itemMenu->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->itemMenu->erase();
  }

  /**
   * Calculates the margins of the item menu.
   *
   * @return void
   */
  protected function calculateMargins(): void
  {
    $this->leftMargin = (get_screen_width() - self::ITEM_MENU_WIDTH) / 2;
    $this->topMargin = 0;
  }

  /**
   * Initializes the UI of the item menu.
   *
   * @return void
   */
  public function initializeMenuUI(): void
  {
    $this->borderPack = new DefaultBorderPack();

    $this->itemMenu = new ItemMenu($this->getGameScene(), '', '');
    $this->itemMenu
      ->addItem(new class($this, $this->itemMenu) extends MenuItem {
        public function __construct(
          protected ItemMenuState $state,
          MenuInterface $menu
        )
        {
          parent::__construct(
            $menu,
            'Use',
            'Use the selected item.'
          );
        }

        public function execute(?ExecutionContextInterface $context = null): int
        {
          $this->state->setMode(new UseItemMode($this->state));
          return self::SUCCESS;
        }
      })
      ->addItem(new class($this, $this->itemMenu) extends MenuItem {
        public function __construct(
          protected ItemMenuState $state,
          MenuInterface $menu
        )
        {
          parent::__construct(
            $menu,
            'Sort',
            'Sort the items in the inventory.'
          );
        }

        public function execute(?ExecutionContextInterface $context = null): int
        {
          return self::SUCCESS;
        }
      })
      ->addItem(new class($this, $this->itemMenu) extends MenuItem {
        public function __construct(
          protected ItemMenuState $state,
          MenuInterface $menu
        )
        {
          parent::__construct(
            $menu,
            'Discard',
            'Discard the selected item.'
          );
        }

        public function execute(?ExecutionContextInterface $context = null): int
        {
          $this->state->setMode(new DiscardItemMode($this->state));
          return self::SUCCESS;
        }
      })
      ->addItem(new class($this, $this->itemMenu) extends MenuItem {
        public function __construct(
          protected ItemMenuState $state,
          MenuInterface $menu
        )
        {
          parent::__construct($menu, 'Key Items', 'View key items.');
        }

        public function execute(?ExecutionContextInterface $context = null): int
        {
          $this->state->setMode(new ViewKeyItemsMode($this->state));
          return self::SUCCESS;
        }
      });

    $this->itemMenuCommandsPanel = new ItemCommandPanel(
      $this->itemMenu,
      new Rect($this->leftMargin, $this->topMargin, self::ITEM_MENU_WIDTH, self::COMMAND_PANEL_HEIGHT),
      $this->borderPack,
    );
    $this->itemMenuCommandsPanel->focus();
    $this->itemMenuCommandsPanel->render();

    $this->selectionPanel = new ItemSelectionPanel(
      $this,
      new Rect(
        $this->leftMargin,
        $this->topMargin + self::COMMAND_PANEL_HEIGHT,
        self::SELECTION_PANEL_WIDTH,
        self::SELECTION_PANEL_HEIGHT
      ),
      $this->borderPack
    );
    $this->selectionPanel->render();

    $this->targetSelectionPanel = new ItemTargetSelectionPanel(
      $this,
      new Rect(
        $this->leftMargin + self::SELECTION_PANEL_WIDTH,
        $this->topMargin + self::COMMAND_PANEL_HEIGHT,
        self::TARGET_SELECTION_PANEL_WIDTH,
        self::TARGET_SELECTION_PANEL_HEIGHT
      ),
      $this->borderPack
    );
    $this->targetSelectionPanel->render();

    $this->statusPanel = new ItemTargetStatusPanel(
      $this,
      new Rect(
        $this->leftMargin + self::SELECTION_PANEL_WIDTH,
        $this->topMargin + self::COMMAND_PANEL_HEIGHT + self::TARGET_SELECTION_PANEL_HEIGHT,
        self::TARGET_STATUS_PANEL_WIDTH,
        self::TARGET_STATUS_PANEL_HEIGHT
      ),
      $this->borderPack
    );
    $this->statusPanel->render();

    $infoPanelArea = new Rect(
      $this->leftMargin,
      $this->topMargin + self::COMMAND_PANEL_HEIGHT + self::SELECTION_PANEL_HEIGHT,
      self::ITEM_MENU_WIDTH,
      self::INFO_PANEL_HEIGHT
    );
    $this->infoPanel = new ItemInfoPanel($this->itemMenu, $infoPanelArea, $this->borderPack);
    $this->infoPanel->setText($this->itemMenu->getActiveItem()->getDescription());
  }

  /**
   * Sets the mode of the item menu.
   *
   * @param ItemMenuMode $mode The item menu mode.
   * @return void
   */
  public function setMode(ItemMenuMode $mode): void
  {
    $this->mode?->exit();
    $this->mode = $mode;
    $this->mode->enter();
  }

  /**
   * @inheritdoc
   */
  public function resume(): void
  {
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->itemMenuCommandsPanel->render();
    $this->selectionPanel->render();
    $this->targetSelectionPanel->render();
    $this->statusPanel->render();
    $this->infoPanel->render();
  }

  /**
   * @inheritdoc
   */
  public function suspend(): void
  {
    $this->exit();
  }
}