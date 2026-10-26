<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Events\MapEventDefinition;
use Ichiloto\Engine\Field\WorldObjectPlacement;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/** Resolves the {@see MapEventDefinition} envelope for runtime, Editor drafts and validation. */
final class MapEventResolver
{
    public static function inspect(array $data, array $eventLayer, string $mapId): MapEventResolution
    {
        $areas = [];
        foreach ($eventLayer as $y => $row) {
            foreach ($row as $x => $cell) {
                $marker = TerminalText::stripAnsi($cell);
                if (trim($marker) !== '') { $areas[$marker][] = [$x, $y]; }
            }
        }
        $events = $data['events'] ?? [];
        if (!is_array($events)) {
            return new MapEventResolution([], [new MapValidationIssue($mapId, 'event.definition', 'Map events must be an array.', path: ['events'])]);
        }
        $resolved = $issues = $seen = [];
        foreach ($events as $key => $definition) {
            $marker = is_string($key) ? $key : (is_array($definition) ? ($definition['marker'] ?? null) : null);
            $marker = is_string($marker) ? $marker : null;
            $cells = $marker === null ? [] : ($areas[$marker] ?? []);
            unset($areas[$marker ?? '']);
            try {
                if (!is_array($definition)) { throw new InvalidArgumentException("Invalid event definition on map {$mapId}."); }
                // Preserve the legacy explicit-area form; it is still checked before use.
                if (isset($definition['area']) && !is_string($key) && !array_key_exists('subject', $definition)) {
                    CellArea::fromArray($definition['area'], 'Event area');
                    $resolved[] = $definition;
                    continue;
                }
                if ($marker === null || TerminalText::displayWidth($marker) !== 1 || trim($marker) === '') {
                    throw new InvalidArgumentException('Events in split map data must be keyed by a single-character marker or declare one explicitly.');
                }
                if (isset($seen[$marker])) { throw new InvalidArgumentException("Duplicate event marker '{$marker}' on map {$mapId}."); }
                $seen[$marker] = true;
                if (array_key_exists('subject', $definition)) {
                    if (array_key_exists('area', $definition) || $cells !== []) {
                        throw new InvalidArgumentException("Event '{$marker}' subject owns its area; remove its painted marker cells and authored area.");
                    }
                    $subject = $definition['subject'];
                    if (!is_array($subject) || array_diff(array_keys($subject), ['kind', 'id']) !== []
                        || ($subject['kind'] ?? null) !== 'world_object') {
                        throw new InvalidArgumentException("Event '{$marker}' subject requires kind world_object and a stable id.");
                    }
                    $id = WorldObjectPlacement::requireId($subject['id'] ?? null);
                    $placements ??= WorldObjectPlacement::readMap($data, $eventLayer);
                    $placement = $placements[$id] ?? throw new InvalidArgumentException("Event '{$marker}' subject '{$id}' was not found on map {$mapId}.");
                    $cells = $placement->cells;
                    $conditions = $definition['conditions'] ?? [];
                    if (!is_array($conditions) || !array_is_list($conditions)) {
                        throw new InvalidArgumentException("Event '{$marker}' conditions require a list.");
                    }
                    WorldConditionEvaluator::validateAll($conditions, "events/{$marker}/conditions");
                    $definition['conditions'] = [...$placement->conditions, ...$conditions];
                } elseif ($cells === []) {
                    throw new InvalidArgumentException("Event marker '{$marker}' was not found in {$mapId}.");
                }
                $definition['area'] = CellArea::fromCells($cells)->toArray();
                $definition['marker'] = $marker;
                $resolved[] = $definition;
            } catch (InvalidArgumentException $error) {
                $issues[] = new MapValidationIssue($mapId, array_key_exists('subject', is_array($definition) ? $definition : []) ? 'event.subject' : 'event.area',
                    $error->getMessage(), $marker, $cells, ['events', $key], 'Bind this event to its subject or place its marker cells, with one area owner.');
            }
        }
        foreach ($areas as $marker => $cells) {
            $issues[] = new MapValidationIssue($mapId, 'event.unmapped', "Unmapped event markers found in {$mapId}: {$marker}.", (string) $marker,
                $cells, ['events'], 'Define the event or remove its unowned marker cells.');
        }
        return new MapEventResolution($resolved, $issues);
    }
}
