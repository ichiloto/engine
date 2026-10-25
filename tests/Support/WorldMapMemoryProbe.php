<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\RetainedUpload;
use Ichiloto\Engine\Rendering\Presentation\RetainedWorldUpload;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

// Travels between a small map and a world map through the field's own preparation and
// installation, then builds and sends the world's graphical upload as a renderer session
// does. No Game, native renderer or audio backend is constructed. Usage: <project> <logs>.
[, $project, $logs] = $argv;
chdir($project);
ConfigStore::put(ProjectConfig::class, new PlaySettings([]));
Debug::configure(['log_directory' => $logs]);
Console::setTerminalOutputEnabled(false);
Console::syncDimensions(40, 20);
$scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, new Camera($scene, 40, 20));
new ReflectionProperty(GameScene::class, 'gameState')->setValue($scene, new GameState());
new ReflectionProperty(GameScene::class, 'party')->setValue($scene,
    new ReflectionClass(Party::class)->newInstanceWithoutConstructor());
$manager = new class($scene) extends MapManager {
    public function __construct(GameScene $scene) { $this->gameScene = $scene; }
    protected function applyMapBackgroundMusic(mixed $bgm, mixed $variants = []): void {}
};
new ReflectionProperty(GameScene::class, 'mapManager')->setValue($scene, $manager);
$player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
new ReflectionProperty(Player::class, 'position')->setValue($player, new Vector2(1, 1));

$enter = static function (string $mapId) use ($manager, $player): array {
    // The destination is prepared while the current map is still installed, as a transfer does.
    $manager->applyPreparedMap($manager->prepareMap($mapId), $player);
    $world = $manager->getPresentationWorld();
    $packets = $bytes = 0;
    $upload = new RetainedUpload([new RetainedWorldUpload($world, true, true)], 1, true, true, null, [], [], $world);
    do {
        $bytes += strlen($upload->getMessage($packets)->encode());
        $packets++;
    } while (!$upload->advance());
    return ['packets' => $packets, 'bytes' => $bytes, 'columns' => $manager->mapWidth, 'rows' => $manager->mapHeight];
};
$settled = static function (): int {
    gc_collect_cycles();
    return memory_get_usage();
};

$enter('synthetic/hamlet');
$beforeWorld = $settled();
memory_reset_peak_usage();
$world = $enter('synthetic/world');
$worldPeak = memory_get_peak_usage() - $beforeWorld;
$worldRetained = $settled() - $beforeWorld;
$enter('synthetic/hamlet');
$afterLeaving = $settled() - $beforeWorld;
$enter('synthetic/world');
$enter('synthetic/hamlet');
$afterReturning = $settled() - $beforeWorld;

echo json_encode([
    'memoryLimit' => ini_get('memory_limit'),
    'peakBytes' => memory_get_peak_usage(),
    'worldPeakBytes' => $worldPeak,
    'worldRetainedBytes' => $worldRetained,
    'afterLeavingBytes' => $afterLeaving,
    'afterReturningBytes' => $afterReturning,
    'world' => $world,
], JSON_THROW_ON_ERROR) . PHP_EOL;
