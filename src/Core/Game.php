<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Core\Interfaces\CanRun;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;

class Game implements CanRun
{
  protected bool $isRunning = false;
  /**
   * The game options.
   * @var array
   */
  protected array $options = [];

  /**
   * The scene manager.
   * @var SceneManager
   */
  protected SceneManager $sceneManager;

  public function __construct(
    protected string $name,
    protected int $width = DEFAULT_SCREEN_WIDTH,
    protected int $height = DEFAULT_SCREEN_HEIGHT
  )
  {
    $this->sceneManager = SceneManager::getInstance();
  }

  /**
   * Configure the game.
   *
   * @param array $options The options to configure the game with.
   * @return Game
   */
  public function configure(array $options): self
  {
    $this->options = array_merge_recursive($this->options, $options);

    return $this;
  }

  /**
   * Add scenes to the game.
   *
   * @param SceneInterface ...$scenes The scenes to add.
   * @return Game The game.
   */
  public function addScenes(SceneInterface ...$scenes): self
  {
    $this->sceneManager->addScenes(...$scenes);

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function run(): void
  {
    $this->start();

    while ($this->isRunning) {
      $this->handleInput();
      $this->update();
      $this->render();
    }

    $this->stop();
  }

  /**
   * Start the game.
   */
  protected function start(): void
  {
    // TODO: Implement start() method.
  }

  /**
   * Stop the game.
   */
  protected function stop(): void
  {
    // TODO: Implement stop() method.
  }

  /**
   * Handle the input.
   *
   * @return void
   */
  protected function handleInput(): void
  {

  }

  /**
   * Update the game.
   *
   * @return void
   */
  protected function update(): void
  {
    $this->sceneManager->update();
  }

  /**
   * Render the game.
   *
   * @return void
   */
  protected function render(): void
  {
    $this->sceneManager->render();
  }
}