<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

/**
 * OpenRecordsMenuCommand. Opens the achievements and bestiary records.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenRecordsMenuCommand extends MenuItem
{
  /**
   * OpenRecordsMenuCommand constructor.
   *
   * @param MenuInterface $menu The menu that this command belongs to.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Records', 'Review achievements earned and enemies catalogued.');
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    if (! $context instanceof MenuCommandExecutionContext) {
      Debug::error("The context is null: " . __METHOD__);
      Debug::error(debug_get_backtrace());
      return self::FAILURE;
    }

    if (! $context->scene instanceof GameScene) {
      Debug::error("The scene is not a game scene: " . __METHOD__);
      Debug::error(debug_get_backtrace());
      return self::FAILURE;
    }

    $scene = $context->scene;
    $scene->setState($scene->recordsMenuState);
    $scene->locationHUDWindow->erase();
    return self::SUCCESS;
  }
}
