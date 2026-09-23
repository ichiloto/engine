<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Util\Config;

use BackedEnum;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Player-owned overrides of authored config.php and input.php defaults. */
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
    try {
      if (! self::runFileOperation(fn() => is_file($this->filename))) {
        return [];
      }
      $content = self::runFileOperation(fn() => file_get_contents($this->filename));
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
    $bindings = $this->getInputBindings();
    $this->config = $saved;

    if ($bindings !== []) {
      $overrides['input']['bindings'] = self::encodeInputBindings($bindings);
    }

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

  /**
   * Player key overrides only. Authored descriptions and controller metadata
   * remain in input.php and are never copied into this file.
   *
   * @return array<string, KeyCode[]>
   */
  public function getInputBindings(): array
  {
    $saved = $this->get('input.bindings', []);
    if (! is_array($saved)) {
      Debug::warn('Ignoring invalid player input bindings.');
      return [];
    }

    $bindings = [];
    foreach ($saved as $action => $values) {
      if (! is_string($action) || $action === '' || ! is_array($values) || ! array_is_list($values)) {
        Debug::warn('Ignoring malformed player input binding.');
        continue;
      }
      $keys = [];
      foreach ($values as $value) {
        $key = is_string($value) ? KeyCode::tryFrom($value) : null;
        if ($key === null) {
          Debug::warn("Ignoring invalid player input binding for $action.");
          continue 2;
        }
        $keys[] = $key;
      }
      $bindings[$action] = $keys;
    }
    return $bindings;
  }

  /**
   * Save only changed keyboard keys; project action metadata stays authored.
   *
   * @param array<string, array{keys?: KeyCode[]}> $bindings Live bindings.
   * @param array<string, array{keys?: KeyCode[]}> $defaults Authored and Engine defaults.
   */
  public function setInputBindings(array $bindings, array $defaults): void
  {
    $overrides = [];
    foreach ($bindings as $action => $binding) {
      if (! isset($defaults[$action])) {
        continue;
      }
      $keys = $binding['keys'] ?? [];
      if (! is_array($keys) || ! array_all($keys, static fn(mixed $key): bool => $key instanceof KeyCode)) {
        throw new InvalidArgumentException("Invalid keys for action $action.");
      }
      if ($keys !== ($defaults[$action]['keys'] ?? [])) {
        $overrides[$action] = array_values($keys);
      }
    }
    parent::set('input.bindings', self::encodeInputBindings($overrides));
  }

  public function persist(): void
  {
    $directory = dirname($this->filename);
    if (! self::runFileOperation(fn() => is_dir($directory))) {
      try {
        self::runFileOperation(fn() => mkdir($directory, 0777, true));
      } catch (RuntimeException $exception) {
        if (! self::runFileOperation(fn() => is_dir($directory))) {
          throw new RuntimeException("Could not create player data directory: $directory", previous: $exception);
        }
      }
      if (! self::runFileOperation(fn() => is_dir($directory))) {
        throw new RuntimeException("Could not create player data directory: $directory");
      }
    }

    $data = $this->getOverrides();
    $bindings = $this->getInputBindings();
    if ($bindings !== []) {
      $data['input']['bindings'] = self::encodeInputBindings($bindings);
    }
    $content = json_encode((object) $data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $temporary = self::runFileOperation(fn() => tempnam($directory, '.player-settings-'));
    if ($temporary === false) {
      throw new RuntimeException('Could not create a temporary player settings file.');
    }
    try {
      if (self::runFileOperation(fn() => file_put_contents($temporary, $content)) !== strlen($content)
        || ! self::runFileOperation(fn() => rename($temporary, $this->filename))) {
        throw new RuntimeException('Could not write player settings.');
      }
    } finally {
      try {
        if (self::runFileOperation(fn() => is_file($temporary))) {
          self::runFileOperation(fn() => unlink($temporary));
        }
      } catch (RuntimeException $exception) {
        Debug::warn('Could not clean up temporary player settings: ' . $exception->getMessage());
      }
    }
  }

  /** @param array<string, KeyCode[]> $bindings */
  private static function encodeInputBindings(array $bindings): array
  {
    return array_map(static fn(array $keys): array => array_map(
      static fn(KeyCode $key): string => $key->value, $keys), $bindings);
  }

  /** Convert local filesystem warnings before Game's fatal handler sees them. */
  private static function runFileOperation(callable $operation): mixed
  {
    set_error_handler(static function (int $severity, string $message): never {
      throw new RuntimeException($message);
    });
    try {
      return $operation();
    } finally {
      restore_error_handler();
    }
  }
}
