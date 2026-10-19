<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use BackedEnum;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Entities\Actions\ChestOpeningAction;
use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Events\Enumerations\EventEngagement;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use InvalidArgumentException;
use RuntimeException;

/** Engine-owned readiness checks for current in-memory map sources; never writes drafts or runtime state. */
final class MapCompletenessValidator
{
    /**
     * @param callable(string): ?string $resolveInventoryReference Current catalog: stable ID, null for missing, exception for ambiguity.
     * @return list<MapValidationIssue>
     */
    public static function validate(string $mapId, array $data, array $eventLayer, MapLayerSet $layers,
        ?MapGraphics $graphics, string $assetRoot, callable $resolveInventoryReference,
        ?FieldPresentationCatalog $fieldCatalog = null, bool $requiresGraphics = false): array
    {
        $issues = self::validateEvents($mapId, $data, $eventLayer);
        foreach (is_array($data['events'] ?? null) ? $data['events'] : [] as $key => $event) {
            if (!is_array($event)) { continue; }
            $class = $event['class'] ?? null;
            if (!is_string($class) || !is_a($class, ChestEventTrigger::class, true)) { continue; }
            $marker = is_string($key) ? $key : (is_string($event['marker'] ?? null) ? $event['marker'] : null);
            if (!is_array($event['data'] ?? null)) { continue; } // event.definition reports the malformed data.
            $issues = [...$issues, ...self::validateChestReward($event['data'], $mapId, $resolveInventoryReference, $marker,
                ['events', $key, 'data'])];
        }
        try { $layers->assertMatchingGrid($eventLayer, "Event map {$mapId}"); }
        catch (InvalidArgumentException $error) {
            $issues[] = new MapValidationIssue($mapId, 'event.grid', $error->getMessage());
        }
        return [...$issues, ...self::validateCoverage($mapId, $data, $layers, $graphics, $assetRoot, $fieldCatalog, $requiresGraphics)];
    }

    /** @return list<MapValidationIssue> */
    public static function validateEvents(string $mapId, array $data, array $eventLayer): array
    {
        $result = MapEventResolver::inspect($data, $eventLayer, $mapId);
        $issues = $result->issues;
        foreach ($result->events as $event) {
            $marker = $event['marker'] ?? null;
            try {
                $class = $event['class'] ?? null;
                if (!is_string($class) || !is_subclass_of($class, EventTrigger::class)) {
                    throw new InvalidArgumentException('Event class must resolve to an EventTrigger subclass.');
                }
                if (!is_array($event['data'] ?? null)) { throw new InvalidArgumentException('Event data must be an array.'); }
                EventTrigger::parseFacing($event['facing'] ?? []);
                if (array_key_exists('engagement', $event)) { EventEngagement::parse($event['engagement']); }
            } catch (InvalidArgumentException $error) {
                $issues[] = new MapValidationIssue($mapId, 'event.definition', $error->getMessage(), $marker,
                    $event['area']['cells'] ?? [], ['events', $marker ?? '']);
            }
        }
        return $issues;
    }

    /** @return list<MapValidationIssue> */
    public static function validateChestReward(array $data, string $mapId, callable $resolveInventoryReference,
        ?string $marker = null, array $path = []): array
    {
        $error = static fn(string $message, string $field, string $hint = ''): array => [new MapValidationIssue(
            $mapId, 'chest.reward', $message, $marker, path: [...$path, $field], hint: $hint)];
        $declared = $data['lootType'] ?? '';
        if ($declared instanceof BackedEnum) { $declared = $declared->value; }
        $type = $declared === '' || $declared === null ? LootType::ITEM : (is_string($declared) ? LootType::tryFrom($declared) : null);
        if ($type === null) { return $error('Chest loot type is unknown.', 'lootType', 'Choose a supported loot type.'); }
        if (!ChestOpeningAction::supportsLootType($type)) {
            return $error("The Engine cannot grant chest loot type {$type->value}.", 'lootType', 'Choose a grantable inventory item or gold.');
        }
        $quantity = $data['quantity'] ?? 1;
        if (!self::isWholeNumber($quantity)) { return $error('Chest quantity must be a whole number.', 'quantity'); }
        $quantity = (int) $quantity;
        $loot = $data['loot'] ?? null;
        if ($loot instanceof BackedEnum) { $loot = $loot->value; }
        if ($type === LootType::GOLD) {
            $blank = $loot === null || (is_string($loot) && trim($loot) === '');
            if (!$blank && !self::isWholeNumber($loot)) { return $error('Chest gold amount must be a whole number.', 'loot'); }
            $amount = $blank ? $quantity : (int) $loot;
            return $amount > 0 ? [] : $error('Chest gold amount must be above zero.', $blank ? 'quantity' : 'loot');
        }
        if (!is_string($loot) || trim($loot) === '') {
            return $error('Chest has no valid inventory reference chosen.', 'loot', 'Choose an item from the catalog.');
        }
        $issues = [];
        try {
            $id = $resolveInventoryReference(trim($loot));
            if (!is_string($id) || trim($id) === '') {
                $issues = $error('Chest inventory reference does not resolve: ' . trim($loot), 'loot');
            }
        } catch (InvalidArgumentException|RuntimeException|NotFoundException $exception) {
            $issues = $error($exception->getMessage(), 'loot', 'Choose an unambiguous stable inventory ID.');
        }
        if ($quantity < 1) { $issues = [...$issues, ...$error('Chest quantity must be above zero.', 'quantity')]; }
        return $issues;
    }

