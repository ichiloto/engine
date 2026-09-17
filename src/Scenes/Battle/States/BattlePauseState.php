<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\Battle\Presentation\BattlePauseMenu;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasUiAdapter;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePause;
use Ichiloto\Engine\Battle\Presentation\PauseAction;
use Ichiloto\Engine\Core\Menu\MainMenu\ConfigMenu;
use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\ConfigSelectionWindow;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\ConfigDetailPanel;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\UI\Accessibility;

/**
 * Represents the battle pause state.
 *
 * @package Ichiloto\Engine\Scenes\Battle\States
 */
class BattlePauseState extends BattleSceneState
{
  const string PAUSE_TEXT = "PAUSED";
  public private(set) ?BattlePauseMenu $menu = null;
  public private(set) ?ConfigMenu $configMenu = null;
  private ?PresentationCanvas $battlefield = null;
  private bool $configReturned = false;
  private bool $ownsOverlay = false;
  private bool $ownsConfigLayer = false;

  public function retainFrame(?PresentationCanvas $frame): void { $this->battlefield = $frame; }

  public function hasOwnedResources(): bool
  {
    return $this->menu !== null || $this->configMenu !== null || $this->battlefield !== null
      || $this->ownsOverlay || $this->ownsConfigLayer;
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (!$this->ownsInput()) { return; }
    if ($this->configMenu !== null) {
      $this->drawConfig(fn() => $this->configMenu?->update());
      if ($this->configReturned) { $this->returnFromConfig(); }
      return;
    }
    $menu = $this->menu;
    if ($menu === null) { return; }
    $menu->reducedMotion = Accessibility::prefersReducedMotion();
    $ready = $menu->isReady();
    $action = $menu->tick();
    if ($action !== null) {
      InputManager::resetState(true);
      if (!$this->ownsInput()) { return; }
      match ($action) {
        PauseAction::RESUME => $this->scene->resumeBattle(),
        PauseAction::CONFIG => $this->openConfig(),
        PauseAction::TITLE => $this->scene->getGame()->sceneManager->loadScene(TitleScene::class),
        PauseAction::EXIT => $this->scene->getGame()->quit(),
      };
      return;
    }
    // The frame that finishes a transition cannot also activate the next view.
    if (!$ready) { InputManager::resetState(); $this->render(); return; }
    if (Input::isButtonDown('cancel')) { $menu->back(); }
    elseif (Input::isButtonDown('pause')) { $menu->back(pauseShortcut: true); }
    elseif (Input::isButtonDown('confirm')) { $menu->confirm(); }
    else {
      $axis = Input::getAxis($menu->confirmation === null ? AxisName::VERTICAL : AxisName::HORIZONTAL);
      if ($axis !== 0.0) { $menu->navigate($axis > 0 ? 1 : -1); }
    }
    InputManager::resetState();
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->menu = $this->createMenu();
    $this->menu->open();
    InputManager::resetState(true);
    $this->render();
  }

  protected function createMenu(): BattlePauseMenu { return new BattlePauseMenu(Accessibility::prefersReducedMotion()); }

  public function exit(): void
  {
    if (!$this->hasOwnedResources()) { return; }
    $this->menu?->close();
    $this->menu = null;
    $this->configMenu = null;
    $this->configReturned = false;
    $this->battlefield = null;
    try {
      $this->releaseConfigLayer();
    } finally {
      $this->releaseOverlay();
    }
  }

  public function resume(): void { $this->render(); }

