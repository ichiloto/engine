<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use InvalidArgumentException;

/**
 * What a project authors for a whole region: the place every map naming that region in its data's `region` belongs
 * to. Regions live in `Data/regions.php`, keyed by the region name exactly as maps write it:
 *
 * ```php
 * return ['schema' => 'ichiloto.regions/1', 'regions' => [
 *     'Garden of Roads' => ['skitBackdrop' => 'Graphics/Skits/GardenOfRoads/Road.png'],
 * ]];
 * ```
 *
 * A region's `skitBackdrop` is the scene a skit shows on any of its maps that names no backdrop of its own
 * ({@see SkitBackdrop}). Every key is optional and the file itself is optional: a region without a record is still a
 * region. The Engine defines no region and requires none.
 */
final readonly class RegionCatalog
{
    public const string FILE = 'Data/regions.php';
    public const string SCHEMA = 'ichiloto.regions/1';
    public const int MAX_REGIONS = 256;
    public const int MAX_NAME_LENGTH = 128;
    /** The keys a region record may hold. */
    public const array KEYS = [SkitBackdrop::MAP_KEY];

    /** @var array<string, array{skitBackdrop?: string}> Region records by region name, in authored order. */
    public array $regions;

    public function __construct(array $regions = [])
    {
        if (count($regions) > self::MAX_REGIONS) { throw new InvalidArgumentException('Regions exceed 256 records.'); }
        $copy = [];
        foreach ($regions as $name => $entry) {
            $name = is_int($name) ? (string) $name : $name;
            if (!self::isRegionName($name) || !is_array($entry) || array_diff(array_keys($entry), self::KEYS) !== []) {
                throw new InvalidArgumentException(sprintf('A region record is keyed by the region name its maps write '
                    . '(1 to %d characters, no surrounding spaces) and may hold only: %s.', self::MAX_NAME_LENGTH, implode(', ', self::KEYS)));
            }
            if (array_key_exists(SkitBackdrop::MAP_KEY, $entry)) {
                $entry[SkitBackdrop::MAP_KEY] = SkitBackdrop::validateAsset($entry[SkitBackdrop::MAP_KEY],
                    sprintf('Region %s\'s skit backdrop', $name));
            }
            $copy[$name] = $entry;
        }
        $this->regions = $copy;
    }

    /** The project's regions, or none when it declares none. */
    public static function load(string $assetRoot): self
    {
        $file = $assetRoot . '/' . self::FILE;
        if (!file_exists($file) && !is_link($file)) { return new self(); }
        $root = realpath($assetRoot); $path = realpath($file);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new InvalidArgumentException('Region catalog must be a readable file inside assets.');
        }
        return self::fromArray((static fn(string $path): mixed => require $path)($path));
    }

    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA
            || array_diff(array_keys($data), ['schema', 'regions']) !== [] || !is_array($data['regions'] ?? null)) {
            throw new InvalidArgumentException('Regions require schema ichiloto.regions/1 and a regions dictionary.');
        }
        return new self($data['regions']);
    }

    public function toArray(): array { return ['schema' => self::SCHEMA, 'regions' => $this->regions]; }

    /** Whether a value can name a region: what a map's `region` holds, read exactly. */
    public static function isRegionName(mixed $name): bool
    {
        return is_string($name) && $name !== '' && $name === trim($name) && mb_strlen($name) <= self::MAX_NAME_LENGTH
            && preg_match('//u', $name) === 1 && !str_contains($name, "\0");
    }

    /** @return list<string> */
    public function getNames(): array { return array_map(strval(...), array_keys($this->regions)); }

    public function hasRegion(string $name): bool { return isset($this->regions[$name]); }

    /** The backdrop a region's skits show where their map names none, or null when the region names none. */
    public function getSkitBackdrop(string $name): ?string
    {
        return $this->regions[$name][SkitBackdrop::MAP_KEY] ?? null;
    }
}
