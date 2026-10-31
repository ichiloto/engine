<?php

namespace Ichiloto\Engine\Scenes;

use Ichiloto\Engine\IO\Console\Console;

use Assegai\Collections\ItemList;
use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Enumerations\BattleEngineType;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameStartLocation;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\Enumerations\SceneEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\SceneEvent;
use Ichiloto\Engine\Exceptions\IchilotoException;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Rendering\ScreenTransitionCatalog;
use Ichiloto\Engine\Rendering\ScreenTransitionPhase;
use Ichiloto\Engine\Rendering\ScreenTransitionSession;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleLoader;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\Interfaces\ConfigurableSceneInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Throwable;

/**
 * SceneManager is a class that manages scenes.
 *
 * @package Ichiloto\Engine\Scenes
 */
class SceneManager implements CanStart, CanRender, CanUpdate
{
  /**
   * The instance of this singleton.
   * @var SceneManager|null
   */
  protected static ?SceneManager $instance = null;
  /**
   * @var EventManager The event manager.
   */
  protected EventManager $eventManager;
  /**
   * @var SaveManager The save manager.
   */
  protected(set) SaveManager $saveManager;
  /**
   * The scenes in the scene manager.
   * @var ItemList<SceneInterface>
   */
  protected ItemList $scenes;
  /**
   * The current scene.
   * @var SceneInterface|null
   */
  public ?SceneInterface $currentScene = null {
    get {
      return $this->currentScene;
    }
  }
  /**
   * @var BattleLoader The battle loader.
   */
  protected(set) BattleLoader $battleLoader;
  /**
   * @var class-string|null Legacy battle return lookup hint. The exact retained
   * owner is suspendedCaller; this hint never acquires generic excursion ownership.
   */
  protected ?string $sceneBeforeBattle = null;
  /** The exact return owner, shared by battle and generic excursions. */
  private ?SceneInterface $suspendedCaller = null;
  private ?SceneExcursionInvocation $sceneExcursion = null;
  private bool $changingExcursion = false;
  private bool $stopping = false;
  private ?ScreenTransitionSession $sceneTransition = null;
  private float $transitionTime = 0;
  private bool $changingCoveredScene = false;
  private bool $transitionFocusPaused = false;

  public function hasSceneTransition(): bool { return $this->sceneTransition !== null; }

  /**
   * SceneManager constructor.
   */
  private function __construct(public Game $game {
    get {
      return $this->game;
    }
  })
  {
    $this->scenes = new ItemList(SceneInterface::class);
    $this->eventManager = EventManager::getInstance($this->game);
    $this->saveManager = SaveManager::getInstance($this->game);
    $this->battleLoader = BattleLoader::getInstance($this->game);
  }

  /**
   * Return the instance of the scene manager.
   *
   * @param Game $game The game.
   * @return SceneManager
   */
  public static function getInstance(Game $game): SceneManager
  {
    if (self::$instance === null) {
      self::$instance = new SceneManager($game);
    }

    return self::$instance;
  }

  /**
   * Add scenes to the scene manager.
   *
   * @param SceneInterface ...$scenes The scenes to add.
   * @return $this The scene manager.
   */
  public function addScenes(SceneInterface ...$scenes): self
  {
    foreach ($scenes as $scene) {
      $this->scenes->add($scene);
    }

    return $this;
  }

  /** Updates all camera viewports without starting or redrawing inactive scenes. */
  public function resizeViewports(int $width, int $height): void
  {
    foreach ($this->scenes as $scene) {
      $scene->camera->resizeViewport($width, $height);
    }
  }

