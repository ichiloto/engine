<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Field\RegionMap;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Messaging\Notifications\Notification;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationSlideDirection;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MapState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

it('restores the exact idle map through notification motion and dismissal without resuming field objects', function (bool $output, NotificationSlideDirection $exit) {
  $saved = [];
  foreach ([Console::class, EventManager::class, Time::class] as $class) {
    $saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $settings = ConfigStore::has(PlaySettings::class) ? ConfigStore::get(PlaySettings::class) : null;
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  foreach (['overlays' => [], 'terminalOutputEnabled' => $output, 'terminalOutputStream' => null,
    'usingAlternateScreen' => false, 'terminalHandedBack' => false, 'frameDepth' => 0, 'isRecomposing' => false] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(80, 24);
  Console::setLayerTracking(!$output);
  ob_start();
  try {
    writeTestMaps(['garden' => ['name' => 'Garden', 'region' => 'Roads']]);
    $game = new class extends Game { public function __construct() {} public function __destruct() {} };
    $scene = new class(EventManager::getInstance($game)) extends GameScene {
      public int $suspends = 0;
      public int $resumes = 0;
      public function __construct(EventManager $events) {
        $this->eventManager = $events;
        $this->party = new Party();
        $this->party->location = new PartyLocation('Garden', 'Roads');
        $this->initializeEventHandlers();
      }
      public function suspend(): void { $this->suspends++; }
      public function resume(): void { $this->resumes++; }
    };
    $map = new class(new SceneStateContext($scene)) extends MapState {
      protected function currentMapId(): string { return 'garden'; }
      protected function hasVisited(string $id): bool { return true; }
      public function draw(): void {
        $this->calculateMargins(); $this->initializeUI(); $this->refreshUI();
      }
    };
    $map->draw();
    $before = Console::getBuffer();
    Time::setElapsedTime(0);
    $notice = new Notification($game, NotificationChannel::INFO, 'Toast', 'Over the map',
      enterDirection: NotificationSlideDirection::NONE, exitDirection: $exit, animationDuration: 0.2);
    $notice->setPosition(new Vector2(40, 1))->open()->render(1, 1);
    expect(Console::getBuffer())->not->toBe($before);
    $notice->dismiss();
    Time::setElapsedTime(0.1);
    $notice->update();
    $notice->render(1, 1);
    Time::setElapsedTime(0.2);
    $notice->update();
    expect($notice->isFinished())->toBeTrue()
      ->and(Console::getBuffer())->toBe($before)
      ->and($scene->suspends)->toBe(0)->and($scene->resumes)->toBe(0);
  } finally {
    ob_end_clean();
    RegionMap::reset();
    foreach ($saved as $class => $properties) {
      foreach ($properties as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
    }
    $settings === null ? ConfigStore::remove(PlaySettings::class) : ConfigStore::put(PlaySettings::class, $settings);
  }
})->with([true, false])->with([NotificationSlideDirection::RIGHT, NotificationSlideDirection::UP, NotificationSlideDirection::NONE]);
