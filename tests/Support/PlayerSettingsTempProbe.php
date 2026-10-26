<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ichiloto\Engine\Audio\AudioMutePreflight;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

$root = $argv[1];
$fallback = $argv[2];
chdir($root);
Debug::configure(['log_directory' => $root . '/logs']);
$player = new PlayerSettings($root);
ConfigStore::put(PlayerSettings::class, $player);
$effective = new ProjectConfig();
AudioMutePreflight::assertMuted($effective);
$game = new class extends Game {
  public function __construct() {}
  public function __destruct() {}
};
$game->configureErrorAndExceptionHandlers();

$player->set('ui.dialogue.auto', true);
$before = scandir($fallback);
$failed = false;
try { $player->persist(); } catch (RuntimeException) { $failed = true; }
echo json_encode([
  'failed' => $failed,
  'fallbackUnchanged' => scandir($fallback) === $before,
  'settingsFileAbsent' => ! is_file($root . '/.data/player-settings.json'),
], JSON_THROW_ON_ERROR);
