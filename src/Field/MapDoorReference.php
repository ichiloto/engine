<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use InvalidArgumentException;

/** A script reference to a map event that owns a transfer destination. */
final readonly class MapDoorReference
{
  public const string COMMAND = 'transfer_through_door';

  private function __construct(public string $map, public string $event)
  {
  }

  /** @param array<string, mixed> $command */
  public static function fromCommand(array $command): self
  {
    if (array_diff(array_keys($command), ['type', 'map', 'event']) !== []
      || !is_string($command['map'] ?? null) || trim($command['map']) === ''
      || !is_string($command['event'] ?? null) || trim($command['event']) === '') {
      throw new InvalidArgumentException(self::COMMAND . ' requires a map and event marker.');
    }
    return new self(trim($command['map']), trim($command['event']));
  }

  /** @param list<array<string, mixed>> $events Resolved map events. */
  public function resolve(array $events): Location
  {
    foreach ($events as $definition) {
      if (($definition['marker'] ?? null) !== $this->event) { continue; }
      $class = $definition['class'] ?? null;
      if (is_string($class) && is_a($class, TransferPlayerTrigger::class, true)) {
        /** @var TransferPlayerTrigger $trigger */
        $trigger = EventTriggerFactory::create($definition, $this->map);
        return $trigger->getDestination();
      }
      throw new InvalidArgumentException("Event '{$this->event}' on map '{$this->map}' is not a transfer door.");
    }
    throw new InvalidArgumentException("Door '{$this->event}' was not found on map '{$this->map}'.");
  }

  public function resolveFromProject(string $assetRoot): Location
  {
    $mapsRoot = realpath($assetRoot . '/Maps');
    if ($mapsRoot === false) {
      throw new InvalidArgumentException('The project has no Maps directory.');
    }
    $paths = MapSourceReader::resolvePaths($mapsRoot, $this->map);
    foreach (MapSourceReader::EVENT_SOURCE_FILES as $field) {
      $file = realpath($paths[$field]);
      if ($file === false || !is_file($file) || !str_starts_with($file, $mapsRoot . DIRECTORY_SEPARATOR)) {
        throw new InvalidArgumentException("Door source map '{$this->map}' was not found in the project.");
      }
      $paths[$field] = $file;
    }
    return $this->resolve(MapSourceReader::readEventSource($paths)['data']['events']);
  }
}
