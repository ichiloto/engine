<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/** Map-owned presentation source, never physical occupancy or save state. */
final readonly class WorldObjectDefinition
{
    public const string DATA_KEY = 'worldObjects';
    public const int MAX_OBJECTS = 128;
    public const int MAX_VARIANTS = 16;
    public const int MAX_CONDITIONS = 32;
    public const int MAX_CELLS = 256;

    private function __construct(
        public string $id,
        public int $x,
        public int $y,
        public PresentationSpritePivot $pivot,
        public GraphicalSpriteDefinition|CharacterSheet|FieldPoseAnimation|null $sprites,
        public array $variants,
        public array $coverage,
        public ?WorldObjectResource $resource = null,
        public ?\Ichiloto\Engine\Localization\LocalizedText $label = null,
        public ?WorldObjectTerminalPresentation $terminal = null,
        public array $terminalFootprint = [],
    ) {}

    /**
     * Validate the declaration independently of optional image availability.
     * @param array<string, ?string>|null $tileOwners Owners resolved without tileset pieces when graphics are unavailable.
     *     Null means layer declarations are unavailable; a null owner means inference is unavailable.
     */
    public static function readMap(array $data, MapLayerSet $layers, ?MapGraphics $graphics = null, ?array $tileOwners = null,
        ?FieldPresentationCatalog $catalog = null): array
    {
        if (!array_key_exists(self::DATA_KEY, $data)) { return []; }
        $entries = $data[self::DATA_KEY];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::MAX_OBJECTS) {
            throw new InvalidArgumentException('worldObjects must be a list of at most 128 declarations.');
        }
        $objects = $claimed = $terminalOwners = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['id', 'anchor', 'resource', 'pivot', 'sprites2d', 'variants', 'covers', 'label', 'terminal']) !== []) {
                throw new InvalidArgumentException('A world object accepts id, anchor, covers, a sign label and either resource or inline pivot/sprites2d/variants.');
            }
            $id = self::requireId($entry['id'] ?? null);
            if (isset($objects[$id])) { throw new InvalidArgumentException("Duplicate world object id '{$id}'."); }
            $anchor = $entry['anchor'] ?? null;
            if (!is_array($anchor) || array_diff(array_keys($anchor), ['x', 'y']) !== []
                || !is_int($anchor['x'] ?? null) || !is_int($anchor['y'] ?? null)
                || !isset($layers->getComposedGrid()[$anchor['y']][$anchor['x']])) {
                throw new InvalidArgumentException("World object '{$id}' requires an anchor with integer x/y inside its map.");
            }
            $resource = null;
            if (array_key_exists('resource', $entry)) {
                if (array_intersect(array_keys($entry), ['pivot', 'sprites2d', 'variants']) !== []) {
                    throw new InvalidArgumentException("World object '{$id}' resource references cannot include inline pivot, sprites2d or variants.");
                }
                WorldObjectResource::assertId($entry['resource']);
                $resource = ($catalog ?? new FieldPresentationCatalog())->getResource($entry['resource']);
            } elseif (!is_array($entry['pivot'] ?? null) || !array_key_exists('sprites2d', $entry)) {
                throw new InvalidArgumentException("World object '{$id}' requires an explicit pivot and sprites2d role (or null).");
            }
            $pivot = $resource?->pivot ?? PresentationSpritePivot::fromArray($entry['pivot']);
            $sprites = $resource !== null ? $resource->sprites : self::readSprites($entry['sprites2d']);
            $terminal = WorldObjectTerminalPresentation::read($entry['terminal'] ?? null);
            $label = null;
            if ($resource?->sign !== null) {
                $label = \Ichiloto\Engine\Localization\LocalizedText::fromArray($entry['label'] ?? null);
                if (array_key_exists('variants', $entry)) {
                    throw new InvalidArgumentException('Sign resources do not accept image variants; their localized label remains the same subject.');
                }
            } elseif (array_key_exists('label', $entry)) {
                throw new InvalidArgumentException('Only named sign resources accept a localized label.');
            }
            $variants = array_key_exists('variants', $entry) ? $entry['variants'] : [];
            if (!is_array($variants) || !array_is_list($variants) || count($variants) > self::MAX_VARIANTS) {
                throw new InvalidArgumentException("World object '{$id}' variants must be a list of at most 16 entries.");
            }
            $parsed = $variantIds = [];
            foreach ($variants as $variant) {
                if (!is_array($variant) || array_diff(array_keys($variant), ['id', 'conditions', 'sprites2d', 'terminal']) !== []
                    || !array_key_exists('sprites2d', $variant)) {
                    throw new InvalidArgumentException("World object '{$id}' variants require id, conditions and sprites2d.");
                }
                $variantId = self::requireId($variant['id'] ?? null);
                if (isset($variantIds[$variantId])) { throw new InvalidArgumentException("Duplicate world object variant '{$variantId}'."); }
                $conditions = $variant['conditions'] ?? null;
                if (!is_array($conditions) || !array_is_list($conditions) || $conditions === [] || count($conditions) > self::MAX_CONDITIONS) {
                    throw new InvalidArgumentException("World object '{$id}' variant conditions require 1..32 conditions.");
                }
                WorldConditionEvaluator::validateAll($conditions, "worldObjects/{$id}/{$variantId}/conditions");
                $variantIds[$variantId] = true;
                $parsed[] = ['id' => $variantId, 'conditions' => $conditions, 'sprites' => self::readSprites($variant['sprites2d']),
                    'terminal' => array_key_exists('terminal', $variant) ? WorldObjectTerminalPresentation::read($variant['terminal']) : $terminal];
            }
            [$coverage, $coverageResolved] = self::readCoverage($entry, $layers, $graphics, $tileOwners);
            $terminalFootprint = $terminalClaims = [];
            $terminalPresentations = [$terminal, ...array_column($parsed, 'terminal')];
            $needsFootprint = array_any($terminalPresentations, static fn(?WorldObjectTerminalPresentation $presentation): bool
                => $presentation?->usesOwnedFootprint() ?? false);
            foreach ($needsFootprint ? ($coverage['glyphs'] ?? []) : [] as $layerName => $rows) {
                $layer = array_find($layers->layers, static fn(MapLayer $layer): bool => $layer->name === $layerName);
                foreach ($rows as $y => $cells) {
                    foreach ($cells as $x => $_) {
                        $terminalFootprint[] = ['x' => $x, 'y' => $y, 'layer' => $layerName, 'symbol' => $layer->grid[$y][$x]];
                    }
                }
            }
            foreach ($terminalPresentations as $presentation) {
                if ($presentation === null) { continue; }
                if (!$presentation->usesOwnedFootprint()) { $terminalClaims[$anchor['y']][$anchor['x']] = true; continue; }
                if ($terminalFootprint === []) {
                    throw new InvalidArgumentException("World object '{$id}' terminal color requires an explicit covers glyph footprint.");
                }
                foreach ($terminalFootprint as $cell) { $terminalClaims[$cell['y']][$cell['x']] = true; }
            }
            foreach ($terminalClaims as $y => $cells) {
                foreach ($cells as $x => $_) {
                    if (isset($terminalOwners[$y][$x])) {
                        throw new InvalidArgumentException("World object '{$id}' overlaps another object's terminal presentation.");
                    }
                    $terminalOwners[$y][$x] = true;
                }
            }
            foreach ($coverage as $kind => $names) {
                foreach ($names as $name => $rows) {
                    foreach ($rows as $y => $cells) {
                        foreach ($cells as $x => $_) {
                            if (isset($claimed[$kind][$name][$y][$x])) {
                                throw new InvalidArgumentException("World object '{$id}' overlaps another object's {$kind} ownership.");
                            }
                            $claimed[$kind][$name][$y][$x] = true;
                        }
                    }
                }
            }
            $objects[$id] = new self($id, $anchor['x'], $anchor['y'], $pivot, $sprites, $parsed,
                $coverageResolved ? $coverage : [], $resource, $label, $terminal, $terminalFootprint);
        }
        return array_values($objects);
    }

    /** First matching authored variant wins; selection never writes world state. */
    public function selectVariant(GameState $state, ?Party $party = null): array
    {
        foreach ($this->variants as $variant) {
            if (WorldConditionEvaluator::allHold($variant['conditions'], $state, $party)) { return $variant; }
        }
        return ['id' => null, 'sprites' => $this->sprites, 'terminal' => $this->terminal];
    }

    private static function requireId(mixed $id): string
    {
        if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/', $id) !== 1) {
            throw new InvalidArgumentException('World object and variant ids must be stable lowercase identifiers of 1..64 characters.');
        }
        return $id;
    }

    private static function readSprites(mixed $data): GraphicalSpriteDefinition|CharacterSheet|FieldPoseAnimation|null
    {
        if ($data === null) { return null; }
        if (!is_array($data)) { throw new InvalidArgumentException('World object sprites2d requires a field role or explicit null.'); }
        return FieldSpriteRole::readDefinition($data);
    }

    /** @return array{array, bool} Declared claims and whether all their ownership is proven. */
    private static function readCoverage(array $entry, MapLayerSet $layers, ?MapGraphics $graphics, ?array $tileOwners): array
    {
        if (!array_key_exists('covers', $entry)) { return [[], true]; }
        $covers = $entry['covers'];
        $gameplayNames = array_column(array_filter($layers->layers, static fn(MapLayer $layer): bool => !$layer->decoration), 'name');
        if (!is_array($covers) || array_diff(array_keys($covers), ['layer', 'cells', 'tileLayers']) !== []
            || !is_string($covers['layer'] ?? null) || !in_array($covers['layer'], $gameplayNames, true)
            || !is_array($covers['cells'] ?? null) || !array_is_list($covers['cells'])
            || $covers['cells'] === [] || count($covers['cells']) > self::MAX_CELLS
            || !is_array($covers['tileLayers'] ?? null) || !array_is_list($covers['tileLayers'])
            || count($covers['tileLayers']) > MapGraphics::MAX_LAYERS) {
            throw new InvalidArgumentException('World object covers requires a gameplay layer, 1..256 cells and an explicit tileLayers list.');
        }
        $result = ['glyphs' => [], 'tiles' => []];
        $cells = [];
        foreach ($covers['cells'] as $cell) {
            if (!is_array($cell) || !array_is_list($cell) || count($cell) !== 2
                || !is_int($cell[0]) || !is_int($cell[1]) || !isset($layers->getComposedGrid()[$cell[1]][$cell[0]])
                || isset($cells[$cell[1]][$cell[0]])) {
                throw new InvalidArgumentException('World object covers cells must be distinct integer [x,y] cells inside the map.');
            }
            $cells[$cell[1]][$cell[0]] = true;
        }
        $result['glyphs'][$covers['layer']] = $cells;
        $owners = $graphics === null ? $tileOwners : $graphics->owners;
        $names = $graphics === null ? ($tileOwners === null ? null : array_keys($tileOwners)) : array_column($graphics->layers, 'name');
        $resolved = true;
        foreach ($covers['tileLayers'] as $name) {
            if (!is_string($name) || isset($result['tiles'][$name])
                || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $name) !== 1
                || ($names !== null && !in_array($name, $names, true))
                || (($graphics !== null || ($owners[$name] ?? null) !== null) && ($owners[$name] ?? null) !== $covers['layer'])) {
                throw new InvalidArgumentException('World object covers tileLayers must name distinct graphical layers owned by its gameplay layer.');
            }
            if ($graphics === null && ($owners[$name] ?? null) === null) { $resolved = false; }
            $result['tiles'][$name] = $cells;
        }
        if (!$resolved) {
            Debug::warn("World object '{$entry['id']}' coverage ownership is unavailable; retaining its map glyphs without applying coverage.");
        }
        return [$result, $resolved];
    }
}
