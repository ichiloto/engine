<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Interfaces\ModalPresentationProviderInterface;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;
use Throwable;

/** Optional presentation lifecycle shared by the existing Engine menu states. */
trait MenuCanvasState
{
  private ?MenuPresentationCatalog $menuTheme = null;
  private bool $menuThemeLoaded = false;
  private float $menuPresentationStart = 0;
  private ?string $menuPresentationError = null;
  private ?MenuInfoText $infoText = null;

  public MenuInfoText $menuInfoText
  {
    get => $this->infoText ??= new MenuInfoText();
  }

  protected function handleMenuInfoInput(string $description, ?string $status = null, int $columns = 1): bool
  {
    if (!Input::isButtonDown('info')) { return false; }
    $info = $this->menuInfoText;
    $info->getPage($description, $status, $info->lastPage?->columns ?? $columns);
    $info->advance();
    return true;
  }

  private function resetMenuPresentation(): void
  {
    $this->menuTheme = null;
    $this->menuThemeLoaded = false;
    $this->menuPresentationStart = hrtime(true) / 1e9;
    $this->menuPresentationError = null;
    $this->infoText?->reset();
  }

  public function getPresentationCanvas(): ?PresentationCanvas
  {
    $game = $this->getGameScene()->getGame();
    $runtime = $game->getRendererRuntime();
    if ($runtime === null) { return null; }
    try {
      if (!$this->menuThemeLoaded) {
        $this->menuThemeLoaded = true;
        $theme = MenuPresentationCatalog::load($runtime->getAssetRoot());
        if ($theme !== null) {
          foreach (MenuPresentationCatalog::CAPABILITIES as $capability) {
            if (!$runtime->supports($capability)) { throw new RuntimeException("Menu renderer lacks {$capability}."); }
          }
        }
        $this->menuTheme = $theme;
      }
      if ($this->menuTheme === null) { return null; }
      $time = max(0, hrtime(true) / 1e9 - $this->menuPresentationStart);
      $canvas = $this->composeMenuCanvas($this->menuTheme, $time);
      $modal = isset($game->modalManager) ? $game->modalManager->currentModal : null;
      if ($canvas === null || $modal === null) { return $canvas; }
      if (!$modal->isShowing()) { return $canvas; }
      $snapshot = $modal instanceof ModalPresentationProviderInterface ? $modal->getModalPresentation() : null;
      if ($snapshot === null) {
        throw new RuntimeException('Active modal ' . $modal::class . ' has no supported menu canvas presentation.');
      }
      return MenuModalPresentation::compose($canvas, $snapshot, $this->menuTheme, $time);
    } catch (Throwable $error) {
      if ($this->menuPresentationError !== $error->getMessage()) {
        Debug::error('Menu presentation degraded to terminal: ' . $error->getMessage());
        $this->menuPresentationError = $error->getMessage();
      }
      return null;
    }
  }

  abstract protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas;
}
