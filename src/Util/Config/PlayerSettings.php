<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Util\Config;

use BackedEnum;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Player-owned overrides of the defaults shipped in config.php. */
final class PlayerSettings extends AbstractConfig
{
  public const array PATHS = [
    'audio.master_volume', 'audio.music', 'audio.sfx', 'audio.voice',
    'ui.dialogue.auto', 'ui.dialogue.speed', 'ui.dialogue.message.speed',
    'accessibility.notificationDurationScale', 'ui.cursor.memory',
    'ui.battle.message_pace', 'ui.battle.animation_pace',
    'ui.menu.selection_color', 'ui.battle.selection_color',
    'ui.hud.location', 'ui.transitions.style',
  ];

  private readonly string $filename;

  public function __construct(?string $projectRoot = null)
  {
    $root = $projectRoot ?? getcwd();
    if ($root === false) {
      throw new RuntimeException('Cannot locate the project for player settings.');
    }
    $this->filename = $root . DIRECTORY_SEPARATOR . SaveManager::DATA_DIRECTORY
      . DIRECTORY_SEPARATOR . 'player-settings.json';
    parent::__construct();
  }

  protected function load(): array
  {
    if (! is_file($this->filename)) {
      return [];
    }

    try {
      $content = file_get_contents($this->filename);
      if ($content === false) {
        throw new RuntimeException('Could not read player settings.');
      }
      $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
      if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
        throw new RuntimeException('Player settings must be a JSON object.');
      }
    } catch (JsonException|RuntimeException $exception) {
      Debug::warn('Ignoring unreadable player settings: ' . $exception->getMessage());
      return [];
    }

    $saved = $this->config;
    $this->config = $decoded;
    $overrides = $this->getOverrides();
    $this->config = $saved;

    return $overrides;
  }

  public function set(string $path, mixed $value): void
  {
    if (! in_array($path, self::PATHS, true)) {
      throw new InvalidArgumentException("Not a player setting: $path");
    }
    parent::set($path, $value instanceof BackedEnum ? $value->value : $value);
  }

  /** Return only supported setting paths from the player file. */
  public function getOverrides(): array
  {
    $overrides = [];
    foreach (self::PATHS as $path) {
      if (! $this->has($path)) {
        continue;
      }
      $value = $this->get($path);
      if (! is_scalar($value)) {
        continue;
      }
      $target = &$overrides;
      $segments = explode('.', $path);
      foreach ($segments as $segment) {
        $target = &$target[$segment];
      }
      $target = $value;
      unset($target);
    }
    return $overrides;
  }

  public function persist(): void
  {
    $directory = dirname($this->filename);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
      throw new RuntimeException("Could not create player data directory: $directory");
    }

    $content = json_encode($this->getOverrides(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam($directory, '.player-settings-');
    if ($temporary === false) {
      throw new RuntimeException('Could not create a temporary player settings file.');
    }
    try {
      if (file_put_contents($temporary, $content) !== strlen($content)
        || ! rename($temporary, $this->filename)) {
        throw new RuntimeException('Could not write player settings.');
      }
    } finally {
      if (is_file($temporary)) {
        unlink($temporary);
      }
    }
  }
}
