<?php

use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;

final class SplitMapManagerProbe extends MapManager
{
  /**
   * @param array{id: string, data: string, map: string, event: string} $paths
   * @return array<string, mixed>
   */
  public function readSplitMap(array $paths): array
  {
    return $this->readSplitMapDataFromFiles($paths);
  }
}

it('isolates authored map variables from split-map loader state', function () {
  $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-split-map-', true);
  mkdir($directory, 0777, true);

  $paths = [
    'id' => 'test/colliding-map',
    'data' => $directory . DIRECTORY_SEPARATOR . 'colliding-map.data.php',
    'map' => $directory . DIRECTORY_SEPARATOR . 'colliding-map.map.php',
    'event' => $directory . DIRECTORY_SEPARATOR . 'colliding-map.event.php',
  ];

  file_put_contents($paths['data'], <<<'PHP'
<?php

return [
  'name' => 'Collision Test',
  'events' => [
    'A' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger',
      'data' => ['dialogue' => []],
    ],
  ],
];
PHP);

  // This local name deliberately matches MapManager's `$map` data variable.
  file_put_contents($paths['map'], <<<'PHP'
<?php

$map = "  \n  ";

return $map;
PHP);

  file_put_contents($paths['event'], <<<'PHP'
<?php

return "A \n  ";
PHP);

  try {
    $manager = (new ReflectionClass(SplitMapManagerProbe::class))->newInstanceWithoutConstructor();
    $gameScene = (new ReflectionClass(GameScene::class))->newInstanceWithoutConstructor();
    $camera = new Camera(makeCameraTestScene(), 10, 10);

    (new ReflectionProperty(GameScene::class, 'camera'))->setValue($gameScene, $camera);
    (new ReflectionProperty(MapManager::class, 'gameScene'))->setValue($manager, $gameScene);

    $mapData = $manager->readSplitMap($paths);

    expect($mapData['name'])->toBe('Collision Test')
      ->and($mapData['events'])->toHaveCount(1)
      ->and($mapData['events'][0]['marker'])->toBe('A')
      ->and($mapData['events'][0]['area'])->toBe([
        'x' => 0,
        'y' => 0,
        'width' => 1,
        'height' => 1,
      ]);
  } finally {
    foreach (['data', 'map', 'event'] as $type) {
      if (is_file($paths[$type])) {
        unlink($paths[$type]);
      }
    }

    if (is_dir($directory)) {
      rmdir($directory);
    }
  }
});
