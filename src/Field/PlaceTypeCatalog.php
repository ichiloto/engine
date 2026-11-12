<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Localization\LocalizedText;
use InvalidArgumentException;

/**
 * What kind of place a map is the way into: a city, a town, a village, a castle, an outpost, an inn, a shrine, a
 * cave, a wild area, or whatever types a game names. A map names its place's type in its data's `placeType`; the
 * project's types live in `Data/place-types.php`, each with a localized name. A world map shows each place by the
 * icon its tileset gives the type ({@see \Ichiloto\Engine\Rendering\Tilesets\Tileset::$placeIcons}), so a larger
 * settlement and a single building read differently at a glance, and validation holds every entrance's icon to its
 * destination's type ({@see Validation\MapPlaceIconRule}).
 *
 * A place type is the scale and nature of the whole place. It differs from a map's role ({@see MapRoleCatalog}),
 * which is what one map is to the world's travel (an overworld, an outside map, an interior), and from a landmark's
 * category ({@see MapCategoryCatalog}), which is what a building offers inside a place (an inn, a shop). The Engine
 * defines no type and enforces none: a game's place types are authored data its Editor validates.
 */
final readonly class PlaceTypeCatalog
{
    public const string FILE = 'Data/place-types.php';
    public const string SCHEMA = 'ichiloto.place-types/1';
    /** The map data key naming the type of the place the map belongs to. */
    public const string MAP_KEY = 'placeType';
    public const int MAX_TYPES = 64;

    /** @var array<string, array{name: array}> Types by stable id, in authored order. */
    public array $types;

    public function __construct(array $types = [])
    {
        if (count($types) > self::MAX_TYPES) { throw new InvalidArgumentException('Place types exceed 64 records.'); }
        $copy = [];
        foreach ($types as $id => $entry) {
            if (!is_string($id) || preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1 || !is_array($entry)
                || array_diff(array_keys($entry), ['name']) !== [] || !array_key_exists('name', $entry)) {
                throw new InvalidArgumentException('A place type needs a stable lowercase id and a localized name.');
            }
            LocalizedText::fromArray($entry['name']);
            $copy[$id] = $entry;
        }
        $this->types = $copy;
    }

    /** The project's place types, or none when it declares none. */
    public static function load(string $assetRoot): self
    {
        $file = $assetRoot . '/' . self::FILE;
        if (!file_exists($file) && !is_link($file)) { return new self(); }
        $root = realpath($assetRoot); $path = realpath($file);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new InvalidArgumentException('Place type catalog must be a readable file inside assets.');
        }
        return self::fromArray((static fn(string $path): mixed => require $path)($path));
    }

    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA
            || array_diff(array_keys($data), ['schema', 'types']) !== [] || !is_array($data['types'] ?? null)) {
            throw new InvalidArgumentException('Place types require schema ichiloto.place-types/1 and a types dictionary.');
        }
        return new self($data['types']);
    }

    public function toArray(): array { return ['schema' => self::SCHEMA, 'types' => $this->types]; }

    public static function getNameMessageKey(string $id): string
    {
        if (preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1) { throw new InvalidArgumentException('Invalid place type id.'); }
        return 'place_types.' . $id . '.name';
    }

    /** @return list<string> */
    public function getIds(): array { return array_keys($this->types); }

    public function hasType(string $id): bool { return isset($this->types[$id]); }

    public function getName(string $id): string
    {
        $entry = $this->types[$id] ?? throw new InvalidArgumentException(sprintf('Unknown place type \'%s\'.', $id));
        return LocalizedText::fromArray($entry['name'])->getText();
    }

    /**
     * The place type a map's data names, or null when it names none.
     *
     * @param array<string, mixed> $mapData
     * @throws InvalidArgumentException When the type is not a stable id.
     */
    public static function readMapPlaceType(array $mapData): ?string
    {
        if (!array_key_exists(self::MAP_KEY, $mapData)) { return null; }
        $type = $mapData[self::MAP_KEY];
        if (!is_string($type) || preg_match(MapLandmarkCatalog::ID_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException('A place type is a stable lowercase id.');
        }
        return $type;
    }
}
