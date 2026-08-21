<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

/**
 * OpenQuestsMenuCommand. Opens the party's quest journal.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenQuestsMenuCommand extends MenuItem
{
  /**
   * OpenQuestsMenuCommand constructor.
   *
   * @param MenuInterface $menu The menu that this command belongs to.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Quests', 'Review the journal of active and completed quests.');
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
    $scene->setState($scene->questMenuState);
    $scene->locationHUDWindow->erase();
    return self::SUCCESS;
  }
}
