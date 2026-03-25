<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Exception;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * OpenQuitMenuCommand. This class represents a menu item that opens the quit menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenQuitMenuCommand extends MenuItem
{
  public function __construct(MenuInterface $menu)
  {
    $label = config(ProjectConfig::class, 'vocab.command.quit_game', 'Quit');
    parent::__construct($menu, $label, 'Return to title or close the game.');
  }

  /**
   * @inheritDoc
   * @throws Exception If the quit-selection modal cannot be displayed.
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    $selection = $this->promptForSelection();

    if ($selection >= 0) {
      $this->executeSelection($selection, $context);
    }

    return self::SUCCESS;
  }

  /**
   * Returns the quit-menu option labels.
   *
   * @return string[] The option labels.
   */
  protected function getOptions(): array
  {
    return [
      (new ToTitleMenuCommand($this->menu))->getLabel(),
      (new QuitGameCommand($this->menu))->getLabel(),
    ];
  }

  /**
   * Executes the selected quit action.
   *
   * @param int $selection The selected option index.
   * @param ExecutionContextInterface|null $context The execution context.
   * @return void
   */
  protected function executeSelection(int $selection, ?ExecutionContextInterface $context = null): void
  {
    match ($selection) {
      0 => (new ToTitleMenuCommand($this->menu))->execute($context),
      1 => (new QuitGameCommand($this->menu))->execute($context),
      default => null,
    };
  }

  /**
   * Opens the quit-selection modal and returns the selected option index.
   *
   * @return int The selected option index, or -1 when cancelled.
   * @throws Exception If the selection modal cannot be displayed.
   */
  protected function promptForSelection(): int
  {
    return select(
      'Choose where to go.',
      $this->getOptions(),
      $this->getLabel(),
      width: 32
    );
  }
}
