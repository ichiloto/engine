<?php

namespace Ichiloto\Engine\Core;

use Assegai\Collections\ItemList;
use Assegai\Util\Debug;
use Assegai\Util\Path;
use Error;
use Exception;
use Ichiloto\Engine\Core\Interfaces\CanRun;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\Events\Interfaces\SubjectInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Throwable;

/**
 * The game.
 *
 * @package Ichiloto\Engine\Core
 */
class Game implements CanRun, SubjectInterface
{
  /**
   * Whether the game is running.
   * @var bool
   */
  protected bool $isRunning = false;
  /**
   * The scene manager.
   * @var SceneManager
   */
  protected SceneManager $sceneManager;
  /**
   * The input manager.
   * @var InputManager
   */
  protected InputManager $inputManager;
  /**
   * The observers.
   * @var ItemList<ObserverInterface>
   */
  protected ItemList $observers;
  protected ItemList $staticObservers;

  /**
   * Game constructor.
   *
   * @param string $name The name of the game.
   * @param int $width The width of the game screen.
   * @param int $height The height of the game screen.
   * @param array<string, mixed> $options The options to configure the game with.
   * @throws Exception
   */
  public function __construct(
    protected string $name,
    protected int $width = DEFAULT_SCREEN_WIDTH,
    protected int $height = DEFAULT_SCREEN_HEIGHT,
    protected array $options = []
  )
  {
    try {
      // Register error and exception handlers
      $this->registerErrorAndExceptionHandlers();

      // Initialize configuration

      $this->initializeDebugger();
      $this->declareManagers();
      $this->declareObservers();

      $this->configure([...$this->options, 'name' => $name, 'screen' => ['width' => $width, 'height' => $height]]);
    } catch (Error|Exception|Throwable $exception) {
      $this->handleException($exception);
    }
  }

  /**
   * Game destructor.
   */
  public function __destruct()
  {
    Console::restoreTerminalSettings();
//    Console::reset();
  }

  /**
   * Configure the game.
   *
   * @param array<string, mixed> $options The options to configure the game with.
   * @return Game
   */
  public function configure(array $options): self
  {
    $this->options = array_merge_recursive($this->options, $options);
    ConfigStore::put(PlaySettings::class, new PlaySettings($this->options));
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
    try {
      $this->start();

      while ($this->isRunning) {
        $this->handleInput();
        $this->update();
        $this->render();
      }

      $this->stop();
    } catch (Exception $exception) {
      $this->handleException($exception);
    }
  }

  /**
   * Start the game.
   */
  protected function start(): void
  {
    // TODO: Implement start() method.

    $this->isRunning = true;
  }

  /**
   * Stop the game.
   */
  protected function stop(): void
  {
    // TODO: Implement stop() method.

    $this->isRunning = false;
  }

  /**
   * Handle the input.
   *
   * @return void
   */
  protected function handleInput(): void
  {
    $this->inputManager->handleInput();
  }

  /**
   * Update the game.
   *
   * @return void
   */
  protected function update(): void
  {
    if (Input::isAnyKeyPressed([KeyCode::Q, KeyCode::q])) {
      $this->stop();
    }

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

  /**
   * Declare the managers.
   *
   * @return void
   */
  private function declareManagers(): void
  {
    $this->sceneManager = SceneManager::getInstance();
  }

  /**
   * Declare the observers.
   *
   * @return void
   */
  private function declareObservers(): void
  {
    $this->observers = new ItemList(ObserverInterface::class);
    $this->staticObservers = new ItemList(StaticObserverInterface::class);
  }

  /**
   * @return void
   */
  public function registerErrorAndExceptionHandlers(): void
  {
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
      $this->handleError($errno, $errstr, $errfile, $errline);
    });
    set_exception_handler(function (Error|Exception|Throwable $exception) {
      $this->handleException($exception);
    });
  }

  /**
   * Initialize the debugger.
   *
   * @return void
   * @throws Exception
   */
  private function initializeDebugger(): void
  {
    $logDirectory = Path::join(Path::getCurrentWorkingDirectory(), 'logs');

    if (!file_exists($logDirectory)) {
      if (false === mkdir($logDirectory, 0777, true)) {
        throw new Exception("Could not create log directory: $logDirectory");
      }
    }
  }

  /**
   * Handle an error.
   *
   * @param int $errno The error number.
   * @param string $errstr The error string.
   * @param string $errfile The error file.
   * @param int $errline The error line.
   * @return never
   */
  private function handleError(int $errno, string $errstr, string $errfile, int $errline): never
  {
    $this->stop();
    exit($errno);
  }

  /**
   * Handle an exception.
   *
   * @param Exception|Throwable|Error $exception The exception to handle.
   * @return never
   */
  private function handleException(Exception|Throwable|Error $exception): never
  {
    Debug::error($exception);
    $this->stop();
    exit($exception->getMessage());
  }

  /**
   * @inheritDoc
   */
  public function addObserver(ObserverInterface|string $observer): void
  {
    if ($observer instanceof ObserverInterface) {
      $this->observers->add($observer);
    } else if (is_a($observer, StaticObserverInterface::class, true)) {
      $this->staticObservers->add($observer);
    }
  }

  /**
   * @inheritDoc
   */
  public function removeObserver(ObserverInterface|string $observer): void
  {
    // TODO: Implement removeObserver() method.
  }

  /**
   * @inheritDoc
   */
  public function notify(object $entity, EventInterface $event): void
  {
    // TODO: Implement notify() method.
  }
}