<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ichiloto\Engine\Audio\AudioMutePreflight;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

final class LocalDataProbeGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

final class LocalDataProbeScene extends GameScene
{
  public function __construct(private GameConfig $snapshot) { $this->party = $snapshot->party; }
  public function createSnapshot(int $playTimeSeconds = 0): GameConfig { return $this->snapshot; }
}

$root = $argv[1];
$mode = $argv[2] ?? 'blocked-data';
$logsBlocked = $mode === 'blocked-logs';
chdir($root);
Debug::configure(['log_directory' => $root . '/logs']);
$player = new PlayerSettings($root);
ConfigStore::put(PlayerSettings::class, $player);
$effective = new ProjectConfig();
ConfigStore::put(ProjectConfig::class, $effective);
// No native game starts in this probe; still verify the effective audio state first.
AudioMutePreflight::assertMuted($effective);

$game = new LocalDataProbeGame();
$game->configureErrorAndExceptionHandlers();
$manifest = SaveCompatibilityManifest::fromArray('ichiloto/test-project',
  ['contentVersion' => 0, 'migrations' => [], 'aliases' => [], 'tombstones' => []], 'storage probe');
$manager = new SaveManager($game, 'saves', 'saves/quick', $manifest);
$slots = $manager->getSaveSlots();
$manager->getQuickSavePath('quick');

$player->set('ui.dialogue.auto', true);
$settingsFailed = false;
try { $player->persist(); } catch (RuntimeException) { $settingsFailed = true; }

$scene = new LocalDataProbeScene(new GameConfig('test-map', new Party(), new Vector2(0, 0),
  new Rect(0, 0, 1, 1), MovementHeading::SOUTH));
$saveFailures = [];
foreach (['normal' => static fn() => $manager->save($scene, 1),
  'quick' => static fn() => $manager->quickSave($scene),
  'auto' => static fn() => $manager->autoSave($scene)] as $kind => $save) {
  $saveFailures[$kind] = false;
  try { $save(); } catch (RuntimeException) { $saveFailures[$kind] = true; }
}
$deleteRefused = $mode === 'readonly-saves'
  ? ! $manager->deleteSlot(1) && is_file($manager->getSlotPath(1)) : null;

echo json_encode([
  'settingsFailed' => $settingsFailed,
  'saveFailures' => $saveFailures,
  'sessionChoiceApplied' => $player->get('ui.dialogue.auto') === true,
  'slotsListed' => count($slots) === 5,
  'diagnosed' => ! $logsBlocked && str_contains((string) file_get_contents($root . '/logs/warning.log'), 'Saving is unavailable:'),
  'dataPath' => is_file($root . '/.data') ? 'file' : (is_dir($root . '/.data') ? 'directory' : 'absent'),
  'deleteRefused' => $deleteRefused,
], JSON_THROW_ON_ERROR);
