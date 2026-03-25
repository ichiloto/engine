<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Title\TitleScene;

/**
 * Opens the title-screen options overlay.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenTitleOptionsCommand extends MenuItem
{
  /**
   * @param MenuInterface $menu The title menu.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Options', 'Adjust game settings.');
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    if (! $context instanceof MenuCommandExecutionContext) {
      return self::FAILURE;
    }

    if (! $context->scene instanceof TitleScene) {
      return self::FAILURE;
    }

    $context->scene->openOptionsMenu();

    return self::SUCCESS;
  }
}
