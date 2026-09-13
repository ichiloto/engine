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

it('loads current optional terrain metadata afresh and rejects malformed presence without changing the map', function () {
  $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-terrain-map-', true);
  mkdir($directory);
  $paths = ['id'=>'test/terrain','data'=>"$directory/map.data.php",'map'=>"$directory/map.map.php",'event'=>"$directory/map.event.php"];
  file_put_contents($paths['map'], '<?php return ";~";');
  file_put_contents($paths['event'], '<?php return "  ";');
  $manager = new ReflectionClass(SplitMapManagerProbe::class)->newInstanceWithoutConstructor();
  $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  $camera = new Camera(makeCameraTestScene(),8,4);
  new ReflectionProperty(GameScene::class,'camera')->setValue($scene,$camera);
  new ReflectionProperty(MapManager::class,'gameScene')->setValue($manager,$scene);
  try {
    foreach ([null, 'one.png', 'two.png', null] as $asset) {
      $data = ['name'=>'Test'];
      if ($asset !== null) { $data['tiles2d'] = ['asset'=>$asset,'symbols'=>[';'=>['x'=>0,'y'=>0,'width'=>16,'height'=>32]]]; }
      file_put_contents($paths['data'], '<?php return ' . var_export($data,true) . ';');
      $manager->readSplitMap($paths);
      expect($manager->tiles2d?->asset)->toBe($asset)->and($manager->tileMap)->toBe([[';','~']])
        ->and($camera->worldSpace)->toBe($manager->tileMap);
    }
    file_put_contents($paths['data'], '<?php return ["tiles2d"=>null];');
    expect(fn()=>$manager->readSplitMap($paths))->toThrow(InvalidArgumentException::class, 'map.data.php tiles2d:')
      ->and($manager->tiles2d)->toBeNull()->and($manager->tileMap)->toBe([[';','~']]);
  } finally {
    foreach (['data','map','event'] as $key) { unlink($paths[$key]); }
    rmdir($directory);
  }
});

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
