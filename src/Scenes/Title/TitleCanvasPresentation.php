<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Title;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\UI\Presentation\CreditsMenuPresentation;
use Ichiloto\Engine\UI\Interfaces\ModalPresentationProviderInterface;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\UI\Presentation\SaveLoadMenuPresentation;
use Ichiloto\Engine\UI\Presentation\SettingsMenuContent;
use Ichiloto\Engine\UI\Presentation\SettingsMenuPresentation;
use Ichiloto\Engine\UI\Presentation\TitleMenuPresentation;
use Ichiloto\Engine\UI\Presentation\TitlePlayback;
use Ichiloto\Engine\UI\Presentation\TitlePresentationCatalog;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;
use Throwable;

/** Optional graphics and lifetime over TitleScene's existing commands and destination state. */
trait TitleCanvasPresentation
{
  private ?TitlePresentationCatalog $titleCatalog = null;
  private ?TitlePlayback $titlePlayback = null;
  private bool $titleCatalogLoaded = false;
  private bool $titleSuspended = false;
  private ?string $titlePresentationError = null;
  private ?MenuInfoText $titleInfoText = null;
  protected ?string $optionStatusMessage = null;

  protected function resetTitlePresentation(): void
  {
    $this->titleCatalog = null;
    $this->titleCatalogLoaded = false;
    $this->titlePresentationError = null;
    $this->titleSuspended = false;
    $this->titleInfoText = new MenuInfoText();
    $this->titlePlayback = new TitlePlayback();
    $this->advanceTitlePresentation();
  }

  protected function advanceTitlePresentation(): void
  {
    if ($this->titlePlayback === null) { return; }
    $now = hrtime(true) / 1e9;
    $game = isset($this->sceneManager) ? $this->getGame() : null;
    $windowActive = $game?->getRendererRuntime()?->windowActive ?? true;
    $modal = isset($game->modalManager) ? $game->modalManager->currentModal : null;
    $this->titlePlayback->setObscured(!$windowActive || $this->titleSuspended || $this->showingOptions
      || $this->showingContinueMenu || $this->creditsPlayback !== null || ($modal?->isShowing() ?? false), $now);
    $this->titlePlayback->advance($now, null, Accessibility::prefersReducedMotion());
    $this->creditsPlayback?->advance($now, !$windowActive || $this->titleSuspended || ($modal?->isShowing() ?? false));
  }

  protected function setTitlePresentationSuspended(bool $suspended): void
  {
    $this->titleSuspended = $suspended;
    $this->advanceTitlePresentation();
  }

  protected function stopTitlePresentation(): void
  {
    $this->titlePlayback = null;
    $this->titleCatalog = null;
    $this->titleInfoText = null;
  }

  public function getPresentationCanvas(): ?PresentationCanvas
  {
    if ($this->titlePlayback === null || !isset($this->menu)) { return null; }
    $game = $this->getGame();
    $runtime = $game->getRendererRuntime();
    if ($runtime === null) { return null; }
    try {
      $modal = isset($game->modalManager) ? $game->modalManager->currentModal : null;
      if ($modal !== null && !$modal->isShowing()) { $modal = null; }
      if ($this->creditsPlayback !== null && $this->creditsContent !== null && $this->creditsTheme !== null) {
        $credits = CreditsMenuPresentation::compose($this->creditsContent, $this->creditsPlayback, $this->creditsTheme);
        $canvas = $this->titleCatalog === null ? $credits : MenuCanvas::overlay(
          TitleMenuPresentation::compose($this->titleCatalog, $this->titlePlayback, [], false), $credits, $this->creditsTheme);
        if ($modal !== null) {
          $snapshot = $modal instanceof ModalPresentationProviderInterface ? $modal->getModalPresentation() : null;
          if ($snapshot === null) { throw new RuntimeException('Active credits modal has no supported canvas presentation.'); }
          $canvas = MenuModalPresentation::compose($canvas, $snapshot, $this->creditsTheme);
        }
        return $canvas;
      }
      if (!$this->titleCatalogLoaded) {
        $this->titleCatalogLoaded = true;
        $catalog = TitlePresentationCatalog::load($runtime->getAssetRoot());
        if ($catalog !== null) {
          foreach (TitlePresentationCatalog::CAPABILITIES as $capability) {
            if (!$runtime->supports($capability)) { throw new RuntimeException("Title renderer lacks {$capability}."); }
          }
        }
        $this->titleCatalog = $catalog;
      }
      if ($this->titleCatalog === null) { return null; }
      $catalog = $this->titleCatalog;
      $rows = [];
      foreach ($this->menu->getItems() as $index => $command) {
        $rows[] = new MenuRow('command-' . $index, $command->getLabel(), kind: MenuRowKind::BUTTON,
          selected: $index === $this->menu->activeIndex, focused: $index === $this->menu->activeIndex,
          disabled: $command->isDisabled(), showCursor: false);
      }
      $time = $this->titlePlayback->elapsed;
      $canvas = TitleMenuPresentation::compose($catalog, $this->titlePlayback, $rows,
        !$this->showingOptions && !$this->showingContinueMenu && $modal === null);
      $info = $this->titleInfoText ??= new MenuInfoText();
      if ($this->showingOptions && $this->optionsManager !== null) {
        $choices = array_map(fn($setting) => $this->optionsManager->getCurrentChoiceIndex($setting), $this->options);
        $snapshot = new SettingsMenuContent('Options', $this->options, $choices, $this->activeOptionIndex, $info,
          $this->optionStatusMessage, $this->optionStatusMessage !== null, 'Back', $this->isBackRowSelected(),
          new CanvasRectangle(225, 60, 900, 600));
        $canvas = MenuCanvas::overlay($canvas, SettingsMenuPresentation::compose($snapshot, $catalog->theme, $time), $catalog->theme);
      } elseif ($this->showingContinueMenu) {
        $canvas = MenuCanvas::overlay($canvas, SaveLoadMenuPresentation::compose($this->continueSlots,
          $this->activeContinueSlotIndex, $catalog->theme, $info, $this->continueStatusMessage, $time), $catalog->theme);
      }
      if ($modal !== null) {
        $snapshot = $modal instanceof ModalPresentationProviderInterface ? $modal->getModalPresentation() : null;
        if ($snapshot === null) { throw new RuntimeException('Active title modal has no supported canvas presentation.'); }
        $canvas = MenuModalPresentation::compose($canvas, $snapshot, $catalog->theme, $time);
      }
      return $canvas;
    } catch (Throwable $error) {
      if ($this->creditsPlayback !== null) { $this->creditsPresentationFailed = true; }
      if ($this->titlePresentationError !== $error->getMessage()) {
        Debug::error('Title presentation degraded to terminal: ' . $error->getMessage());
        $this->titlePresentationError = $error->getMessage();
      }
      return null;
    }
  }
}
