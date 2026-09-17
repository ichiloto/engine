<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Menu\MainMenu;

use Closure;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\ConfigDetailPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\ConfigSelectionWindow;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Settings\GameSetting;
use Throwable;

/** The existing settings interaction, hosted by either Main Menu or Pause. */
final class ConfigMenu
{
  private ?string $statusMessage = null;

  public function __construct(
    private MainMenuSettingsManager $settingsManager,
    public readonly ConfigSelectionWindow $selection,
    public readonly ConfigDetailPanel $detail,
    private Closure $onBack,
  ) {}

  public function enter(): void
  {
    $this->statusMessage = null;
    $this->selection->setSettings($this->settingsManager->getSettings());
    $this->selection->focus();
    $this->render();
  }

  public function render(): void
  {
    $this->selection->render();
    $setting = $this->selection->getActiveSetting();
    if ($setting instanceof GameSetting) {
      $this->detail->showSetting($setting, $this->statusMessage);
    } else {
      $this->detail->render();
    }
  }

  public function update(): void
  {
    if (Input::isButtonDown('cancel')) {
      play_sound(SystemSound::CANCEL);
      ($this->onBack)();
      return;
    }
    $vertical = Input::getAxis(AxisName::VERTICAL);
    if ($vertical !== 0.0) {
      play_sound(SystemSound::CURSOR);
      $this->statusMessage = null;
      if ($vertical > 0) { $this->selection->selectNext(); }
      else { $this->selection->selectPrevious(); }
      $this->render();
      return;
    }
    $horizontal = Input::getAxis(AxisName::HORIZONTAL);
    if ($horizontal !== 0.0 || Input::isButtonDown('confirm')) {
      $setting = $this->selection->getActiveSetting();
      if (!$setting instanceof GameSetting) { return; }
      play_sound(SystemSound::CURSOR);
      try {
        $label = $this->settingsManager->cycle($setting, $horizontal < 0 ? -1 : 1);
        $this->statusMessage = sprintf('%s set to %s.', $setting->label, $label);
      } catch (Throwable $exception) {
        $this->statusMessage = sprintf('Could not save settings: %s', $exception->getMessage());
      }
      $this->selection->updateContent();
      $this->render();
    }
  }
}
