<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Exceptions\NotFoundException;
use InvalidArgumentException;

/** Discovers authored grids without evaluating any grid PHP. */
final class MapLayerSource
{
    public const string DIRECTORY = 'layers';
    public const string FILENAME_PATTERN = '/\A(?<order>[0-9]{2})\.(?<name>[A-Za-z][A-Za-z0-9_-]*)\.(?<kind>map|deco)\.php\z/';

    public static function loadFromDirectory(string $directory, ?string $displayDirectory = null, ?string $legacyPath = null): MapLayerSet
    {
        $displayDirectory ??= $directory;
        $layerDirectory = $directory . DIRECTORY_SEPARATOR . self::DIRECTORY;
        if (!is_dir($layerDirectory)) {
            $path = $legacyPath ?? $directory . DIRECTORY_SEPARATOR . basename($directory) . '.map.php';
            $displayPath = $displayDirectory . '/' . basename($path);
            if (!is_file($path)) {
                throw new NotFoundException("File {$displayPath} not found.");
            }
            return new MapLayerSet([new MapLayer('terrain', 0, false, $displayPath, MapGridSource::readFile($path, $displayPath))], legacy: true);
        }

        $files = glob($layerDirectory . '/*.{map,deco}.php', GLOB_BRACE);
        if ($files === false || $files === []) {
            throw new InvalidArgumentException("Map {$displayDirectory}/layers requires at least one gameplay layer.");
        }
        $layers = [];
        foreach ($files as $path) {
            $filename = basename($path);
            $displayPath = $displayDirectory . '/layers/' . $filename;
            if (preg_match(self::FILENAME_PATTERN, $filename, $matches) !== 1) {
                throw new InvalidArgumentException("Layer {$displayPath} must be named NN.name.map.php or NN.name.deco.php.");
            }
            $layers[] = new MapLayer($matches['name'], (int)$matches['order'], $matches['kind'] === 'deco',
                $displayPath, MapGridSource::readFile($path, $displayPath));
        }
        return new MapLayerSet($layers);
    }
}
