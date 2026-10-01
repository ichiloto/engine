<?php

namespace Ichiloto\Engine\Scenes\Battle;

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasUiAdapter;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleHud;
use Ichiloto\Engine\Battle\Presentation\BattleHudSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleResultsSkin;
use Ichiloto\Engine\Battle\Presentation\BattleResultsPlayback;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleResults;
use Ichiloto\Engine\Battle\Presentation\BattlePauseSkin;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePause;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Battle\Entry\BattleEntryRuleCatalog;
use Ichiloto\Engine\Battle\Entry\BattleEntryRuleRunner;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Battle\UI\BattleResultWindow;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleEndState;
use Ichiloto\Engine\Scenes\Battle\States\BattleDefeatState;
use Ichiloto\Engine\Scenes\Battle\States\BattlePauseState;
use Ichiloto\Engine\Scenes\Battle\States\BattleRunState;
use Ichiloto\Engine\Scenes\Battle\States\BattleSceneState;
use Ichiloto\Engine\Scenes\Battle\States\BattleStartState;
use Ichiloto\Engine\Scenes\Battle\States\BattleVictoryState;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Override;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;
use Throwable;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;

/**
 * Represents a battle scene.
 *
 * @package Ichiloto\Engine\Scenes\Battle
 */
class BattleScene extends AbstractScene implements CanvasProviderInterface
{
  public private(set) ?GraphicalBattlePresentation $graphicalPresentation = null;
  public private(set) ?BattleCanvasLayout $battleUiLayout = null;
  public private(set) ?BattleResultsSkin $resultsSkin = null;
  public private(set) ?BattleResultsPlayback $resultsPlayback = null;
  public private(set) ?BattlePauseSkin $pauseSkin = null;
  private ?PresentationCanvas $resultsBattlefield = null;

  public function beginResults(): void
  {
    $rewards = $this->result?->rewards;
    if ($rewards === null) { return; }
    $this->resultsPlayback = new BattleResultsPlayback($rewards, Accessibility::prefersReducedMotion());
    $layout = $this->graphicalPresentation?->arena ?? $this->battleUiLayout;
    if ($this->resultsSkin !== null && $layout !== null) {
      $field = array_values(array_filter(BattleCanvasUiAdapter::collect($this, $layout, true),
        static fn($layer) => $layer->id === 'battle-field'));
      $this->resultsBattlefield = $this->graphicalPresentation?->frame()
        ?? new PresentationCanvas($layout->width, $layout->height, textLayers: $field);
    }
  }

  public function endResults(): void
  {
    $this->resultsPlayback = null;
    $this->resultsBattlefield = null;
  }

  #[Override]
  public function stop(): void
  {
    try {
      $this->ui?->resumeTiming(discard: true);
      if ($this->pauseState?->hasOwnedResources()) { $this->pauseState->exit(); }
      $this->endResults();
    } finally {
      BattleCommandCatalog::endBattle();
      parent::stop();
    }
  }

  public function hasGraphicalResults(): bool
  {
    return $this->resultsPlayback !== null && $this->resultsSkin !== null && $this->resultsBattlefield !== null;
  }

  public function getPresentationCanvas(): ?PresentationCanvas
  {
    if ($this->state instanceof BattlePauseState) { return $this->state->canvas(); }
    if ($this->hasGraphicalResults()) {
      return GraphicalBattleResults::frame($this->resultsBattlefield, $this->resultsSkin, $this->resultsPlayback);
    }
    $layout = $this->graphicalPresentation?->arena ?? $this->battleUiLayout;
    if ($layout === null || $this->state instanceof BattleStartState) { return null; }
    $focus = null;
    if ($layout->skin !== null && $this->state instanceof BattleRunState) {
      $engine = $this->getGame()->engine;
      if ($engine instanceof TurnBasedEngine && $engine->state instanceof PlayerActionState) {
        $focus = $engine->state->getSelectionMode();
      }
    }
    $ui = BattleCanvasUiAdapter::collect($this, $layout, includeField: $this->graphicalPresentation === null);
    $hud = $this->ui === null ? null : BattleHudSnapshot::fromScreen($this->ui);
    if ($this->graphicalPresentation !== null) {
      return $this->graphicalPresentation->frame($this->ui?->fieldWindow, $ui, $hud, $focus);
    }
    $composition = $hud === null ? null : GraphicalBattleHud::compose($layout, $hud, $focus,
      hrtime(true) / 1_000_000_000, $this->getGame()->getRendererRuntime()?->getAssetRoot());
    return new PresentationCanvas($layout->width, $layout->height, $composition?->images ?? [],
      textLayers: [...$ui, ...($composition?->textLayers ?? [])]);
  }
  /**
   * @var BattleConfig|null The configuration of the scene.
   */
  protected(set) ?BattleConfig $config = null;
  /**
   * @var Party|null The party in the scene.
   */
  public ?Party $party {
    get {
      return $this->config->party ?? null;
    }
  }
  public ?Troop $troop {
    get {
      return $this->config->troop ?? null;
    }
  }
  public array $events {
    get {
      return $this->config->events ?? [];
    }
  }
  /**
   * @var BattleSceneState|null The state of the scene.
   */
  protected(set) ?BattleSceneState $state = null;
  /**
   * @var SceneStateContext|null The context of the scene state.
   */
  protected(set) ?SceneStateContext $sceneStateContext = null;

