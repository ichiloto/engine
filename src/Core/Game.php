<?php

namespace Ichiloto\Engine\Core;

use Assegai\Collections\ItemList;
use Assegai\Util\Path;
use Error;
use Exception;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Core\Enumerations\ChronoUnit;
use Ichiloto\Engine\Core\Interfaces\CanRun;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\GameEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\GameEvent;
use Ichiloto\Engine\Events\GameplayEvent;
use Ichiloto\Engine\Events\GameplayEventType;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\Events\Interfaces\SubjectInterface;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Windows\DebugWindow;
use Ichiloto\Engine\Util\Config\AppConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\InputConfig;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Throwable;

/**
 * The game.
 *
 * @package Ichiloto\Engine\Core
 */
class Game implements CanRun, SubjectInterface
{
  /**
   * @var bool Whether the game is running.
   */
  protected bool $isRunning = false;
  /**
   * @var SceneManager The scene manager.
   */
  protected(set) SceneManager $sceneManager;
  /**
   * @var EventManager $eventManager The event manager.
   */
  protected(set) EventManager $eventManager;
  /**
   * @var ModalManager $modalManager The modal manager.
   */
  protected(set) ModalManager $modalManager;
  /**
   * @var NotificationManager $notificationManager The notification manager.
   */
  protected(set) NotificationManager $notificationManager;
  /**
   * @var int $frameCount The number of frames that have been rendered.
   */
  private int $frameCount = 0;
  /**
   * @var int $frameRate The frame rate of the game.
   */
  private int $frameRate = 0;
  /**
   * @var DebugWindow $debugWindow The debug window.
   */
  protected DebugWindow $debugWindow;
  /**
   * @var ItemList<ObserverInterface> The observers.
   */
  protected ItemList $observers;
  /**
   * @var ItemList<StaticObserverInterface> The static observers.
   */
  protected ItemList $staticObservers;
  /**
   * @var BattleEngineInterface $engine The battle engine.
   */
  protected(set) BattleEngineInterface $engine;
  /**
   * @var ItemStore $itemStore The item store.
   */
  protected(set) ItemStore $itemStore;

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
      $this->configureErrorAndExceptionHandlers();
      $this->initializeObservers();
      $this->initializeConfigStore();
      $this->initializeDebugger();
      $this->initializeManagers();

      $this->configure([...$this->options, 'name' => $name, 'screen' => ['width' => $width, 'height' => $height]]);

      $this->sceneManager
        ->addScenes(
          new TitleScene($this->sceneManager, "Title Screen"),
          new GameScene($this->sceneManager, $this->name),
          new BattleScene($this->sceneManager, "$this->name - Battle Screen"),
          new GameOverScene($this->sceneManager, "$this->name - Game Over Screen")
        );
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
    Console::reset();

    if ($lastError = error_get_last()) {
      $this->handleError($lastError['type'], $lastError['message'], $lastError['file'], $lastError['line']);
    }
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

    foreach ($this->options as $key => $value) {
      ConfigStore::get(PlaySettings::class)->set($key, $value);
    }

    Console::init($this, ['width' => $this->width, 'height' => $this->height]);
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
      $sleepTime = (int)(1000000 / ($this->options['fps'] ?? DEFAULT_FPS));
      $this->start();
      $nextFrameTime = microtime(true) + 1;
      $lastFrameCountSnapShot = $this->frameCount;

