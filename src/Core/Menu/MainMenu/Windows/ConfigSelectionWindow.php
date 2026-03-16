<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Interfaces\CanChangeSelection;
use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSetting;
use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Interfaces\CanFocus;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Renders the list of configurable options inside the main-menu config flow.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Windows
 */
class ConfigSelectionWindow extends Window implements CanFocus, CanChangeSelection
{
  /**
   * @var MainMenuSetting[] The configurable settings shown in the list.
   */
  protected array $settings = [];
  protected int $totalSettings = 0;
  /**
   * @var int The currently selected setting index.
   */
  protected int $activeIndex = -1;

  /**
   * @param Rect $rect The rectangle occupied by the window.
   * @param MainMenuSettingsManager $settingsManager Resolves the displayed setting values.
   * @param BorderPackInterface $borderPack The border pack to use.
   */
  public function __construct(
    Rect $rect,
    protected MainMenuSettingsManager $settingsManager,
    BorderPackInterface $borderPack = new DefaultBorderPack(),
  )
  {
    parent::__construct(
      'Config',
      'enter:Next  c:Cancel',
      new Vector2($rect->getX(), $rect->getY()),
      $rect->getWidth(),
      $rect->getHeight(),
      $borderPack
    );
  }

  /**
   * Sets the settings shown in the list.
   *
   * @param MainMenuSetting[] $settings The settings to display.
   * @return void
   */
  public function setSettings(array $settings): void
  {
    $this->settings = array_values(array_filter(
      $settings,
      static fn(mixed $setting): bool => $setting instanceof MainMenuSetting
    ));
    $this->activeIndex = empty($this->settings) ? -1 : 0;
    $this->totalSettings = count($this->settings);
    $this->updateContent();
  }

  /**
   * Returns the currently selected setting.
   *
   * @return MainMenuSetting|null The selected setting, if any.
   */
  public function getActiveSetting(): ?MainMenuSetting
  {
    return $this->settings[$this->activeIndex] ?? null;
  }

  /**
   * @inheritdoc
   */
  public function focus(): void
  {
    if (! empty($this->settings) && $this->activeIndex < 0) {
      $this->activeIndex = 0;
    }

    $this->updateContent();
  }

  /**
   * @inheritdoc
   */
  public function blur(): void
  {
    $this->updateContent();
  }

  /**
   * @inheritdoc
   */
  public function selectPrevious(): void
  {
    if (empty($this->settings)) {
      return;
    }

    $this->activeIndex = clamp($this->activeIndex - 1, 0, $this->totalSettings - 1);
    $this->updateContent();
  }

  /**
   * @inheritdoc
   */
  public function selectNext(): void
  {
    if (empty($this->settings)) {
      return;
    }

    $this->activeIndex = clamp($this->activeIndex + 1, 0, $this->totalSettings - 1);
    $this->updateContent();
  }

  /**
   * Rebuilds the visible list content.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = [];
    $availableWidth = max(0, $this->width - 4);
    $labelWidth = 22;
    $valueWidth = max(0, $availableWidth - $labelWidth - 3);

    foreach ($this->settings as $index => $setting) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $label = TerminalText::padRight($setting->label, $labelWidth);
      $value = TerminalText::padRight($this->settingsManager->getCurrentChoiceLabel($setting), $valueWidth);
      $line = TerminalText::padRight(
        TerminalText::truncateToWidth("{$prefix} {$label} : {$value}", $availableWidth),
        $availableWidth
      );

      if ($index === $this->activeIndex) {
        $line = SelectionStyle::apply($line);
      }

      $content[] = $line;
    }

    $content = array_pad($content, $this->height - 2, '');
    $this->setContent($content);
    $this->render();
  }
}
