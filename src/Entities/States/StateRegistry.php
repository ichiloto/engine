<?php

namespace Ichiloto\Engine\Entities\States;

use Assegai\Util\Path;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Loads and caches the project's authored states.
 *
 * @package Ichiloto\Engine\Entities\States
 */
class StateRegistry
{
  /** @var array<string, array<string, State>>|null States keyed by project source, then by state ID. */
  protected static ?array $states = null;

  /**
   * Returns a state definition by id.
   *
   * @param string $stateId The state id.
   * @return State|null The state, or null when unknown.
   */
  public static function get(string $stateId): ?State
  {
    return self::all()[trim($stateId)] ?? null;
  }

  /**
   * Returns every authored state, keyed by id.
   *
   * @return array<string, State> The states.
   */
  public static function all(): array
  {
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'states.php');
    if (isset(self::$states[$filename])) {
      return self::$states[$filename];
    }

    if (! file_exists($filename)) {
      return self::$states[$filename] = [];
    }

    $entries = require $filename;

    $states = [];
    foreach (is_array($entries) ? $entries : [] as $entry) {
      if ($entry instanceof State) {
        $states[$entry->id] = $entry;
        continue;
      }

      if (! is_array($entry)) {
        continue;
      }

      try {
        $state = State::fromArray($entry);
        $states[$state->id] = $state;
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Skipping invalid state definition: %s', $exception->getMessage()));
      }
    }

    return self::$states[$filename] = $states;
  }

  /**
   * Clears the cache (tests and hot reloads).
   *
   * @return void
   */
  public static function reset(): void
  {
    self::$states = null;
  }
}
