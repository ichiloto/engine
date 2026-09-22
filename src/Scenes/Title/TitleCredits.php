<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Title;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\UI\Presentation\CreditsContent;
use Ichiloto\Engine\UI\Presentation\CreditsMenuPresentation;
use Ichiloto\Engine\UI\Presentation\CreditsPlayback;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** The title scene owns entry, time, exit and fallback; presenters own no gameplay state. */
trait TitleCredits
{
  private ?CreditsContent $creditsContent = null;
  private ?CreditsPlayback $creditsPlayback = null;
  private ?MenuPresentationCatalog $creditsTheme = null;
  private bool $creditsPresentationFailed = false;

  public function openCredits(array $sections): void
  {
    $this->resetCredits();
    $content = new CreditsContent($sections);
    $runtime = $this->getGame()->getRendererRuntime();
    if ($runtime !== null && !Accessibility::prefersReducedMotion()) {
      try {
        if (array_all(CreditsMenuPresentation::CAPABILITIES, $runtime->supports(...))) {
          // Initialize the optional title backdrop without advancing any presentation clock.
          $this->getPresentationCanvas();
          $theme = $this->titleCatalog?->theme ?? MenuPresentationCatalog::load($runtime->getAssetRoot())
            ?? new MenuPresentationCatalog($runtime->getAssetRoot(), ['schema' => 'ichiloto.menu/1']);
          $playback = CreditsMenuPresentation::createPlayback($content, $theme);
          CreditsMenuPresentation::compose($content, $playback, $theme);
          $this->creditsContent = $content;
          $this->creditsPlayback = $playback;
          $this->creditsTheme = $theme;
          $playback->advance(hrtime(true) / 1e9);
          Console::clear();
          InputManager::resetState(true);
          $this->advanceTitlePresentation();
          return;
        }
      } catch (Throwable $error) {
        Debug::error('Credits presentation degraded to centered alerts: ' . $error->getMessage());
      }
    }
    $this->getGame()->modalManager->showCredits($content);
  }

  public function closeCredits(): void
  {
    $this->resetCredits();
    InputManager::resetState(true);
    $this->resume();
  }

  protected function updateCredits(): void
  {
    if ($this->creditsPlayback === null) { return; }
    $runtime = $this->getGame()->getRendererRuntime();
    if ($runtime === null || $this->creditsPresentationFailed || Accessibility::prefersReducedMotion()) {
      $content = $this->creditsContent;
      $this->resetCredits();
      if ($content !== null) { $this->getGame()->modalManager->showCredits($content); }
      return;
    }
    $modal = $this->getGame()->modalManager->currentModal;
    $paused = $this->titleSuspended || !$runtime->windowActive || ($modal?->isShowing() ?? false);
    if ($paused) { return; }
    if ($this->creditsPlayback->finished || Input::isButtonDown('confirm')
      || Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      $this->closeCredits();
    }
  }

  protected function resetCredits(): void
  {
    $this->creditsContent = null;
    $this->creditsPlayback = null;
    $this->creditsTheme = null;
    $this->creditsPresentationFailed = false;
  }
}
