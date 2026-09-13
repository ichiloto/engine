<?php

namespace Ichiloto\Engine\Core;

/**
 * The persistent world-state store.
 *
 * Holds everything a running game needs to remember about the player's
 * progress that is not derivable from the party itself: named boolean
 * switches, named variables, recorded story events, and the completion
 * state of one-shot map events (so a looted chest stays looted across map
 * reloads and save files).
 *
 * The store round-trips through {@see \Ichiloto\Engine\Scenes\Game\GameConfig}
 * into save files as a plain array.
 *
 * @package Ichiloto\Engine\Core
 */
class GameState
{
  /**
   * @var \Closure|null Observer invoked as `($kind, $name)` after a write,
   * where `$kind` is `switch`, `variable`, or `event`. Never serialized —
   * the owning scene re-wires it on load.
   */
  public ?\Closure $onChange = null;
  /**
   * @var array<string, bool> Named boolean switches.
   */
  protected array $switches = [];
  /**
   * @var array<string, int|float|string> Named variables.
   */
  protected array $variables = [];
  /**
   * @var string[] Recorded story-event flags.
   */
  protected(set) array $storyEvents = [];
  /**
   * @var array<string, true> Completed one-shot map events, keyed "mapId:marker".
   */
  protected array $completedEvents = [];
  /**
   * @var array<string, true> The maps the party has set foot on, keyed by map id.
   */
  protected array $visitedMaps = [];

  /**
   * Sets a named switch.
   *
   * @param string $name The switch name.
   * @param bool $value The switch value.
   * @return void
   */
  public function setSwitch(string $name, bool $value = true): void
  {
    $name = trim($name);

    if ($name === '') {
      return;
    }

    $this->switches[$name] = $value;

    if ($value) {
      $this->onChange?->__invoke('switch', $name);
    }
  }

  /**
   * Returns a named switch's value.
   *
   * @param string $name The switch name.
   * @return bool The switch value; false when never set.
   */
  public function getSwitch(string $name): bool
  {
    return $this->switches[trim($name)] ?? false;
  }

  /**
   * Sets a named variable.
   *
   * @param string $name The variable name.
   * @param int|float|string $value The value.
   * @return void
   */
  public function setVariable(string $name, int|float|string $value): void
  {
    $name = trim($name);

    if ($name === '') {
      return;
    }

    $this->variables[$name] = $value;
    $this->onChange?->__invoke('variable', $name);
  }

  /**
   * Returns a named variable's value.
   *
   * @param string $name The variable name.
   * @param int|float|string $default The value returned when never set.
   * @return int|float|string The variable value.
   */
  public function getVariable(string $name, int|float|string $default = 0): int|float|string
  {
    return $this->variables[trim($name)] ?? $default;
  }

  /**
   * Adds an amount to a numeric variable.
   *
   * @param string $name The variable name.
   * @param int|float $amount The amount to add.
   * @return void
   */
  public function addToVariable(string $name, int|float $amount): void
  {
    $current = $this->getVariable($name, 0);
    $this->setVariable($name, (is_numeric($current) ? $current : 0) + $amount);
  }

  /**
   * Records a story event.
   *
   * @param string $eventName The story-event flag.
   * @return void
   */
  public function recordStoryEvent(string $eventName): void
  {
    $eventName = trim($eventName);

    if ($eventName === '' || $this->hasStoryEvent($eventName)) {
      return;
    }

    $this->storyEvents[] = $eventName;
    $this->onChange?->__invoke('event', $eventName);
  }

  /**
   * Determines whether a story event has been recorded.
   *
   * @param string $eventName The story-event flag.
   * @return bool True when recorded.
   */
  public function hasStoryEvent(string $eventName): bool
  {
    return in_array(trim($eventName), $this->storyEvents, true);
  }

