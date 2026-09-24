<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

it('refuses a layered destination through the real field transfer and draws a recoverable notification', function (string $failure) {
    $saved = [];
    foreach ([Console::class, NotificationManager::class, EventManager::class, Time::class, ConfigStore::class, AudioManager::class] as $class) {
        $saved[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $root = sys_get_temp_dir() . '/ichiloto-layer-transfer-' . bin2hex(random_bytes(8));
    mkdir($root . '/layers', recursive: true);
    $paths = ['id' => 'destination', 'data' => $root . '/destination.data.php',
        'event' => $root . '/destination.event.php', 'map' => $root . '/destination.map.php'];
    file_put_contents($paths['data'], '<?php return [];');
    file_put_contents($paths['event'], MapGridSource::buildSource('  ', 'EVENT'));
    file_put_contents($root . '/layers/01.terrain.map.php', MapGridSource::buildSource('xx', 'MAP'));
    $overlay = $root . '/layers/02.fixtures.map.php';
    file_put_contents($overlay, $failure === 'source'
        ? "<?php\nreturn str_repeat(' ', 2);" : MapGridSource::buildSource('x', 'MAP'));
    try {
        foreach ([NotificationManager::class, EventManager::class] as $class) {
            new ReflectionProperty($class, 'instance')->setValue(null, null);
        }
        foreach ((new ReflectionClass(AudioManager::class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if ($property->getValue() instanceof AudioManager) { $property->setValue(null, null); }
        }
        ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 100, 'height' => 20]));
        ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub([
            'audio' => ['music' => false, 'sfx' => false], 'accessibility' => ['reducedMotion' => true],
        ]));
        foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $name => $value) {
            new ReflectionProperty(Console::class, $name)->setValue(null, $value);
        }
        Console::setTerminalOutputEnabled(false);
        Console::syncDimensions(100, 20);
        Time::setElapsedTime(0);
        $game = new class extends Game { public function __construct() {} public function __destruct() {} };
        $scene = new class($game) extends GameScene {
            public function __construct(private Game $testGame) {}
            public function getGame(): Game { return $this->testGame; }
        };
        $manager = new class($paths) extends MapManager {
            public function __construct(private array $paths) {}
            protected function resolveMapPaths(string $filename): array { return $this->paths; }
        };
        $camera = new Camera(makeCameraTestScene(), 100, 20, worldSpace: [['o', 'l', 'd']]);
        new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
        new ReflectionProperty(GameScene::class, 'mapManager')->setValue($scene, $manager);
        new ReflectionProperty(GameScene::class, 'currentMapId')->setValue($scene, 'original');
        new ReflectionProperty(MapManager::class, 'gameScene')->setValue($manager, $scene);
        expect($scene->transferPlayer(new Location('destination', new Vector2(0, 0), null), false))->toBeFalse();
        NotificationManager::getInstance($game)->render();
        $visible = implode("\n", Console::snapshot()->rows);
        expect($visible)->toContain('Map transfer unavailable', 'destination')
            ->not->toContain($root)
            ->and($camera->worldSpace)->toBe([['o', 'l', 'd']])
            ->and($scene->currentMapId)->toBe('original');
    } finally {
        foreach ($saved as $class => $properties) {
            foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
        }
        foreach ([$paths['data'], $paths['event'], $root . '/layers/01.terrain.map.php', $overlay] as $path) { unlink($path); }
        rmdir($root . '/layers');
        rmdir($root);
    }
})->with(['source', 'dimensions']);
