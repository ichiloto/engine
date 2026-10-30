<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\GameState;
use InvalidArgumentException;

/** Stable source-owned destinations. Saves select a name; current source owns its geometry. */
final readonly class NpcPlacementSet
{
    public const string DEFAULT = 'default';
    public const array FIELDS = ['x', 'y', 'facing'];

    /** @param array<string, array{x:int, y:int, facing:MovementHeading}> $placements */
    private function __construct(public array $placements) {}

    public static function fromEntry(array $entry): self
    {
        if (!is_string($entry['id'] ?? null) || trim($entry['id']) === '') {
            throw new InvalidArgumentException('Named NPC placements require a stable NPC id.');
        }
        $read = static function (mixed $value, string $label, MovementHeading $fallback): array {
            if (!is_array($value) || array_diff(array_keys($value), self::FIELDS) !== []
                || !is_int($value['x'] ?? null) || !is_int($value['y'] ?? null)
                || $value['x'] < 0 || $value['y'] < 0) {
                throw new InvalidArgumentException("NPC placement {$label} requires nonnegative integer x/y and optional facing.");
            }
            $heading = $value['facing'] ?? $fallback->value;
            $heading = is_string($heading) ? MovementHeading::tryFrom(ucfirst(strtolower(trim($heading)))) : null;
            if ($heading === null || $heading === MovementHeading::NONE) {
                throw new InvalidArgumentException("NPC placement {$label} facing must be cardinal.");
            }
            return ['x' => $value['x'], 'y' => $value['y'], 'facing' => $heading];
        };
        $default = $read(array_intersect_key($entry, array_flip(self::FIELDS)), self::DEFAULT, MovementHeading::SOUTH);
        $variants = $entry['placements'] ?? null;
        if (!is_array($variants) || ($variants !== [] && array_is_list($variants))) {
            throw new InvalidArgumentException('NPC placements must be a dictionary of stable placement ids.');
        }
        $placements = [self::DEFAULT => $default];
        foreach ($variants as $id => $definition) {
            if (!is_string($id) || trim($id) !== $id || $id === '' || $id === self::DEFAULT || str_contains($id, "\0")) {
                throw new InvalidArgumentException('NPC placement ids must be non-empty distinct names; default selects root x/y.');
            }
            $placements[$id] = $read($definition, $id, $default['facing']);
        }
        return new self($placements);
    }

    public static function getStateKey(string $mapId, string $npcId): string
    {
        return 'npc-placement:' . rawurlencode($mapId) . ':' . rawurlencode($npcId);
    }

    public function readSelection(GameState $state, string $mapId, string $npcId): string
    {
        $id = $state->getVariable(self::getStateKey($mapId, $npcId), self::DEFAULT);
        if (!is_string($id) || !isset($this->placements[$id])) {
            throw new InvalidArgumentException("NPC {$npcId} on {$mapId} selects an unavailable saved placement; migrate its stable name explicitly.");
        }
        return $id;
    }
}
