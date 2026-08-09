<?php

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\InputConfig;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Reads and rewrites the player's key bindings.
 *
 * A rebind takes effect immediately, because the running game reads its
 * bindings from {@see InputManager}, and is written back to the project's
 * `input.php` so it survives the session.
 *
 * @package Ichiloto\Engine\IO
 */
class InputBindings
{
  /**
   * Actions the player must not rebind.
   *
   * Escape backs out of every screen, including the rebinding screen itself,
   * so binding it elsewhere could strand a player with no way out.
   */
  protected const array LOCKED_ACTIONS = ['back'];

  /**
   * Returns the rebindable actions in a stable display order.
   *
   * @return array<int, array{action: string, description: string, keys: KeyCode[]}> The bindings.
   */
  public function all(): array
  {
    $bindings = [];

    foreach (InputManager::getBindings() as $action => $binding) {
      if (in_array($action, self::LOCKED_ACTIONS, true)) {
        continue;
      }

      $bindings[] = [
        'action' => $action,
        'description' => strval($binding['description'] ?? ''),
        'keys' => $this->keysOf($binding),
      ];
    }

    return $bindings;
  }

  /**
   * Describes an action's keys for display.
   *
   * @param string $action The action to describe.
   * @return string The key names, comma separated.
   */
  public function describeKeys(string $action): string
  {
    $binding = InputManager::getBindings()[$action] ?? [];
    $names = array_map(static fn(KeyCode $key): string => $key->name, $this->keysOf($binding));

    return $names === [] ? 'Unbound' : implode(', ', $names);
  }

  /**
   * Binds a key to an action, replacing whatever it had.
   *
   * A key may drive more than one action (escape cancels and opens the menu
   * in the shipped bindings), so nothing is unbound elsewhere.
   *
   * @param string $action The action to rebind.
   * @param KeyCode $key The key to bind.
   * @return bool True when the action exists and was rebound and persisted.
   */
  public function rebind(string $action, KeyCode $key): bool
  {
    if (in_array($action, self::LOCKED_ACTIONS, true)) {
      return false;
    }

    if (! InputManager::setBinding($action, [$key])) {
      return false;
    }

    return $this->persist();
  }

  /**
   * Restores the bindings the project shipped with.
   *
   * @return bool True when the defaults were restored and persisted.
   */
  public function restoreDefaults(): bool
  {
    InputManager::setBindings(InputManager::getDefaultBindings());

    return $this->persist();
  }

  /**
   * Writes the live bindings back to the project's input configuration.
   *
   * @return bool True when the bindings were written.
   */
  protected function persist(): bool
  {
    $config = ConfigStore::get(InputConfig::class);

    if (! $config instanceof InputConfig) {
      Debug::warn('Input configuration is not available; the rebind applies to this session only.');

      return false;
    }

    foreach (InputManager::getBindings() as $action => $binding) {
      $config->set($action, $binding);
    }

    try {
      $config->persist();
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Could not write the input configuration: %s', $exception->getMessage()));

      return false;
    }

    return true;
  }

  /**
   * Reads a binding's key codes, discarding anything unrecognised.
   *
   * @param array<string, mixed> $binding The binding entry.
   * @return KeyCode[] The bound keys.
   */
  protected function keysOf(array $binding): array
  {
    return array_values(array_filter(
      (array) ($binding['keys'] ?? []),
      static fn(mixed $key): bool => $key instanceof KeyCode
    ));
  }
}
