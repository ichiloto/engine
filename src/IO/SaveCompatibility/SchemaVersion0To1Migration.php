<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Scenes\Game\GameConfig;

/** Wraps the pre-WP1 payload and absorbs its legacy story-event list. */
final class SchemaVersion0To1Migration implements SaveSchemaMigrationInterface
{
  public function migrate(array $envelope, string $projectId): array
  {
    $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
    $config = $payload['config'] ?? null;

    if ($config instanceof GameConfig) {
      $data = $config->getSaveCompatibilityData();
      $gameState = is_array($data['gameState'] ?? null) ? $data['gameState'] : [];
      $storyEvents = array_values(array_filter(
        is_array($gameState['storyEvents'] ?? null) ? $gameState['storyEvents'] : [],
        'is_string'
      ));

      foreach (array_filter(is_array($data['events'] ?? null) ? $data['events'] : [], 'is_string') as $event) {
        if (! in_array($event, $storyEvents, true)) {
          $storyEvents[] = $event;
        }
      }

      $gameState['storyEvents'] = $storyEvents;
      $data['gameState'] = $gameState;
      $config->applySaveCompatibilityData($data);
    }

    $envelope['schemaVersion'] = 1;
    $envelope['projectId'] = $projectId;

    return $envelope;
  }
}
