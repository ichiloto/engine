<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

$root = $argv[1];
chdir($root);
Debug::configure(['log_directory' => $root . '/logs']);
ConfigStore::put(PlayerSettings::class, new PlayerSettings($root . '/blocked'));
ConfigStore::put(ProjectConfig::class, new ProjectConfig());

// Match the Game policy: an uncaught PHP filesystem warning ends the process.
set_error_handler(static function (int $severity): bool {
  if ((error_reporting() & $severity) === 0) {
    return false;
  }
  exit(91);
});

$manager = new MainMenuSettingsManager();
$music = array_find($manager->getSettings(), static fn($setting): bool => $setting->key === 'music');
$diagnosed = false;
try {
  $manager->cycle($music, 1);
} catch (RuntimeException $exception) {
  $diagnosed = $exception->getMessage() !== '';
}

$dialogue = new DialoguePlayback();
$dialogue->toggleAuto();

echo json_encode([
  'diagnosed' => $diagnosed,
  'musicApplied' => ConfigStore::get(ProjectConfig::class)->get('audio.music') === false,
  'autoApplied' => $dialogue->auto && (new DialoguePlayback())->auto,
], JSON_THROW_ON_ERROR);