      while ($this->isRunning) {
        $this->handleInput();
        $this->update();
        $this->render();

        usleep($sleepTime);

        if (microtime(true) >= $nextFrameTime) {
          $this->frameRate = $this->frameCount - $lastFrameCountSnapShot;
          $lastFrameCountSnapShot = $this->frameCount;
          $nextFrameTime = microtime(true) + 1;
        }
      }
    } catch (Exception $exception) {
      $this->handleException($exception);
    }
  }

  /**
   * Start the game.
   * @throws Exception
   */
  protected function start(): void
  {
    Console::clear();
    Console::saveTerminalSettings();
    Console::setTerminalName($this->name);
    Console::setTerminalSize($this->width, $this->height);
    Console::cursor()->hide();
    InputManager::disableEcho();
    InputManager::enableNonBlockingMode();

    $this->showSplashScreens();
    $this->buildItemStore();
    $this->handleGameEvents();

    $this->sceneManager->loadScene(0);
    $this->addObserver(Time::class);

    $this->isRunning = true;

    $this->notify($this, new GameEvent(GameEventType::START));
  }

  /**
   * Stop the game.
   */
  protected function stop(): void
  {
    // Disable non-blocking input mode
    InputManager::disableNonBlockingMode();

    // Enable echo
    InputManager::enableEcho();

    // Show the cursor
    Console::cursor()->show();

    // Restore the terminal settings
    Console::restoreTerminalSettings();

    $this->notify($this, new GameEvent(GameEventType::STOP));

    // Remove observers
    $this->removeObservers();

    $this->isRunning = false;
  }

  /**
   * Handle the input.
   *
   * @return void
   */
  protected function handleInput(): void
  {
    InputManager::handleInput();
  }

  /**
   * Update the game.
   *
   * @return void
   */
  protected function update(): void
  {
    $this->frameCount++;
    $this->sceneManager->update();
    $this->notificationManager->update();

    $this->notify($this, new GameEvent(GameEventType::UPDATE));
  }

  /**
   * Render the game.
   *
   * @return void
   */
  protected function render(): void
  {
    $this->sceneManager->render();
    $this->notificationManager->render();

    if (
      config(AppConfig::class, 'debug.enabled') &&
      config(AppConfig::class, 'debug.show')
    ) {
      $this->renderDebugInfo();
    }

    $this->notify($this, new GameEvent(GameEventType::RENDER));
  }

  /**
   * Initialize the manager instances.
   *
   * @return void
   */
  private function initializeManagers(): void
  {
    $this->sceneManager = SceneManager::getInstance($this);
    $this->eventManager = EventManager::getInstance($this);
    $this->modalManager = ModalManager::getInstance($this);
    $this->notificationManager = NotificationManager::getInstance($this);
    InputManager::init($this);
    $this->engine = new TraditionalTurnBasedBattleEngine($this);
  }

  /**
   * Initialize the list observers.
   *
   * @return void
   */
  private function initializeObservers(): void
  {
    $this->observers = new ItemList(ObserverInterface::class);
    $this->staticObservers = new ItemList(StaticObserverInterface::class);
  }

  /**
   * Configure the error and exception handlers.
   *
   * @return void
   */
  public function configureErrorAndExceptionHandlers(): void
  {
    error_reporting(E_ALL);

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

    Debug::configure([
      'log_level' => config(AppConfig::class, 'debug.level', 1) ?? 1,
      'log_directory' => $logDirectory
    ]);

    $this->debugWindow = new DebugWindow($this);
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
    $message = "Error: $errstr in $errfile on line $errline";
    Debug::error($message);
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
    exit("$exception\n");
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
    if ($observer instanceof ObserverInterface) {
      $this->observers->remove($observer);
    } else if (is_a($observer, StaticObserverInterface::class, true)) {
      $this->staticObservers->remove($observer);
    }
  }

  /**
   * @inheritDoc
   */
  public function notify(object $entity, EventInterface $event): void
  {
    try {
      /** @var ObserverInterface $observer */
      foreach ($this->observers as $observer) {
        $observer->onNotify($entity, $event);
      }

      /** @var StaticObserverInterface $observer */
      foreach ($this->staticObservers as $observer) {
        $observer::onNotify($entity, $event);
      }
    } catch (Error|Exception|Throwable $exception) {
      exit($exception);
    }
  }

  /**
   * Quit the game.
   *
   * @return void
   * @throws Exception
   */
  public function quit(): void
  {
    Console::reset();
    $this->notify($this, new GameEvent(GameEventType::QUIT));
    $this->stop();
  }

  /**
   * Show the splash screen.
   *
   * @return void
   */
  private function showSplashScreens(): void
  {
    $this->showCustomSplashScreen();
    $this->showGameEngineSplashScreen();
  }

  /**
   * Render debug info.
   *
   * @return void
   */
  private function renderDebugInfo(): void
  {
    $this->debugWindow->setContent([
      "FPS: {$this->frameRate}",
      "Delta: " . round(Time::getDeltaTime(), 2),
      "Time: " . Time::getPrettyTime(ChronoUnit::SECONDS)
    ]);
    $this->debugWindow->render();
  }

  /**
   * Handle game events.
   *
   * @return void
   */
  private function handleGameEvents(): void
  {
    // Handle game events
    $this->eventManager->addEventListener(EventType::GAME, function (GameEvent $event) {
      switch ($event->getGameEventType()) {
        case GameEventType::QUIT:
          $this->quit();
          break;

        default:
          break;
      }
    });

    // Handle Game Play events
    $this->eventManager->addEventListener(EventType::GAME_PLAY, function (GameplayEvent $event) {
      switch ($event->getGameplayEventType()) {
        case GameplayEventType::GAME_OVER:
          $this->sceneManager->loadGameOverScene();
          break;

        default:
          break;
      }
    });
  }

  /**
   * Show the custom engine splash screen.
   *
   * @return void
   */
  private function showCustomSplashScreen(): void
  {
    if (!config(AppConfig::class, 'splash_screen.enabled')) {
      return;
    }

    $filename = config(AppConfig::class, 'splash_screen.filename') ?? DEFAULT_ASSETS_SPLASH_TEXTURE;
    $splashScreenTextureFilename = Path::join(Path::getCurrentWorkingDirectory(), $filename);

    if (! file_exists($splashScreenTextureFilename) ) {
      Debug::warn("The custom splash screen texture file, $splashScreenTextureFilename, does not exist.");
      return;
    }

    $splashScreenTexture = file_get_contents($splashScreenTextureFilename);

    if (false === $splashScreenTexture) {
      Debug::warn("Failed to read the custom splash screen texture file: $splashScreenTextureFilename.");
      return;
    }

    $this->renderSplashScreenTexture($splashScreenTexture, config(AppConfig::class, 'splash_screen.duration'));
  }

  /**
   * Show the game engine splash screen.
   *
   * @return void
   * @noinspection SpellCheckingInspection
   */
  private function showGameEngineSplashScreen(): void
  {
    $splashScreen = <<<SPLASH_SCREEN
Powered by

ooooo           oooo         o8o  oooo                .                   
`888'           `888         `"'  `888              .o8                   
 888   .ooooo.   888 .oo.   oooo   888   .ooooo.  .o888oo  .ooooo.        
 888  d88' `"Y8  888P"Y88b  `888   888  d88' `88b   888   d88' `88b       
 888  888        888   888   888   888  888   888   888   888   888       
 888  888   .o8  888   888   888   888  888   888   888 . 888   888       
o888o `Y8bod8P' o888o o888o o888o o888o `Y8bod8P'   "888" `Y8bod8P'       
                                                                          
                                                                          
                                                                          
          oooooooooooo                         o8o                        
          `888'     `8                         `"'                        
           888         ooo. .oo.    .oooooooo oooo  ooo. .oo.    .ooooo.  
           888oooo8    `888P"Y88b  888' `88b  `888  `888P"Y88b  d88' `88b 
           888    "     888   888  888   888   888   888   888  888ooo888 
           888       o  888   888  `88bod8P'   888   888   888  888    .o 
          o888ooooood8 o888o o888o `8oooooo.  o888o o888o o888o `Y8bod8P' 
                                   d"     YD                              
                                   "Y88888P'
                                                                   v1.0.0
SPLASH_SCREEN;
    $this->renderSplashScreenTexture($splashScreen);
  }

  /**
   * Remove observers.
   *
   * @return void
   */
  private function removeObservers(): void
  {
    foreach ($this->observers as $observer) {
      $this->observers->remove($observer);
    }

    foreach ($this->staticObservers as $staticObserver) {
      $this->staticObservers->remove($staticObserver);
    }
  }

  /**
   * Render the splash screen texture.
   *
   * @param string $splashScreenTexture The splash screen texture.
   * @param float $duration The duration to show the splash screen.
   * @return void
   */
  private function renderSplashScreenTexture(string $splashScreenTexture, float $duration = DEFAULT_SPLASH_SCREEN_DURATION): void
  {
    if (config(AppConfig::class, 'debug.skip_splash')) {
      return;
    }

    $splashScreenRows = explode("\n", $splashScreenTexture);

    $leftMargin = (get_screen_width()  / 2) - (75 / 2);
    $topMargin = (get_screen_height() / 2) - (25 / 2);

    foreach ($splashScreenRows as $rowIndex => $row) {
      Console::write($row, (int)$leftMargin, (int)($topMargin + $rowIndex));
    }

    usleep(intval($duration * 1000000));
    Console::clear();
  }

  /**
   * Initialize the configuration.
   *
   * @return void
   */
  private function initializeConfigStore(): void
  {
    ConfigStore::put(PlaySettings::class,   new PlaySettings($this->options));
    ConfigStore::put(AppConfig::class,      new AppConfig());
    ConfigStore::put(ProjectConfig::class,  new ProjectConfig());
    ConfigStore::put(InputConfig::class,    new InputConfig());
    ConfigStore::put(ItemStore::class,      new ItemStore());
    ConfigStore::put(EnemyStore::class,     new EnemyStore());
  }

  /**
   * Build the item store. This is where all the items in the game are stored.
   *
   * @return void
   * @throws NotFoundException If the item store is not an instance of ItemStore.
   */
  private function buildItemStore(): void
  {
    if ( $itemStore = ConfigStore::get(ItemStore::class) ) {
      if (! $itemStore instanceof ItemStore) {
        throw new NotFoundException(ItemStore::class);
      }

      $this->itemStore = $itemStore;
    }
  }
}