  /**
   * Remove scenes from the scene manager.
   *
   * @param SceneInterface ...$scenes The scenes to remove.
   * @return $this The scene manager.
   */
  public function removeScenes(SceneInterface ...$scenes): self
  {
    foreach ($scenes as $scene) {
      if ($this->sceneExcursion !== null
        && ($scene === $this->suspendedCaller || $scene === $this->sceneExcursion->scene)) {
        $this->disposeSceneExcursion(SceneExcursionOutcome::abandon());
      }
      $this->scenes->remove($scene);
    }


    return $this;
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    $this->currentScene?->start();
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    $this->stopping = true;
    $failure = null;
    try {
      if ($this->sceneExcursion !== null) {
        $this->disposeSceneExcursion(SceneExcursionOutcome::abandon());
      }
    } catch (Throwable $error) { $failure = $error; }
    try { $this->sceneTransition?->cancel(); } catch (Throwable $error) { $failure = $error; }
    foreach ($this->scenes as $scene) {
      try {
        // Include the current scene even if startup failed partway through.
        if ($scene === $this->currentScene || $scene->isStarted()) {
          $scene->stop();
        }
      } catch (Throwable $error) {
        $failure ??= $error;
      }
    }
    $this->sceneBeforeBattle = null;
    $this->suspendedCaller = null;

    if ($failure !== null) {
      throw $failure;
    }
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    if ($this->sceneTransition !== null) { return; }
    $this->currentScene?->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->currentScene?->erase();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if ($this->sceneExcursion !== null && (!$this->canResumeExcursionCaller()
      || $this->currentScene !== $this->sceneExcursion->scene)) {
      $this->disposeSceneExcursion(SceneExcursionOutcome::abandon());
      return;
    }
    if ($this->sceneTransition !== null) {
      if (Input::isButtonDown('quit')) { $this->game->quit(); return; }
      $now = $this->getTransitionTime();
      $delta = max(0, $now - $this->transitionTime);
      $this->transitionTime = $now;
      if (!$this->game->getRendererRuntime()->windowActive) {
        $this->sceneTransition->pause();
        $this->transitionFocusPaused = true;
        return;
      }
      if ($this->transitionFocusPaused) {
        $this->sceneTransition->resume();
        $this->transitionFocusPaused = false;
        $delta = 0;
      }
      $this->sceneTransition->update($delta);
      return;
    }
    $this->currentScene?->update();
  }

  protected function getTransitionTime(): float { return hrtime(true) / 1_000_000_000; }

  /**
   * Returns a registered scene by class name without loading it.
   *
   * Lets a running scene reach a sibling's state — the battle scene
   * recording into the field scene's bestiary, for instance.
   *
   * @param class-string $className The scene class.
   * @return SceneInterface|null The scene, or null when not registered.
   */
  public function findScene(string $className): ?SceneInterface
  {
    return $this->scenes->find(fn(SceneInterface $scene) => $scene::class === $className);
  }

  /** Registration follows object identity, not the first matching class. */
  public function hasRegisteredScene(SceneInterface $scene): bool
  {
    return $this->scenes->contains($scene);
  }

  /** Title commands and tooling share the same new-game data and scene lifecycle. */
  public function startNewGame(?GameLoader $loader = null, ?GameStartLocation $start = null, ?CinematicDefinition $cinematic = null): GameScene
  {
    $config = ($loader ?? GameLoader::getInstance($this->game))->loadNewGame($start);
    $scene = $this->loadScene(GameScene::class)->currentScene;
    if (!$scene instanceof GameScene) {
      throw new NotFoundException('The current scene is not a game scene.');
    }
    if ($cinematic === null) {
      $scene->configure($config);
    } else {
      $scene->configureForLaunch($config, $cinematic);
    }

    return $scene;
  }

  /** Activate a decoded save; file inspection and new-game launch remain separate. */
  public function loadSavedGame(GameConfig $config): GameScene
  {
    $scene = $this->loadScene(GameScene::class)->currentScene;
    if (!$scene instanceof GameScene) {
      throw new NotFoundException('The current scene is not a game scene.');
    }
    $scene->configureSavedGame($config);
    return $scene;
  }

