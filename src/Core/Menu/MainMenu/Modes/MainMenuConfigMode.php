<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Modes;

use Ichiloto\Engine\Core\Menu\MainMenu\ConfigMenu;

/**
 * Handles the in-game config menu shown from the main menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Modes
 */
class MainMenuConfigMode extends MainMenuMode
{
  private ?ConfigMenu $config = null;

  public function getConfigMenu(): ?ConfigMenu { return $this->config; }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->mainMenuState->infoPanel?->setText('Configure game settings.');
    $this->mainMenuState->eraseSummaryPanels();
    $this->mainMenuState->mainMenu?->erase();
    $this->mainMenuState->characterSelectionMenu?->erase();
    $this->mainMenuState->renderConfigPanels();
    $state = $this->mainMenuState;
    $this->config = new ConfigMenu($state->settingsManager, $state->configSelectionWindow,
      $state->configDetailPanel, fn() => $state->setMode(new MainMenuCommandSelectionMode($state)));
    $this->config->enter();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->config = null;
    $this->mainMenuState->eraseConfigPanels();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->config?->update();
  }
}
