<?php

use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;

final class SplitMapManagerProbe extends MapManager
{
  public ?array $testPaths = null;

  protected function resolveMapPaths(string $filename): array
  {
    return $this->testPaths ?? parent::resolveMapPaths($filename);
  }

  protected function getCollisionDictionary(): array
  {
    return [];
  }

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
  file_put_contents($paths['map'], "<?php\nreturn <<<'MAP'\n;~\nMAP;\n");
  file_put_contents($paths['event'], "<?php\nreturn <<<'EVENT'\n  \nEVENT;\n");
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

it('prepares a destination without touching the active map and refuses a bad event layer', function (): void {
  $directory = sys_get_temp_dir() . '/ichiloto-map-preflight-' . bin2hex(random_bytes(8));
  mkdir($directory);
  $paths = [
    'id' => 'test/destination',
    'data' => $directory . '/destination.data.php',
    'map' => $directory . '/destination.map.php',
    'event' => $directory . '/destination.event.php',
  ];
  file_put_contents($paths['data'], "<?php return ['name' => 'Destination', 'events' => []];");
  file_put_contents($paths['map'], "<?php return <<<'MAP'\n..\nMAP;");
  file_put_contents($paths['event'], "<?php return <<<'EVENT'\n  \nEVENT;");

  try {
    $manager = (new ReflectionClass(SplitMapManagerProbe::class))->newInstanceWithoutConstructor();
    $scene = (new ReflectionClass(GameScene::class))->newInstanceWithoutConstructor();
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: [['old']]);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($manager, $scene);
    $manager->testPaths = $paths;

    $prepared = $manager->prepareMap('ignored');
    expect($prepared->tiles)->toBe([['.', '.']])
      ->and($manager->tileMap)->toBe([])
      ->and($camera->worldSpace)->toBe([['old']]);

    file_put_contents($paths['event'], "<?php return <<<'EVENT'\nX\nEVENT;");
    expect(fn () => $manager->prepareMap('ignored'))->toThrow(InvalidArgumentException::class, 'must be 2 tiles wide')
      ->and($manager->tileMap)->toBe([])
      ->and($camera->worldSpace)->toBe([['old']]);
  } finally {
    foreach (['data', 'map', 'event'] as $member) {
      unlink($paths[$member]);
    }
    rmdir($directory);
  }
});

it('removes executable grid sources from the split-map contract', function () {
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

  // The former executable source shape is refused, not evaluated.
  file_put_contents($paths['map'], <<<'PHP'
<?php

$map = "  \n  ";

return $map;
PHP);

  file_put_contents($paths['event'], "<?php\nreturn <<<'EVENT'\nA \n  \nEVENT;\n");

  try {
    $manager = (new ReflectionClass(SplitMapManagerProbe::class))->newInstanceWithoutConstructor();
    $gameScene = (new ReflectionClass(GameScene::class))->newInstanceWithoutConstructor();
    $camera = new Camera(makeCameraTestScene(), 10, 10);

    (new ReflectionProperty(GameScene::class, 'camera'))->setValue($gameScene, $camera);
    (new ReflectionProperty(MapManager::class, 'gameScene'))->setValue($manager, $gameScene);

    expect(fn() => $manager->readSplitMap($paths))
      ->toThrow(InvalidArgumentException::class, 'literal nowdoc');
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

it('refuses either executable grid before evaluating the map data file', function (string $member): void {
  $directory = sys_get_temp_dir() . '/ichiloto-grid-gate-' . bin2hex(random_bytes(8));
  mkdir($directory);
  $marker = $directory . '/executed';
  $paths = [
    'id' => 'test/gate',
    'data' => $directory . '/gate.data.php',
    'map' => $directory . '/gate.map.php',
    'event' => $directory . '/gate.event.php',
  ];
  file_put_contents($paths['data'], '<?php file_put_contents(' . var_export($marker, true) . ", 'yes'); return []; ");
  file_put_contents($paths['map'], "<?php return <<<'MAP'\nx\nMAP;");
  file_put_contents($paths['event'], "<?php return <<<'EVENT'\n \nEVENT;");
  file_put_contents($paths[$member], '<?php file_put_contents(' . var_export($marker, true) . ", 'yes'); return 'x';");

  try {
    $manager = (new ReflectionClass(SplitMapManagerProbe::class))->newInstanceWithoutConstructor();
    expect(fn() => $manager->readSplitMap($paths))->toThrow(InvalidArgumentException::class, 'literal nowdoc');
    expect(is_file($marker))->toBeFalse();
  } finally {
    foreach (['data', 'map', 'event'] as $type) {
      unlink($paths[$type]);
    }
    if (is_file($marker)) {
      unlink($marker);
    }
    rmdir($directory);
  }
})->with(['map', 'event']);