  /**
   * Load a scene.
   *
   * @param string|int $index The index of the scene to load.
   * @param bool $suspendCurrent Retain the battle caller recorded by loadBattleScene().
   * @return SceneManager The scene manager.
   * @throws NotFoundException
   */
  public function loadScene(string|int $index, bool $suspendCurrent = false): self
  {
    $sceneToLoad = $this->getRegisteredScene($index);
    if ($this->changingExcursion || $this->stopping) {
      throw new \LogicException('The scene lifecycle is already changing or stopped.');
    }
    if ($this->sceneExcursion !== null) {
      if ($suspendCurrent) {
        throw new \LogicException('An excursion already owns the suspended caller.');
      }
      // Even navigation to the caller is abandonment, never an implicit successful return.
      $this->disposeSceneExcursion(SceneExcursionOutcome::abandon());
    }

    return $this->loadRegisteredScene($sceneToLoad, $suspendCurrent);
  }

  private function getRegisteredScene(string|int $index): SceneInterface
  {
    return match(true) {
      is_int($index) => $this->scenes->toArray()[$index] ?? throw new NotFoundException($index),
      default => $this->findScene($index) ?? throw new NotFoundException($index),
    };
  }

  private function loadRegisteredScene(SceneInterface $sceneToLoad, bool $suspendCurrent = false): self
  {

    if ($this->sceneTransition !== null && !$this->changingCoveredScene) {
      $this->sceneTransition->cancel();
    }

    if ($suspendCurrent && (! $sceneToLoad instanceof BattleScene
      || $this->currentScene instanceof BattleScene
      || ($this->currentScene !== null && $this->suspendedCaller !== $this->currentScene))) {
      throw new \LogicException('Only a recorded battle caller can be suspended for a battle.');
    }

    if ($this->currentScene === $sceneToLoad) {
      return $this;
    }

    $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_START, $this->currentScene));

    if ($suspendCurrent) {
      $this->currentScene?->suspend();
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::SUSPEND, $this->currentScene));
    } else {
      $suspended = $this->suspendedCaller;
      $this->suspendedCaller = null;
      $this->sceneBeforeBattle = null;
      $outgoing = [$this->currentScene];
      // Going somewhere other than the battle's caller abandons that caller.
      if ($suspended !== null && $suspended !== $sceneToLoad && $suspended !== $this->currentScene) {
        $outgoing[] = $suspended;
      }
      $failure = null;
      foreach ($outgoing as $scene) {
        try {
          $this->unloadScene($scene);
        } catch (Throwable $error) {
          $failure ??= $error;
        }
      }
      if ($failure !== null) {
        throw $failure;
      }
    }
    $this->currentScene = $sceneToLoad;
    if ($this->currentScene?->isStarted()) {
      $this->currentScene?->resume();
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::RESUME, $this->currentScene));
    } else {
      $this->currentScene?->start();
    }

    $this->applySceneBackgroundMusic($this->currentScene);

    $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_END, $this->currentScene));

    return $this;
  }

  /** Begin an owned, non-nestable excursion without stopping the calling event's scene. */
  public function beginSceneExcursion(SceneInterface $caller, string|int $index,
    SceneConfigurationInterface $config): SceneExcursionSession
  {
    $scene = $this->getRegisteredScene($index);
    if ($this->stopping || $this->changingExcursion || $this->sceneExcursion !== null
      || $this->suspendedCaller !== null || $this->sceneBeforeBattle !== null
      || $this->sceneTransition !== null || $caller !== $this->currentScene
      || !$this->scenes->contains($caller) || !$caller->isStarted()
      || $caller === $scene || $scene->isStarted() || $scene instanceof BattleScene) {
      throw new \LogicException('The scene excursion does not have an available live caller and target.');
    }
    if (!$scene instanceof ConfigurableSceneInterface) {
      throw new \LogicException('The excursion scene must implement ConfigurableSceneInterface.');
    }
    $this->assertNoExcursionModal($caller);
    [$session, $resolve] = SceneExcursionSession::createOwnedSession();
    $invocation = new SceneExcursionInvocation($scene, $session, $resolve);
    $this->sceneExcursion = $invocation;
    $this->suspendedCaller = $caller;
    $this->changingExcursion = true;
    try {
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_START, $caller));
      $this->assertCurrentExcursion($invocation, $caller);
      $caller->suspend();
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::SUSPEND, $caller));
      $this->assertCurrentExcursion($invocation, $caller);
      InputManager::resetState(true);
      $this->assertCurrentExcursion($invocation, $caller);
      $this->currentScene = $scene;
      Console::recomposeFrame(function () use ($scene, $config, $invocation): void {
        $scene->configure($config);
        $this->assertCurrentExcursion($invocation);
        $scene->start();
        $this->assertCurrentExcursion($invocation);
      });
      $this->applySceneBackgroundMusic($scene);
      $this->assertCurrentExcursion($invocation);
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_END, $scene));
      $this->assertCurrentExcursion($invocation);
      return $session;
    } catch (Throwable $error) {
      if ($this->sceneExcursion === $invocation) {
        $this->disposeSceneExcursion(SceneExcursionOutcome::fail($error), restoreCaller: true);
      }
      throw $error;
    } finally {
      $this->changingExcursion = false;
    }
  }

  /** The active target can obtain its identity during configuration; callers cannot borrow it. */
  public function getSceneExcursionSession(SceneInterface $scene): ?SceneExcursionSession
  {
    return $this->currentScene === $scene && $this->sceneExcursion?->scene === $scene
      ? $this->sceneExcursion->session : null;
  }

  /** The active target can read its exact live caller, including during configuration and startup. */
  public function getSceneExcursionCaller(SceneInterface $target): ?SceneInterface
  {
    return $this->currentScene === $target && $this->sceneExcursion?->scene === $target
      && $this->canResumeExcursionCaller() ? $this->suspendedCaller : null;
  }

  /** Game supplies completion or player-cancellation data; event cancellation uses abandonment. */
  public function finishSceneExcursion(SceneExcursionSession $session, SceneExcursionOutcome $outcome): void
  {
    $invocation = $this->requireSceneExcursion($session);
    if (!in_array($outcome->status, [SceneExcursionStatus::COMPLETED, SceneExcursionStatus::CANCELLED], true)) {
      throw new \LogicException('Game must supply a completion or cancellation result.');
    }
    if (!$this->canResumeExcursionCaller() || $this->currentScene !== $invocation->scene) {
      $this->disposeSceneExcursion(SceneExcursionOutcome::abandon());
      throw new \LogicException('The scene excursion caller or current scene was replaced or stopped.');
    }
    $this->assertNoExcursionModal($invocation->scene);
    $this->disposeSceneExcursion($outcome, restoreCaller: true);
  }

  /** A cancelled waiting operation must never restart or resume its abandoned event. */
  public function abandonSceneExcursion(SceneExcursionSession $session): bool
  {
    // Cancellation can re-enter from caller.stop(); stale handles have no authority over a new owner.
    if ($this->sceneExcursion?->session !== $session || $session->outcome !== null) { return false; }
    $this->disposeSceneExcursion(SceneExcursionOutcome::abandon());
    return true;
  }

  private function requireSceneExcursion(SceneExcursionSession $session): SceneExcursionInvocation
  {
    if ($this->changingExcursion || $this->sceneExcursion?->session !== $session || $session->outcome !== null) {
      throw new \LogicException('The scene excursion handle is stale or its lifecycle is changing.');
    }
    return $this->sceneExcursion;
  }

  private function assertCurrentExcursion(SceneExcursionInvocation $invocation, ?SceneInterface $expectedScene = null): void
  {
    if ($this->stopping || $this->sceneExcursion !== $invocation || $this->currentScene !== ($expectedScene ?? $invocation->scene)
      || !$this->canResumeExcursionCaller()) {
      throw new \LogicException('The scene excursion lost its live owner during startup.');
    }
  }

  private function canResumeExcursionCaller(): bool
  {
    return !$this->stopping && $this->suspendedCaller !== null
      && $this->scenes->contains($this->suspendedCaller) && $this->suspendedCaller->isStarted();
  }

  /** AbstractScene reports a true stop, invalidating even a caller restarted before the next tick. */
  public function notifySceneStopped(SceneInterface $scene): void
  {
    if ($this->suspendedCaller === $scene) {
      if ($this->sceneExcursion !== null) {
        $this->disposeSceneExcursion(SceneExcursionOutcome::abandon());
      } elseif ($this->changingExcursion) {
        $this->suspendedCaller = null;
      }
    }
  }

  private function assertNoExcursionModal(SceneInterface $scene): void
  {
    if (ModalManager::getInstance($this->game)->currentModal !== null) {
      throw new \LogicException('Close the shared modal before changing scene excursion ownership.');
    }
    foreach ($scene->getUI()->getActivePresentations() as $presentation) {
      if ($presentation instanceof ModalInterface) {
        throw new \LogicException('Close the active modal presentation before changing scene excursion ownership.');
      }
    }
  }

  /** Detach ownership before cleanup so event cancellation cannot recursively dispose the caller. */
  private function disposeSceneExcursion(SceneExcursionOutcome $outcome, bool $restoreCaller = false): void
  {
    $invocation = $this->sceneExcursion;
    if ($invocation === null) { return; }
    $caller = $this->suspendedCaller;
    $restoreCaller = $restoreCaller && $this->canResumeExcursionCaller()
      && ($this->currentScene === $invocation->scene || $this->currentScene === $caller);
    $this->sceneExcursion = null;
    $wasChanging = $this->changingExcursion;
    $this->changingExcursion = true;
    $failure = null;
    try {
      // No scene may react to CLOSE while outgoing singleton modal state is released.
      if ($this->currentScene === $invocation->scene || $this->currentScene === $caller) {
        $this->currentScene = null;
      }
      try { $invocation->scene->stop(); } catch (Throwable $error) { $failure = $error; }
      try { InputManager::resetState(true); } catch (Throwable $error) { $failure ??= $error; }
      try { $this->assertNoExcursionModal($invocation->scene); } catch (Throwable $error) { $failure ??= $error; }
      if ($failure === null && $restoreCaller && !$this->stopping && $caller?->isStarted()
        && $this->scenes->contains($caller) && $this->currentScene === null) {
        $this->currentScene = $caller;
        try {
          // Caller resume may own a complete Console frame (the field does); do not nest it.
          $caller->camera->resume();
          $caller->resume();
          if ($this->stopping || $this->suspendedCaller !== $caller || $this->currentScene !== $caller
            || !$caller->isStarted() || !$this->scenes->contains($caller)) {
            throw new \LogicException('The excursion caller was replaced or stopped during return.');
          }
          $this->applySceneBackgroundMusic($caller);
          $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::RESUME, $caller));
          $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_END, $caller));
        } catch (Throwable $error) { $failure = $error; }
      } else {
        $restoreCaller = false;
      }
      if (!$restoreCaller || $failure !== null || $this->stopping) {
        if ($this->currentScene === $caller) { $this->currentScene = null; }
        try { if ($caller?->isStarted()) { $caller->stop(); } } catch (Throwable $error) { $failure ??= $error; }
      }
      if ($this->currentScene === null) {
        try { $this->game->audioManager->stopBackgroundMusic(); } catch (Throwable $error) { $failure ??= $error; }
      }
    } finally {
      $this->suspendedCaller = null;
      if ($failure !== null && $outcome->failure !== null) {
        $failure = new \RuntimeException($outcome->failure->getMessage() . '; excursion cleanup failed: '
          . $failure->getMessage(), previous: $outcome->failure);
      }
      $invocation->settle($failure === null ? $outcome : SceneExcursionOutcome::fail($failure));
      $this->changingExcursion = $wasChanging;
    }
    if ($failure !== null) { throw $failure; }
  }

  /**
   * Applies the scene's declared background music.
   *
   * This is the single choke point for scene music: every transition either
   * starts the incoming scene's track (a no-op when it is already playing) or
   * stops the music when the scene declares none, so an outgoing scene's
   * music can never bleed into a scene that did not ask for it.
   *
   * Scenes that determine their track after loading (e.g. the game scene,
   * whose track comes from the map that is loaded during configure()) start
   * their music from that later step instead.
   *
   * @param SceneInterface|null $scene The scene that was just made current.
   * @return void
   */
  protected function applySceneBackgroundMusic(?SceneInterface $scene): void
  {
    if ($scene === null) {
      return;
    }

    if ($scene instanceof GameScene) {
      $scene->restoreBackgroundMusic();
      return;
    }

    $track = $scene->getBackgroundMusic();

    if ($track !== null) {
      $this->game->audioManager->playBackgroundMusic($track);
    } else {
      $this->game->audioManager->stopBackgroundMusic();
    }
  }

  /**
   * Unload a scene.
   *
   * @param SceneInterface|null $scene The scene to unload.
   * @return SceneManager The scene manager.
   */
  public function unloadScene(?SceneInterface $scene): self
  {
    if (!$scene) {
      return $this;
    }

    if ($this->sceneExcursion !== null
      && ($scene === $this->suspendedCaller || $scene === $this->sceneExcursion->scene)) {
      $this->disposeSceneExcursion(SceneExcursionOutcome::abandon());
      return $this;
    }

    if ($this->scenes->contains($scene)
      && ($scene->isStarted() || ($scene instanceof GameScene && $scene->hasUnstableEventSession()))) {
      $scene->stop();
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::UNLOAD, $scene));
    }

    return $this;
  }

  /**
   * Returns the scene a finished battle goes back to.
   *
   * A fight started from the field returns to the field. One started from
   * somewhere else, the arena, returns there instead, so a developer testing
   * a battle is not dumped into a map they never loaded.
   *
   * @return class-string The scene to load.
   */
  protected function sceneToReturnTo(): string
  {
    if ($this->suspendedCaller !== null && $this->scenes->contains($this->suspendedCaller)) {
      return $this->suspendedCaller::class;
    }
    $previous = $this->sceneBeforeBattle;

    return $previous !== null && $this->findScene($previous) !== null
      ? $previous
      : GameScene::class;
  }

  /**
   * Load the game over scene.
   *
   * @return void
   * @throws NotFoundException If the game over scene is not found.
   */
  public function loadGameOverScene(): void
  {
    $this->loadScene(GameOverScene::class);
  }

  /**
   * Load the battle scene.
   *
   * @param Party $party The party.
   * @param Troop $troop The troop.
   * @param object[] $events
   * @return void
   * @throws IchilotoException If an error occurs while loading the battle scene.
   * @throws NotFoundException If the battle scene is not found.
   */
  public function loadBattleScene(Party $party, Troop $troop, array $events = [], array $extraSettings = []): void
  {
    if ($this->stopping || $this->changingExcursion || $this->sceneExcursion !== null || $this->suspendedCaller !== null
      || $this->currentScene instanceof BattleScene || $this->sceneBeforeBattle !== null || $this->sceneTransition !== null) {
      throw new \LogicException('A battle already owns the scene transition.');
    }

    $config = $this->battleLoader->newConfig($party, $troop, $events, $extraSettings);
    $config->partyRoster->promoteReservesAfterWipeout();
    if ($config->partyRoster->isDefeated) {
      $this->loadGameOverScene();
      return;
    }

    $this->game->audioManager->playSystemSound(SystemSound::BATTLE_START);

    $this->game->useBattleEngineType(BattleEngineType::fromValue($config->settings['engine'] ?? null));
    // Only acquire the return owner after configuration preparation succeeds.
    $this->sceneBeforeBattle = $this->currentScene === null ? null : $this->currentScene::class;
    $this->suspendedCaller = $this->currentScene;
    $runtime = $this->game->getRendererRuntime();
    $treatment = null;
    if ($runtime !== null && !Accessibility::prefersReducedMotion()
      && ScreenTransition::isBattleEnabled()) {
      try {
        $treatment = ScreenTransitionCatalog::load($runtime->getAssetRoot())?->getBattleTreatment();
        if ($treatment !== null) {
          $treatment->validateAssets($runtime->getAssetRoot());
          $runtime->beginScreenHandoff($treatment);
        }
      } catch (Throwable $error) {
        Debug::warn('Graphical battle transition unavailable; using a direct cut: ' . $error->getMessage());
        $treatment = null;
      }
    }
    if ($treatment === null) {
      $this->enterBattleScene($config);
      if ($runtime !== null && $this->currentScene instanceof BattleScene) { $this->currentScene->completeGraphicalEntry(); }
      return;
    }
    $this->transitionTime = $this->getTransitionTime();
    $this->transitionFocusPaused = false;
    $this->sceneTransition = ScreenTransition::startHandoff($treatment,
      $runtime->setScreenHandoffPhase(...),
      function () use ($config, $runtime): void {
        $this->changingCoveredScene = true;
        try {
          $this->enterBattleScene($config);
          $runtime->replaceScreenHandoff();
          // Prepare and queue the incoming composition while the engine-owned cover is still active.
          $runtime->present($this->currentScene);
        } finally { $this->changingCoveredScene = false; }
      },
      fn(): bool => $this->currentScene instanceof BattleScene && $this->currentScene->isStarted(),
      function () use ($runtime): void {
        $complete = $this->sceneTransition?->phase === ScreenTransitionPhase::COMPLETE;
        $this->sceneTransition = null;
        $runtime->endScreenHandoff();
        InputManager::resetState(true);
        if ($complete && $this->currentScene instanceof BattleScene) { $this->currentScene->completeGraphicalEntry(); }
        if (!$complete && !$this->currentScene instanceof BattleScene) {
          $this->sceneBeforeBattle = null;
          $this->suspendedCaller = null;
        }
      });
  }

  /** The scene swap is owned here, never by native animation completion or a battle state. */
  protected function enterBattleScene(BattleConfig $config): void
  {
    // Replace outgoing field text even when artwork makes the incoming battlefield Console-free.
    // Game-owned overlays remain live; no partially cleared screen reaches either renderer.
    Console::recomposeFrame(function () use ($config): void {
      $currentScene = $this->loadScene(BattleScene::class, suspendCurrent: true)->currentScene;
      if (! $currentScene instanceof BattleScene) {
        throw new NotFoundException('The current scene is not a battle scene.');
      }
      $currentScene->configure($config);
      // Configuration may select a different battle theme after scene loading.
      $this->applySceneBackgroundMusic($currentScene);
    });
  }

  /**
   * Returns the player to the game scene and re-renders the field.
   *
   * @return void
   * @throws NotFoundException If the game scene cannot be found.
   */
  public function returnFromBattleScene(): void
  {
    if (! $this->currentScene instanceof BattleScene) {
      return;
    }

    $battleResult = $this->currentScene->result;

    $caller = $this->suspendedCaller;
    if ($caller !== null && $this->scenes->contains($caller)) {
      $this->loadRegisteredScene($caller);
    } else {
      $this->loadScene($this->sceneToReturnTo());
    }

    if ($this->currentScene instanceof GameScene && $battleResult !== null) {
      $this->currentScene->concludeBattle($battleResult);
    }
  }

}