    /**
     * Uses the field's tile/glyph and object-art contracts, independently of any graphical editor.
     * A terminal-only project needs no graphics; a graphical project opts into required map graphics.
     * @return list<MapValidationIssue>
     */
    public static function validateCoverage(string $mapId, array $data, MapLayerSet $layers, ?MapGraphics $graphics,
        string $assetRoot, ?FieldPresentationCatalog $fieldCatalog = null, bool $requiresGraphics = false): array
    {
        $issues = [];
        try {
            $fieldCatalog ??= array_any(is_array($data['worldObjects'] ?? null) ? $data['worldObjects'] : [],
                static fn($entry): bool => is_array($entry) && array_key_exists('resource', $entry))
                ? FieldPresentationCatalog::load($assetRoot) : null;
            $objects = WorldObjectDefinition::readMap($data, $layers, $graphics, catalog: $fieldCatalog);
        } catch (InvalidArgumentException|RuntimeException $error) {
            $objects = [];
            $issues[] = new MapValidationIssue($mapId, 'object.reference', $error->getMessage(), path: ['worldObjects']);
        }
        if ($graphics === null) {
            if ($requiresGraphics || array_key_exists('tileset', $data)) {
                $issues[] = new MapValidationIssue($mapId, 'graphics.missing', 'Map graphics are unavailable.', path: ['tileset'],
                    hint: 'Resolve its tileset and tile layers using MapGraphics; retain loader diagnostics.');
            }
            return $issues;
        }
        $covered = [];
        foreach ($objects as $index => $object) {
            $usable = true;
            if ($object->resource?->sign !== null) {
                try { $object->resource->sign->getBoard($assetRoot); }
                catch (InvalidArgumentException|RuntimeException $error) {
                    $usable = false;
                    $issues[] = new MapValidationIssue($mapId, 'object.art', $error->getMessage(), path: ['worldObjects', $index]);
                }
            } else {
                foreach ([$object->sprites, ...array_column($object->variants, 'sprites')] as $sprite) {
                    if ($sprite === null) { continue; } // Explicit absence is authored, missing declared artwork is an error.
                    $role = new FieldSpriteRole($sprite, $assetRoot, "Map validation {$mapId}/{$object->id}");
                    try {
                        if ($role->getStillFrame($object->pivot) === null) {
                            $usable = false;
                            $issues[] = new MapValidationIssue($mapId, 'object.art', "World object '{$object->id}' has unavailable field artwork.",
                                path: ['worldObjects', $index]);
                        }
                    } finally { $role->release(); }
                }
            }
            // Conditional presence must not conceal an unpresented base glyph while the object is absent.
            if ($usable && $object->conditions === []) { $covered = array_replace_recursive($covered, $object->coverage['glyphs'] ?? []); }
        }
        foreach ($graphics->getShownGlyphCells($layers, $assetRoot) as $cell) {
            if (isset($covered[$cell['layer']][$cell['y']][$cell['x']])) { continue; }
            $issues[] = new MapValidationIssue($mapId, 'graphics.glyph', "Gameplay glyph '{$cell['glyph']}' on {$cell['layer']} has no graphical presentation.",
                cells: [[$cell['x'], $cell['y']]], path: ['layers', $cell['layer']],
                hint: 'Give its stable subject artwork or remove an obsolete fixture glyph through the Editor session.');
        }
        foreach (is_array($data['npcs'] ?? null) ? $data['npcs'] : [] as $index => $entry) {
            $placement = NpcPlacement::fromArray($entry);
            if ($placement === null) { continue; }
            $role = null;
            try {
                if (!is_array($entry['sprites2d'] ?? null)) {
                    if (($entry['sprite'] ?? '@') === '') { continue; }
                    throw new InvalidArgumentException("NPC '{$placement->name}' has no field artwork.");
                }
                $role = new FieldSpriteRole(CharacterSheet::fromArray($entry['sprites2d']), $assetRoot, 'Map validation NPC');
                if ($role->getStillFrame(new PresentationSpritePivot()) === null) {
                    throw new InvalidArgumentException("NPC '{$placement->name}' has unavailable field artwork.");
                }
            } catch (InvalidArgumentException|RuntimeException $error) {
                $issues[] = new MapValidationIssue($mapId, 'npc.art', $error->getMessage(), cells: [[$placement->x, $placement->y]], path: ['npcs', $index]);
            } finally { $role?->release(); }
        }
        return $issues;
    }

    private static function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value < PHP_INT_MAX)
            || (is_string($value) && filter_var(trim($value), FILTER_VALIDATE_INT) !== false);
    }
}
