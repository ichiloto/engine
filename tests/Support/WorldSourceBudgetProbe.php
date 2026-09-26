<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

// No Game, native renderer or audio backend is constructed in this memory probe.
ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['sprites' => ['allow_composite_emoji' => true]]]));
Debug::configure(['log_directory' => $argv[1]]);
Console::setTerminalOutputEnabled(false);
Console::setLayerTracking(true);
Console::setRetainedWorldPresentation(true);
Console::syncDimensions(8, 4);
$scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
$camera = new Camera($scene, 8, 4);
new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
new ReflectionProperty(GameScene::class, 'party')->setValue($scene,
    new ReflectionClass(Party::class)->newInstanceWithoutConstructor());
$manager = new class($scene) extends MapManager {
    public function __construct(GameScene $scene) { $this->gameScene = $scene; }
    protected function applyMapBackgroundMusic(mixed $bgm, mixed $variants = []): void {}
};
new ReflectionProperty(GameScene::class, 'mapManager')->setValue($scene, $manager);
$player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
new ReflectionProperty(Player::class, 'position')->setValue($player, new Vector2(0, 0));
$small = new MapLayerSet([new MapLayer('terrain', 0, false, 'small', '..')]);
$preparedSmall = new PreparedMap([], $small->getComposedGrid(), [[0]], [], [], layers: $small);
$manager->applyPreparedMap($preparedSmall, $player);
$initialWorldAvailable = $manager->getPresentationWorld() !== null;

// The layer name is filename-safe and its 214-byte wire ID fits the native 256-byte bound.
$name = str_repeat('a', 210);
$extent = 500;
$layers = new MapLayerSet([new MapLayer($name, 0, false, 'layers/00.' . $name . '.map.php',
    implode("\n", array_fill(0, $extent, str_repeat('..', $extent))))]);
$manager->applyPreparedMap(new PreparedMap([], $layers->getComposedGrid(),
    array_fill(0, $extent, array_fill(0, $extent, 0)), [], [], layers: $layers), $player);
$oversizedWorldAvailable = $manager->getPresentationWorld() !== null;
$manager->render();
$changes = Console::getRetainedPresentationChanges();
$screenRows = array_fill(0, 4, array_fill(0, 8, ' '));
foreach ($changes->layers as $layer) {
    foreach ($layer['rows'] as $row) {
        foreach ($row['runs'] as $run) {
            foreach (mb_str_split($run->text) as $offset => $glyph) {
                $screenRows[$run->row][$run->column + $offset] = $glyph;
            }
        }
    }
}
$warning = file_get_contents($argv[1] . '/warning.log');
for ($index = 0; $index < 5; $index++) {
    $manager->getPresentationWorld();
    $manager->render();
    Console::getRetainedPresentationChanges();
}
$warningAfter = file_get_contents($argv[1] . '/warning.log');
$manager->applyPreparedMap($preparedSmall, $player);

echo json_encode([
    'memoryLimit' => ini_get('memory_limit'),
    'peakBytes' => memory_get_peak_usage(true),
    'cellCount' => $extent * $extent,
    'extent' => $extent,
    'layerCount' => count($layers->layers),
    'ownerIdBytes' => strlen('map:' . $name),
    'sourceBytes' => 8192 + $extent * $extent * (64 + strlen('..') + strlen('map:' . $name)),
    'initialWorldAvailable' => $initialWorldAvailable,
    'oversizedWorldAvailable' => $oversizedWorldAvailable,
    'retainedMode' => Console::isRetainedWorldPresentation(),
    'screenRows' => array_map(implode(...), $screenRows),
    'deltaLayerIds' => array_column($changes->layers, 'id'),
    'warningCount' => substr_count($warningAfter, 'Retained map presentation is unavailable:'),
    'warningUnchanged' => $warningAfter === $warning,
    'recoveredWorldAvailable' => $manager->getPresentationWorld() !== null,
], JSON_THROW_ON_ERROR) . PHP_EOL;
