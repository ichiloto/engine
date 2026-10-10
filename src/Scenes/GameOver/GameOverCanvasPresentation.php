<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\GameOver;

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Interfaces\ModalPresentationProviderInterface;
use Ichiloto\Engine\UI\Presentation\GameOverMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;
use Throwable;

trait GameOverCanvasPresentation
{
  private ?MenuPresentationCatalog $gameOverTheme = null;
  private ?string $gameOverPresentationError = null;

  protected function resetGameOverPresentation(): void
  {
    $this->gameOverTheme = null;
    $this->gameOverPresentationError = null;
  }

  public function getPresentationCanvas(): ?PresentationCanvas
  {
    if (!$this->isStarted() || !isset($this->menu)) { return null; }
    $game = $this->getGame();
    $runtime = $game->getRendererRuntime();
    if ($runtime === null) { return null; }
    foreach (MenuPresentationCatalog::CAPABILITIES as $capability) {
      if (!$runtime->supports($capability)) { return null; }
    }
    try {
      $theme = $this->gameOverTheme ??= MenuPresentationCatalog::load($runtime->getAssetRoot())
        ?? new MenuPresentationCatalog($runtime->getAssetRoot(), ['schema' => 'ichiloto.menu/1']);
      $modal = isset($game->modalManager) ? $game->modalManager->currentModal : null;
      if ($modal !== null && !$modal->isShowing()) { $modal = null; }
      $rows = [];
      foreach ($this->menu->getItems() as $index => $command) {
        $rows[] = new MenuRow('command-' . $index, $command->getLabel(), kind: MenuRowKind::BUTTON,
          selected: $index === $this->menu->activeIndex, focused: $index === $this->menu->activeIndex && $modal === null,
          disabled: $command->isDisabled(), showCursor: false);
      }
      $canvas = GameOverMenuPresentation::compose($theme,
        Vocabulary::getTerm('game.game_over', 'Game Over'), $rows);
      if ($modal !== null && !MenuModalPresentation::requiresSceneComposition($this->getUI(), $runtime->getAssetRoot())) {
        $snapshot = $modal instanceof ModalPresentationProviderInterface ? $modal->getModalPresentation() : null;
        if ($snapshot === null) { throw new RuntimeException('Active Game Over modal has no supported canvas presentation.'); }
        $canvas = MenuModalPresentation::compose($canvas, $snapshot, $theme,
          ownerLayerId: 'ui:' . spl_object_id($modal));
      }
      return $canvas;
    } catch (Throwable $error) {
      if ($this->gameOverPresentationError !== $error->getMessage()) {
        Debug::error('Game Over presentation degraded to terminal: ' . $error->getMessage());
        $this->gameOverPresentationError = $error->getMessage();
      }
      return null;
    }
  }
}