  /** Repaint and reposition, never re-enter or reset focus. */
  public function render(): void
  {
    if (!$this->ownsInput()) { return; }
    if ($this->configMenu !== null) {
      $this->releaseConfigLayer();
      $width = $this->configMenu->selection->getWidth();
      $height = $this->configMenu->selection->getHeight() + $this->configMenu->detail->getHeight();
      $x = max(0, intdiv(get_screen_width() - $width, 2));
      $y = max(0, intdiv(get_screen_height() - $height, 2));
      $this->configMenu->selection->setPosition(new Vector2($x, $y));
      $this->configMenu->detail->setPosition(new Vector2($x, $y + $this->configMenu->selection->getHeight()));
      $this->drawConfig(fn() => $this->configMenu?->render());
      return;
    }
    $menu = $this->menu;
    if ($menu === null || $menu->isClosed()) { return; }
    $width = min(52, get_screen_width());
    $inside = $width - 2;
    $center = static fn(string $label): string => '|' . str_pad($label, $inside, ' ', STR_PAD_BOTH) . '|';
    $lines = ['+' . str_repeat('-', $inside) . '+', $center($menu->heading()), $center('')];
    if ($menu->confirmation !== null) { $lines[] = $center(BattlePauseMenu::WARNING); $lines[] = $center(''); }
    if ($menu->confirmation !== null) {
      $half = intdiv($inside, 2);
      $choices = [];
      foreach ($menu->labels() as $index => $label) {
        $line = str_pad($label, $index === 0 ? $half : $inside - $half, ' ', STR_PAD_BOTH);
        if ($menu->selection === $index) { $line[1] = '>'; }
        $choices[] = $line;
      }
      $lines[] = '|' . implode('', $choices) . '|';
    } else {
      foreach ($menu->labels() as $index => $label) {
        $line = $center($label);
        if ($menu->selection === $index) { $line[2] = '>'; }
        $lines[] = $line;
      }
    }
    $lines[] = $center('');
    $lines[] = '+' . str_repeat('-', $inside) . '+';
    $this->ownsOverlay = true;
    Console::replaceOverlay('battle-pause', $lines, max(0, intdiv(get_screen_width() - $width, 2)),
      max(0, intdiv(get_screen_height() - count($lines), 2)), 10000);
  }

  public function canvas(): ?PresentationCanvas
  {
    $field = $this->battlefield;
    if ($field === null || $this->menu === null) { return null; }
    if ($this->configMenu === null && $this->scene->pauseSkin !== null) {
      return GraphicalBattlePause::frame($field, $this->scene->pauseSkin, $this->menu);
    }
    $columns = get_screen_width();
    $rows = get_screen_height();
    $grid = new RendererGridConfig($columns, $rows, max(1, intdiv($field->width, $columns)), max(1, intdiv($field->height, $rows)));
    $layers = [];
    foreach (Console::presentationSnapshot()->textLayers as $layer) {
      if ($layer->id !== ($this->configMenu === null ? 'battle-pause' : 'pause-config')) { continue; }
      $layers[] = new CanvasTextLayer($layer->id, 10000, ($field->width - $grid->columns * $grid->cellWidth) / 2,
        ($field->height - $grid->rows * $grid->cellHeight) / 2, $grid, BattleCanvasUiAdapter::opaqueRuns($layer->runs));
    }
    return new PresentationCanvas($field->width, $field->height, $field->images, $field->indicators, [...$field->textLayers, ...$layers]);
  }

  private function openConfig(): void
  {
    $this->releaseOverlay();
    $manager = new MainMenuSettingsManager();
    $width = min(110, get_screen_width());
    $height = min(32, get_screen_height());
    $this->configMenu = new ConfigMenu($manager,
      new ConfigSelectionWindow(new Rect(0, 0, $width, $height - 5), $manager),
      new ConfigDetailPanel(new Rect(0, 0, $width, 5)), function (): void { $this->configReturned = true; });
    $this->render();
    $this->drawConfig(fn() => $this->configMenu?->enter());
  }

  private function returnFromConfig(): void
  {
    $this->configReturned = false;
    $this->configMenu = null;
    InputManager::resetState(true);
    if (!$this->ownsInput()) { return; }
    $this->releaseConfigLayer();
    $this->menu?->open(1);
    $this->render();
  }

  private function drawConfig(callable $draw): void
  {
    $this->ownsConfigLayer = true;
    Console::withLayer('pause-config', $draw, 10000);
  }

  private function releaseConfigLayer(): void
  {
    if (!$this->ownsConfigLayer) { return; }
    Console::removeLayer('pause-config', repaint: !$this->scene->getGame()->hasStopped());
    $this->ownsConfigLayer = false;
  }

  private function releaseOverlay(): void
  {
    if (!$this->ownsOverlay) { return; }
    Console::removeOverlay('battle-pause');
    $this->ownsOverlay = false;
  }

  private function ownsInput(): bool
  {
    return !$this->scene->getGame()->hasStopped() && $this->scene->state === $this
      && $this->scene->getGame()->sceneManager->currentScene === $this->scene;
  }
}
