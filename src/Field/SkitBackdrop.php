<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Field\Enumerations\SkitBackdropSource;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/**
 * The scene a skit's graphical stage shows behind its cast. The backdrop follows the place the skit plays in:
 *
 * 1. the skit's own `backdrop`, only for a skit whose content needs one particular setting;
 * 2. else the current map's `skitBackdrop`;
 * 3. else the `skitBackdrop` of the map's region ({@see RegionCatalog});
 * 4. else none, which is reported with the skit and the map, and the stage shows the theme's backing.
 *
 * Each value is an asset-root-relative PNG path. The first record that names a backdrop owns it: a value that is
 * not a usable path is reported as that record's and never passes the choice on to a later record, so a broken
 * binding is never hidden behind another place's art. The terminal presentation shows no backdrop and is unchanged.
 */
final readonly class SkitBackdrop
{
    /** The skit record key naming the backdrop its content requires. */
    public const string SKIT_KEY = 'backdrop';
    /** The map data (and region record) key naming the backdrop skits show there. */
    public const string MAP_KEY = 'skitBackdrop';

    /**
     * @param string|null $asset The asset-root-relative PNG, or null when there is none to show.
     * @param SkitBackdropSource $source The record that decided it.
     * @param string|null $issue Why there is none to show, when there is none.
     */
    private function __construct(public ?string $asset, public SkitBackdropSource $source, public ?string $issue = null) {}

    /**
     * Chooses the backdrop for one skit on one map.
     *
     * @param array<string, mixed> $skit The skit's authored record.
     * @param array<string, mixed> $mapData The current map's data: its `skitBackdrop` and its `region`.
     * @param RegionCatalog $regions The project's regions.
     */
    public static function resolve(array $skit, array $mapData, RegionCatalog $regions): self
    {
        foreach ([[SkitBackdropSource::SKIT, $skit, self::SKIT_KEY], [SkitBackdropSource::MAP, $mapData, self::MAP_KEY]]
            as [$source, $record, $key]) {
            if (($record[$key] ?? null) === null) { continue; }
            try {
                return new self(self::validateAsset($record[$key], sprintf('The %s\'s backdrop', $source->value)), $source);
            } catch (InvalidArgumentException $error) {
                return new self(null, $source, $error->getMessage());
            }
        }
        $region = $mapData['region'] ?? null;
        $asset = RegionCatalog::isRegionName($region) ? $regions->getSkitBackdrop($region) : null;
        if ($asset !== null) { return new self($asset, SkitBackdropSource::REGION); }

        return new self(null, SkitBackdropSource::NONE, RegionCatalog::isRegionName($region)
            ? sprintf('neither the skit, the map nor region %s names a backdrop', $region)
            : 'neither the skit nor the map names a backdrop, and the map names no region');
    }

    /**
     * The map's own backdrop binding as {@see resolve()} reads it: the keys of its data that take part.
     *
     * @param array<string, mixed> $mapData
     * @return array<string, mixed>
     */
    public static function readMapBinding(array $mapData): array
    {
        return array_intersect_key($mapData, [self::MAP_KEY => true, 'region' => true]);
    }

    /**
     * Returns a backdrop value that is an asset-root-relative path.
     *
     * @throws InvalidArgumentException With the owner named, when it is not.
     */
    public static function validateAsset(mixed $asset, string $owner): string
    {
        if (!is_string($asset)) {
            throw new InvalidArgumentException(sprintf('%s must be an asset-root-relative PNG path.', $owner));
        }
        try {
            SpriteValidation::validateAssetPath($asset);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('%s %s must be a relative path under assets, with forward slashes and no parent folders.',
                $owner, $asset === '' ? '(empty)' : $asset));
        }
        return $asset;
    }

    /** What to report for a skit that has no backdrop to show on a map, or null when it has one. */
    public function describeAbsence(string $skitId, string $mapId): ?string
    {
        if ($this->asset !== null) { return null; }
        return sprintf('Skit %s on map %s shows no backdrop: %s. Choose one on the map\'s Skit Backdrop row, its '
            . 'region\'s record or the skit; the stage shows the theme backing.', $skitId, $mapId === '' ? '(none)' : $mapId,
            $this->issue ?? 'none is named');
    }
}
