<?php

namespace Ichiloto\Engine\Scenes;

use Assegai\Collections\ItemList;
use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Enumerations\BattleEngineType;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Core\Game;
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
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleLoader;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

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
   * @var class-string|null The scene a battle was started from, so it can be
   * returned to. A fight from the field goes back to the field; one from the
   * arena goes back to the arena.
   */
  protected ?string $sceneBeforeBattle = null;

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

  /**
   * Remove scenes from the scene manager.
   *
   * @param SceneInterface ...$scenes The scenes to remove.
   * @return $this The scene manager.
   */
  public function removeScenes(SceneInterface ...$scenes): self
  {
    foreach ($scenes as $scene) {
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
    foreach ($this->scenes as $scene) {
      $scene->stop();
    }
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
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
    $this->currentScene?->update();
  }

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

  /**
   * Load a scene.
   *
   * @param string|int $index The index of the scene to load.
   * @return SceneManager The scene manager.
   * @throws NotFoundException
   */
  public function loadScene(string|int $index): self
  {
    $sceneToLoad = match(true) {
      is_int($index) => $this->scenes->toArray()[$index] ?? throw new NotFoundException($index),
      default => $this->scenes->find(fn(SceneInterface $scene) => $scene::class === $index) ?? throw new NotFoundException($index),
    };

    if ($this->currentScene === $sceneToLoad) {
      return $this;
    }

    $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_START, $this->currentScene));

    $this->unloadScene($this->currentScene);
    $this->currentScene = $sceneToLoad;
    if ($this->currentScene?->isStarted()) {
      $this->currentScene?->resume();
    } else {
      $this->currentScene?->start();
    }

    $this->applySceneBackgroundMusic($this->currentScene);

    $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::LOAD_END, $this->currentScene));

    return $this;
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

    if ($this->scenes->contains($scene)) {
      $scene->stop();
      $this->eventManager->dispatchEvent(new SceneEvent(SceneEventType::UNLOAD, $this->currentScene));
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
    // Remembered so the battle goes back where it came from. A fight started
    // from the field returns to the field; one started from the arena returns
    // to the arena.
    $this->sceneBeforeBattle = $this->currentScene instanceof BattleScene
      ? $this->sceneBeforeBattle
      : $this->currentScene::class;

    if ($party->isDefeated()) {
      $this->loadGameOverScene();
      return;
    }

    $this->game->audioManager->playSystemSound(SystemSound::BATTLE_START);

    $config = $this->battleLoader->newConfig($party, $troop, $events, $extraSettings);
    $this->game->useBattleEngineType(BattleEngineType::fromValue($config->settings['engine'] ?? null));
    $currentScene = $this->loadScene(BattleScene::class)->currentScene;

    if (! $currentScene instanceof BattleScene) {
      throw new NotFoundException('The current scene is not a battle scene.');
    }

    $currentScene->configure($config);

    // The battle's runtime settings may override the battle theme, and they
    // only become known during configure(), after the scene transition has
    // already applied music. Re-applying here is a no-op for the common case.
    $this->applySceneBackgroundMusic($currentScene);
  }

  /**
   * Returns the player to the game scene and re-renders the field.
   *
   * @return void
   * @throws NotFoundException If the game scene cannot be found.
   */
  public function returnFromBattleScene(): void
  {
    $this->loadScene($this->sceneToReturnTo());
    $this->sceneBeforeBattle = null;

    if ($this->currentScene instanceof GameScene) {
      $this->currentScene->fieldState?->resume();
    }
  }

}
