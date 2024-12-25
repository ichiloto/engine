<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Exception;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\EquipmentMenu;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentMenuMode;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentMenuCommandSelectionMode;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows\CharacterDetailPanel;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows\EquipmentAssignmentPanel;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows\EquipmentCommandPanel;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows\EquipmentInfoPanel;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuItemInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\Util\Debug;

/**
 * Represents the equipment menu state.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class EquipmentMenuState extends GameSceneState
{
  /**
   * The width and height of the equipment menu.
   */
  const int EQUIPMENT_MENU_WIDTH = 110;
  /**
   * The height of the equipment menu.
   */
  const int EQUIPMENT_MENU_HEIGHT = 35;
  /**
   * The width of the equipment command panel.
   */
  const int EQUIPMENT_COMMAND_PANEL_WIDTH = 70;
  /**
   * The height of the equipment command panel.
   */
  const int EQUIPMENT_COMMAND_PANEL_HEIGHT = 3;
  /**
   * The width of the equipment character detail panel.
   */
  const int EQUIPMENT_CHARACTER_DETAIL_PANEL_WIDTH = 40;
  /**
   * The height of the equipment character detail panel.
   */
  const int EQUIPMENT_CHARACTER_DETAIL_PANEL_HEIGHT = 31;
  /**
   * The width of the equipment assignment panel.
   */
  const int EQUIPMENT_ASSIGNMENT_PANEL_WIDTH = 70;
  /**
   * The height of the equipment assignment panel.
   */
  const int EQUIPMENT_ASSIGNMENT_PANEL_HEIGHT = 28;
  /**
   * The width of the equipment info panel.
   */
  const int EQUIPMENT_INFO_PANEL_WIDTH = 110;
  /**
   * The height of the equipment info panel.
   */
  const int EQUIPMENT_INFO_PANEL_HEIGHT = 4;

  /**
   * @var Character|null The character to manage equipment for.
   */
  public ?Character $character = null;
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
   * @var MenuCommandExecutionContext|null The equipment menu context.
   */
  protected(set) ?MenuCommandExecutionContext $equipmentMenuContext = null;
  /**
   * @var EquipmentMenu|null The equipment menu.
   */
  protected(set) ?EquipmentMenu $equipmentMenu = null;
  /**
   * @var EquipmentCommandPanel|null The equipment command panel.
   */
  protected(set) ?EquipmentCommandPanel $equipmentCommandPanel = null;
  /**
   * @var CharacterDetailPanel|null The character detail panel.
   */
  protected(set) ?CharacterDetailPanel $characterDetailPanel = null;
  /**
   * @var EquipmentAssignmentPanel|null The equipment assignment panel.
   */
  protected(set) ?EquipmentAssignmentPanel $equipmentAssignmentPanel = null;
  /**
   * @var EquipmentInfoPanel|null The equipment info panel.
   */
  protected(set) ?EquipmentInfoPanel $equipmentInfoPanel = null;
  /**
   * @var MenuItemInterface The active menu command.
   */
  public MenuItemInterface $activeMenuCommand {
    get {
      return $this->equipmentMenu->getActiveItem() ?? throw new \RuntimeException('No active menu command.');
    }
  }
  /**
   * @var EquipmentMenuMode|null The equipment menu mode.
   */
  protected ?EquipmentMenuMode $mode = null;

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    $this->mode->update();
  }

  /**
   * @inheritdoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->calculateMargins();
    $this->initializeMenuUI();
    $this->setMode(new EquipmentMenuCommandSelectionMode($this));
  }

  /**
   * @inheritdoc
   */
  public function exit(): void
  {
    // Do nothing
  }

  /**
   * Creates the equipment menu UI.
   *
   * @return void
   */
  protected function calculateMargins(): void
  {
    $this->leftMargin = (get_screen_width() - self::EQUIPMENT_MENU_WIDTH) / 2;
    $this->topMargin = 0;
  }

  /**
   * Initializes the equipment menu UI.
   *
   * @return void
   */
  protected function initializeMenuUI(): void
  {
    $this->borderPack = new DefaultBorderPack();

    $this->equipmentMenu = new EquipmentMenu($this->getGameScene(), '', '');
    $this->equipmentMenu
      ->addItem(new class($this, $this->equipmentMenu) extends MenuItem {
        public function __construct(
          protected EquipmentMenuState $state,
          MenuInterface $menu
        )
        {
          /**
           * Constructs an instance of this menu item.
           *
           * @param EquipmentMenuState $state The equipment menu state.
           * @param MenuInterface $menu The menu.
           */
          parent::__construct(
            $menu,
            'Equip',
            'Equip a character with weapons and armor.',
          );
        }

        /**
         * @inheritDoc
         */
        public function execute(?ExecutionContextInterface $context = null): int
        {
          return self::SUCCESS;
        }
      })
      ->addItem(new class($this, $this->equipmentMenu) extends MenuItem {
        /**
         * Constructs an instance of this menu item.
         *
         * @param EquipmentMenuState $state The equipment menu state.
         * @param MenuInterface $menu The menu.
         */
        public function __construct(
          protected EquipmentMenuState $state,
          MenuInterface $menu
        )
        {
          parent::__construct(
            $menu,
            'Optimize',
            "Optimize the character's equipment.",
          );
        }

        /**
         * @inheritDoc
         */
        public function execute(?ExecutionContextInterface $context = null): int
        {
          $this->state->optimizeEquipment();
          return self::SUCCESS;
        }
      })
      ->addItem(new class($this, $this->equipmentMenu) extends MenuItem {
        /**
         * Constructs an instance of this menu item.
         *
         * @param EquipmentMenuState $state The equipment menu state.
         * @param MenuInterface $menu The menu.
         */
        public function __construct(
          protected EquipmentMenuState $state,
          MenuInterface $menu
        )
        {
          parent::__construct(
            $menu,
            'Clear',
            "Clear the character's equipment.",
          );
        }

        /**
         * @inheritDoc
         */
        public function execute(?ExecutionContextInterface $context = null): int
        {
          $this->state->clearEquipment();
          return self::SUCCESS;
        }
      });

    $this->characterDetailPanel = new CharacterDetailPanel(
      $this->equipmentMenu,
      new Rect(
        $this->leftMargin,
        $this->topMargin,
        self::EQUIPMENT_CHARACTER_DETAIL_PANEL_WIDTH,
        self::EQUIPMENT_CHARACTER_DETAIL_PANEL_HEIGHT
      ),
      $this->borderPack
    );
    $this->characterDetailPanel->setDetails($this->character);

    $this->equipmentCommandPanel = new EquipmentCommandPanel(
      $this->equipmentMenu,
      new Rect($this->leftMargin + self::EQUIPMENT_CHARACTER_DETAIL_PANEL_WIDTH, $this->topMargin, self::EQUIPMENT_COMMAND_PANEL_WIDTH, self::EQUIPMENT_COMMAND_PANEL_HEIGHT),
      $this->borderPack
    );
    $this->equipmentCommandPanel->focus();

    $this->equipmentAssignmentPanel = new EquipmentAssignmentPanel(
      $this->equipmentMenu,
      new Rect($this->leftMargin + self::EQUIPMENT_CHARACTER_DETAIL_PANEL_WIDTH, $this->topMargin + self::EQUIPMENT_COMMAND_PANEL_HEIGHT, self::EQUIPMENT_ASSIGNMENT_PANEL_WIDTH, self::EQUIPMENT_ASSIGNMENT_PANEL_HEIGHT),
      $this->borderPack
    );

    $this->equipmentInfoPanel = new EquipmentInfoPanel(
      $this->equipmentMenu,
      new Rect($this->leftMargin, $this->topMargin + self::EQUIPMENT_COMMAND_PANEL_HEIGHT + self::EQUIPMENT_ASSIGNMENT_PANEL_HEIGHT, self::EQUIPMENT_INFO_PANEL_WIDTH, self::EQUIPMENT_INFO_PANEL_HEIGHT),
      $this->borderPack
    );
    $this->renderUI();
  }

  /**
   * Sets the equipment menu mode.
   *
   * @param EquipmentMenuMode $mode The equipment menu mode.
   * @return void
   */
  public function setMode(EquipmentMenuMode $mode): void
  {
    $this->mode?->exit();
    $this->mode = $mode;
    $this->mode->enter();
  }

  /**
   * Optimizes the character's equipment.
   *
   * @return void
   * @throws Exception If an error occurs while alerting the user.
   */
  public function optimizeEquipment(): void
  {
    $this->character?->optimizeEquipment($this->getGameScene()->party->inventory);
    $this->characterDetailPanel->updateContent();
  }

  /**
   * Clears the character's equipment.
   *
   * @return void
   * @throws Exception If an error occurs while alerting the user.
   */
  public function clearEquipment(): void
  {
    $this->character?->clearEquipment();
    $this->characterDetailPanel->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->renderUI();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->exit();
  }

  /**
   * Renders the equipment menu UI.
   *
   * @return void
   */
  protected function renderUI(): void
  {
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->characterDetailPanel->render();
    $this->equipmentCommandPanel->render();
    $this->equipmentInfoPanel->render();
    $this->equipmentAssignmentPanel->setSlots($this->character?->equipment ?? []);
  }
}