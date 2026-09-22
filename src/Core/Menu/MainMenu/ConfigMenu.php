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
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Throwable;

/** The existing settings interaction, hosted by either Main Menu or Pause. */
final class ConfigMenu
{
  private ?string $statusMessage = null;
  private bool $statusError = false;
  private ?MenuInfoText $infoText = null;
  private ?GameSetting $infoSelection = null;

  public MenuInfoText $menuInfoText { get => $this->infoText ??= new MenuInfoText(); }

  public function __construct(
    private MainMenuSettingsManager $settingsManager,
    public readonly ConfigSelectionWindow $selection,
    public readonly ConfigDetailPanel $detail,
    private Closure $onBack,
  ) {}

  public function getStatusMessage(): ?string { return $this->statusMessage; }
  public function hasStatusError(): bool { return $this->statusError; }
  public function getChoiceIndex(GameSetting $setting): int { return $this->settingsManager->getCurrentChoiceIndex($setting); }

  public function enter(): void
  {
    $this->statusMessage = null;
    $this->statusError = false;
    $this->menuInfoText->reset();
    $this->selection->setSettings($this->settingsManager->getSettings());
    $this->selection->focus();
    $this->render();
  }

  public function render(): void
  {
    $this->selection->render();
    $setting = $this->selection->getActiveSetting();
    $this->syncInfoSelection($setting);
    if ($setting instanceof GameSetting) {
      $page = $this->menuInfoText->getPage($setting->description, $this->statusMessage,
        max(1, $this->detail->getContentWidth() - 2));
      $this->detail->setContent(array_pad($page->lines, 2, ''));
      $this->detail->setHelp($page->total > 2 ? $page->range() : '');
      $this->detail->render();
    } else {
      $this->detail->setContent(['', '']);
      $this->detail->setHelp('');
      $this->detail->render();
    }
  }

  public function update(): void
  {
    $setting = $this->selection->getActiveSetting();
    $this->syncInfoSelection($setting);
    if (Input::isButtonDown('info')) {
      $info = $this->menuInfoText;
      $info->getPage($setting?->description ?? '', $this->statusMessage,
        $info->lastPage?->columns ?? max(1, $this->detail->getContentWidth() - 2));
      $info->advance();
      $this->render();
      return;
    }
    if (Input::isButtonDown('cancel')) {
      play_sound(SystemSound::CANCEL);
      ($this->onBack)();
      return;
    }
    $vertical = Input::getAxis(AxisName::VERTICAL);
    if ($vertical !== 0.0) {
      play_sound(SystemSound::CURSOR);
      $this->statusMessage = null;
      $this->statusError = false;
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
        $this->statusError = false;
      } catch (Throwable $exception) {
        $this->statusMessage = sprintf('Could not save settings: %s', $exception->getMessage());
        $this->statusError = true;
      }
      $this->selection->updateContent();
      $this->render();
    }
  }

  private function syncInfoSelection(?GameSetting $setting): void
  {
    if ($setting !== $this->infoSelection) { $this->menuInfoText->reset(); }
    $this->infoSelection = $setting;
  }
}