  // Battle Scene states
  /**
   * @var BattleEndState|null The end state of the scene.
   */
  protected(set) ?BattleEndState $endState = null;
  /**
   * @var BattleDefeatState|null The defeat state of the scene.
   */
  protected(set) ?BattleDefeatState $defeatState = null;
  /**
   * @var BattlePauseState|null The pause state of the scene.
   */
  protected(set) ?BattlePauseState $pauseState = null;
  /**
   * @var BattleRunState|null The run state of the scene.
   */
  protected(set) ?BattleRunState $runState = null;
  /**
   * @var BattleStartState|null The start state of the scene.
   */
  protected(set) ?BattleStartState $startState = null;
  /**
   * @var BattleVictoryState|null The victory state of the scene.
   */
  protected(set) ?BattleVictoryState $victoryState = null;
  /**
   * @var BattleScreen|null The battle screen of the scene.
   */
  public ?BattleScreen $ui = null;
  /**
   * @var BattleResult|null The outcome of the current battle.
   */
  public ?BattleResult $result = null;
  /**
   * @var BattleResultWindow|null The result window shown at the end of battle.
   */
  public ?BattleResultWindow $resultWindow = null;
  /**
   * @var bool Whether the battle should transition to the game over scene.
   */
  public bool $shouldLoadGameOver = false;

  /**
   * @inheritDoc
   *
   * A battle may override the project-wide battle theme through the
   * `bgm` entry in its runtime settings (e.g. for boss encounters).
   */
  #[Override]
  public function getBackgroundMusic(): ?string
  {
    $track = $this->config->settings['bgm'] ?? null;

    if (is_string($track) && trim($track) !== '') {
      return trim($track);
    }

    return $this->getConfiguredBackgroundMusic('audio.bgm.battle');
  }

  /**
   * Returns the victory theme played while the battle results are shown.
   *
   * A battle may override the project-wide victory theme through the
   * `victory_bgm` entry in its runtime settings.
   *
   * @return string|null The victory track, or null when none is configured.
   */
  public function getVictoryMusic(): ?string
  {
    $track = $this->config->settings['victory_bgm'] ?? null;

    if (is_string($track) && trim($track) !== '') {
      return trim($track);
    }

    return $this->getConfiguredBackgroundMusic('audio.bgm.victory');
  }

  /**
   * Returns whether this battle explicitly returns to its caller on defeat.
   *
   * The default remains the existing game-over flow. Only event-authored
   * battles that pass `event_defeat_policy => continue` opt into returning a
   * defeat result to the suspended event session.
   */
  public function continuesAfterDefeat(): bool
  {
    return ($this->config?->settings['event_defeat_policy'] ?? 'game_over') === 'continue';
  }

  /**
   * Sets the state of the scene.
   *
   * @param BattleSceneState $state The state to set.
   * @return void
   */
  public function setState(BattleSceneState $state): void
  {
    $this->sceneStateContext = new SceneStateContext($this, $this->sceneStateContext);
    $this->state?->exit();
    $this->state = $state;
    $this->state->enter();
  }

  /** Pause retains the running state and its engine context; it is not a new battle entry. */
  public function pauseBattle(): void
  {
    if (!$this->state instanceof BattleRunState || $this->pauseState === null
      || $this->getGame()->hasStopped() || $this->getGame()->sceneManager->currentScene !== $this) { return; }
    $frame = $this->getPresentationCanvas();
    $runtime = $this->getGame()->getRendererRuntime();
    if ($frame !== null && $this->pauseSkin !== null && $runtime !== null) {
      GraphicalBattlePause::preflight($this->pauseSkin, $runtime->getAssetRoot(), $frame->images);
    }
    $this->pauseState->retainFrame($frame);
    $this->state->suspend();
    $this->state = $this->pauseState;
    $this->state->enter();
  }