  /**
   * Marks a one-shot map event as completed.
   *
   * @param string $mapId The map id.
   * @param string $marker The event marker on that map.
   * @return void
   */
  public function markEventComplete(string $mapId, string $marker): void
  {
    $key = $this->eventKey($mapId, $marker);

    if ($key === null) {
      return;
    }

    $this->completedEvents[$key] = true;
  }

  /**
   * Determines whether a one-shot map event has been completed.
   *
   * @param string $mapId The map id.
   * @param string $marker The event marker on that map.
   * @return bool True when completed.
   */
  public function isEventComplete(string $mapId, string $marker): bool
  {
    $key = $this->eventKey($mapId, $marker);

    return $key !== null && isset($this->completedEvents[$key]);
  }

  /**
   * Records that the party has been to a map.
   *
   * @param string $mapId The map's id.
   * @return void
   */
  public function markMapVisited(string $mapId): void
  {
    $mapId = trim($mapId);

    if ($mapId !== '') {
      $this->visitedMaps[$mapId] = true;
    }
  }

  /**
   * Determines whether the party has been to a map.
   *
   * @param string $mapId The map's id.
   * @return bool True when they have.
   */
  public function hasVisitedMap(string $mapId): bool
  {
    return isset($this->visitedMaps[trim($mapId)]);
  }

  /**
   * Returns the maps the party has been to.
   *
   * @return string[] The visited map ids.
   */
  public function visitedMaps(): array
  {
    return array_keys($this->visitedMaps);
  }

  /**
   * @return array{switches: array<string, bool>, variables: array<string, int|float|string>, storyEvents: string[], completedEvents: array<string, true>, visitedMaps: array<string, true>}
   */
  public function toArray(): array
  {
    return [
      'switches' => $this->switches,
      'variables' => $this->variables,
      'storyEvents' => $this->storyEvents,
      'completedEvents' => $this->completedEvents,
      'visitedMaps' => $this->visitedMaps,
    ];
  }

  /**
   * Restores a previously captured state without emitting write callbacks.
   *
   * This is the rollback primitive for bounded Engine transactions. It does
   * not replace the observer wired by the owning GameScene.
   *
   * @param array<string, mixed> $snapshot
   */
  public function restoreSnapshot(array $snapshot): void
  {
    $restored = self::fromArray($snapshot);
    $this->switches = $restored->switches;
    $this->variables = $restored->variables;
    $this->storyEvents = $restored->storyEvents;
    $this->completedEvents = $restored->completedEvents;
    $this->visitedMaps = $restored->visitedMaps;
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    $state = new self();

    foreach ((array) ($data['switches'] ?? []) as $name => $value) {
      $state->setSwitch((string) $name, (bool) $value);
    }

    foreach ((array) ($data['variables'] ?? []) as $name => $value) {
      if (is_int($value) || is_float($value) || is_string($value)) {
        $state->setVariable((string) $name, $value);
      }
    }

    foreach ((array) ($data['storyEvents'] ?? []) as $eventName) {
      if (is_string($eventName)) {
        $state->recordStoryEvent($eventName);
      }
    }

    foreach (array_keys((array) ($data['completedEvents'] ?? [])) as $key) {
      if (is_string($key) && str_contains($key, ':')) {
        [$mapId, $marker] = explode(':', $key, 2);
        $state->markEventComplete($mapId, $marker);
      }
    }

    // Absent in saves written before the region map existed, which simply
    // means the party has been nowhere yet as far as it is concerned.
    foreach (array_keys((array) ($data['visitedMaps'] ?? [])) as $mapId) {
      if (is_string($mapId)) {
        $state->markMapVisited($mapId);
      }
    }

    return $state;
  }

  /**
   * Builds the storage key for a map event.
   *
   * @param string $mapId The map id.
   * @param string $marker The event marker.
   * @return string|null The key, or null for blank identity parts.
   */
  protected function eventKey(string $mapId, string $marker): ?string
  {
    $mapId = trim($mapId);
    $marker = trim($marker);

    if ($mapId === '' || $marker === '') {
      return null;
    }

    return "{$mapId}:{$marker}";
  }
}
