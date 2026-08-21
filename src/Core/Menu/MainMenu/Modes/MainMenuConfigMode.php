<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Modes;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Throwable;

/**
 * Handles the in-game config menu shown from the main menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Modes
 */
class MainMenuConfigMode extends MainMenuMode
{
  /**
   * @var GameSetting[] The configurable settings shown in this mode.
   */
  protected array $settings = [];
  /**
   * @var string|null The latest status message to display in the detail panel.
   */
  protected ?string $statusMessage = null;

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->settings = $this->mainMenuState->settingsManager->getSettings();
    $this->statusMessage = null;
    $this->mainMenuState->infoPanel?->setText('Configure game settings.');
    $this->mainMenuState->eraseSummaryPanels();
    $this->mainMenuState->mainMenu?->erase();
    $this->mainMenuState->characterSelectionMenu?->erase();
    $this->mainMenuState->renderConfigPanels();
    $this->mainMenuState->configSelectionWindow?->setSettings($this->settings);
    $this->mainMenuState->configSelectionWindow?->focus();
    $this->renderActiveSetting();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->mainMenuState->eraseConfigPanels();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->handleNavigation();
    $this->handleActions();
  }

  /**
   * Handles vertical navigation through the settings list.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if ($v > 0) {
      play_sound(SystemSound::CURSOR);
      $this->statusMessage = null;
      $this->mainMenuState->configSelectionWindow?->selectNext();
      $this->renderActiveSetting();
      return;
    }

    if ($v < 0) {
      play_sound(SystemSound::CURSOR);
      $this->statusMessage = null;
      $this->mainMenuState->configSelectionWindow?->selectPrevious();
      $this->renderActiveSetting();
    }
  }

  /**
   * Handles adjustment and cancel actions for the config menu.
   *
   * @return void
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown('cancel')) {
      play_sound(SystemSound::CANCEL);
      $this->mainMenuState->setMode(new MainMenuCommandSelectionMode($this->mainMenuState));
      return;
    }

    $h = Input::getAxis(AxisName::HORIZONTAL);

    if ($h > 0 || Input::isButtonDown('confirm')) {
      play_sound(SystemSound::CURSOR);
      $this->changeSetting(1);
      return;
    }

    if ($h < 0) {
      play_sound(SystemSound::CURSOR);
      $this->changeSetting(-1);
    }
  }

  /**
   * Cycles the active setting in the requested direction.
   *
   * @param int $step The direction to move. Use `1` for next and `-1` for previous.
   * @return void
   */
  protected function changeSetting(int $step): void
  {
    $setting = $this->mainMenuState->configSelectionWindow?->getActiveSetting();

    if (! $setting instanceof GameSetting) {
      return;
    }

    try {
      $valueLabel = $this->mainMenuState->settingsManager->cycle($setting, $step);
      $this->statusMessage = sprintf('%s set to %s.', $setting->label, $valueLabel);
    } catch (Throwable $exception) {
      $this->statusMessage = sprintf('Could not save settings: %s', $exception->getMessage());
    }

    $this->mainMenuState->configSelectionWindow?->updateContent();
    $this->renderActiveSetting();
  }

  /**
   * Renders the description panel for the currently selected setting.
   *
   * @return void
   */
  protected function renderActiveSetting(): void
  {
    $setting = $this->mainMenuState->configSelectionWindow?->getActiveSetting();

    if (! $setting instanceof GameSetting) {
      return;
    }

    $this->mainMenuState->configDetailPanel?->showSetting(
      $setting,
      $this->statusMessage
    );
  }
}