  public function resumeBattle(): void
  {
    if (!$this->state instanceof BattlePauseState || $this->runState === null
      || $this->state->menu === null
      || $this->getGame()->hasStopped() || $this->getGame()->sceneManager->currentScene !== $this) { return; }
    $this->state->exit();
    InputManager::resetState(true);
    $this->state = $this->runState;
    $this->state->resume();
    Console::recomposeFrame(fn() => $this->ui?->refresh());
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function configure(SceneConfigurationInterface $config): void
  {
    if (! $config instanceof BattleConfig) {
      throw new RuntimeException('Invalid configuration type.');
    }

    $presentation = null;
    $layout = null;
    $resultsSkin = null;
    $pauseSkin = null;
    $runtime = $this->getGame()->getRendererRuntime();
    // Terminal play never loads the optional catalog or inspects any PNG.
    // Graphical presentation is optional by contract: any failure while
    // assembling it - a stale crop, a missing texture, an undersized skin,
    // a missing capability - is logged loudly and the battle degrades to
    // the terminal presentation instead of failing to start. Presentation
    // must never change whether combat happens.
    if ($runtime !== null) {
      try {
        $catalog = BattlePresentationCatalog::load($runtime->getAssetRoot());
      if ($catalog === null && isset($config->settings['battleArena'])) {
        throw new RuntimeException('An explicit battleArena requires a battle presentation catalog.');
      }
      if ($catalog !== null) {
        $presentation = GraphicalBattlePresentation::prepare($config, $catalog, $runtime->getAssetRoot());
        $layout = $presentation?->arena ?? $catalog->ui;
        $resultsSkin = $catalog->results;
        $pauseSkin = $catalog->pause;
        if ($pauseSkin !== null) {
          try {
            if ($layout === null || $layout->width < 520 || $layout->height < 320) {
              throw new RuntimeException('The Pause skin requires a battle canvas of at least 520 by 320.');
            }
            GraphicalBattlePause::preflight($pauseSkin, $runtime->getAssetRoot(), $presentation?->frame()->images ?? []);
          } catch (Throwable $failure) {
            Debug::warn('Pause artwork unavailable; retaining the default pause menu: ' . $failure->getMessage());
            $pauseSkin = null;
          }
        }
        try {
          if ($resultsSkin !== null && ($layout === null || $layout->width !== PresentationCanvas::DEFAULT_WIDTH || $layout->height !== PresentationCanvas::DEFAULT_HEIGHT)) {
            throw new RuntimeException(sprintf('The Results skin requires a %d by %d battle canvas.', PresentationCanvas::DEFAULT_WIDTH, PresentationCanvas::DEFAULT_HEIGHT));
          }
          if ($resultsSkin !== null) {
            foreach ([RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::SPRITE_SOURCE_RECT,
              RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::CANVAS_GLYPH_EFFECTS] as $capability) {
              if (!$runtime->supports($capability)) { throw new RuntimeException('Results require negotiated ' . $capability); }
            }
            $resultsSkin = GraphicalBattleResults::prepare($resultsSkin, $runtime->getAssetRoot(),
              $presentation?->frame()->images ?? [],
              array_map(static fn(Character $member): string => $member->actorId, $config->party->members->toArray()));
          }
        } catch (Throwable $failure) {
          Debug::warn('Results artwork unavailable; retaining terminal results: ' . $failure->getMessage());
          $resultsSkin = null;
        }
        if ($presentation === null && $layout !== null) {
          GraphicalBattleHud::preflight($layout, $runtime->getAssetRoot());
        }
        $capabilities = $presentation?->requiredCapabilities()
          ?? ($layout === null ? [] : [RendererSessionConfig::SPRITE_SOURCE_RECT, ...$catalog->requiredCapabilities()]);
        $capabilities = array_unique([...$capabilities, ...($resultsSkin === null ? [] :
          [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::SPRITE_SOURCE_RECT,
            RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::CANVAS_GLYPH_EFFECTS])]);
        foreach ($capabilities as $capability) {
          if (!$runtime->supports($capability)) {
            throw new RuntimeException("Configured graphical battles require the negotiated {$capability} capability.");
          }
        }
      }
      } catch (Throwable $presentationFailure) {
        Debug::error(sprintf(
          'Graphical battle presentation degraded to the terminal presentation: %s',
          $presentationFailure->getMessage(),
        ));
        $presentation = null;
        $layout = null;
        $resultsSkin = null;
        $pauseSkin = null;
      }
    }
    $this->graphicalPresentation = $presentation;
    $this->battleUiLayout = $layout;
    $this->resultsSkin = $resultsSkin;
    $this->pauseSkin = $pauseSkin;
    if ($this->pauseState?->hasOwnedResources()) { $this->pauseState->exit(); }
    $this->endResults();

    // The field HUD only exists once the game scene has built it. A battle
    // started from anywhere else (the arena) has none to hide.
    if (isset($this->uiManager->locationHUDWindow)) {
      $this->uiManager->locationHUDWindow->deactivate();
    }
    $this->config = $config;
    $this->result = null;
    $this->resultWindow = null;
    $this->shouldLoadGameOver = false;
    $gameScene = $this->getGame()->sceneManager->findScene(GameScene::class);
    $worldState = $gameScene instanceof GameScene ? $gameScene->gameState : new GameState();
    $catalog = ConfigStore::has(BattleEntryRuleCatalog::class)
      ? ConfigStore::get(BattleEntryRuleCatalog::class)
      : BattleEntryRuleCatalog::empty();

    if (! $catalog instanceof BattleEntryRuleCatalog) {
      throw new RuntimeException('The configured battle-entry rule catalog is invalid.');
    }

    (new BattleEntryRuleRunner($catalog))->apply($config, $worldState);
    $this->initializeBattleSceneStates();
    BattleCommandCatalog::beginBattle();
    try {
      $this->setState($this->startState);
    } catch (Throwable $error) {
      BattleCommandCatalog::endBattle();
      throw $error;
    }
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if ($this->getGame()->hasStopped() || $this->getGame()->sceneManager->currentScene !== $this) { return; }
    if ($this->state instanceof BattlePauseState) {
      $this->state->execute($this->sceneStateContext);
      return;
    }
    parent::update();
    if ($this->getGame()->hasStopped() || $this->getGame()->sceneManager->currentScene !== $this) { return; }
    if (!$this->state) {
        throw new RuntimeException('Battle scene state is not initialized.');
    }
    $this->state->execute($this->sceneStateContext);
  }

  /**
   * Restores the active battle composition after a blocking overlay.
   *
   * Notifications and modals may cover battle cells while the scene is
   * suspended. The field scene already redraws on resume; battles must obey
   * the same lifecycle contract instead of depending on a later command or
   * ATB update to repaint only part of the UI.
   */
  #[Override]
  public function resume(): void
  {
    if ($this->getGame()->hasStopped() || $this->getGame()->sceneManager->currentScene !== $this) { return; }
    parent::resume();

    if ($this->state instanceof BattlePauseState) {
      if ($this->state->menu === null) { return; }
      Console::recomposeFrame(fn() => $this->ui?->refresh());
      $this->state->resume();
      return;
    }
    $this->state?->resume();

    if (! $this->ui || $this->state instanceof BattleStartState) {
      return;
    }

    if ($this->state instanceof BattleVictoryState || $this->state instanceof BattleDefeatState) {
      $this->ui->renderField();
      $this->ui->hideControls();
      $this->resultWindow?->render();
      return;
    }

    $this->ui->refresh();
  }

  /**
   * Forwards suspension to the active battle state.
   */
  #[Override]
  public function suspend(): void
  {
    parent::suspend();
    $this->state?->suspend();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function onScreenResize(int $width, int $height): void
  {
    parent::onScreenResize($width, $height);

    if (! $this->ui) {
      return;
    }

    $this->ui->refreshLayout();
    $this->resultWindow?->refreshLayout();
    Console::clear();

    if ($this->state instanceof BattleStartState) {
      return;
    }

    if ($this->state instanceof BattleVictoryState || $this->state instanceof BattleDefeatState) {
      $this->ui->renderField();
      $this->ui->hideControls();

      if ($this->resultWindow) {
        $this->resultWindow->render();
      }
      return;
    }

    $this->ui->refresh();

    if ($this->state instanceof BattlePauseState) {
      $this->state->render();
    }
  }

  /**
   * Initializes the battle scene states.
   *
   * @return void
   */
  protected function initializeBattleSceneStates(): void
  {
    $this->sceneStateContext = new SceneStateContext($this);
    $this->endState = new BattleEndState($this->sceneStateContext);
    $this->defeatState = new BattleDefeatState($this->sceneStateContext);
    $this->pauseState = new BattlePauseState($this->sceneStateContext);
    $this->runState = new BattleRunState($this->sceneStateContext);
    $this->startState = new BattleStartState($this->sceneStateContext);
    $this->victoryState = new BattleVictoryState($this->sceneStateContext);
  }
}
