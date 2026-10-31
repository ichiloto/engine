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
            return self::compileSources([$path => ['terrain', 0, false, $displayPath]], true);
        }

        $files = glob($layerDirectory . '/*.{map,deco}.php', GLOB_BRACE);
        if ($files === false || $files === []) {
            throw new InvalidArgumentException("Map {$displayDirectory}/layers requires at least one gameplay layer.");
        }
        $sources = [];
        foreach ($files as $path) {
            $filename = basename($path);
            $displayPath = $displayDirectory . '/layers/' . $filename;
            if (preg_match(self::FILENAME_PATTERN, $filename, $matches) !== 1) {
                throw new InvalidArgumentException("Layer {$displayPath} must be named NN.name.map.php or NN.name.deco.php.");
            }
            $sources[$path] = [$matches['name'], (int)$matches['order'], $matches['kind'] === 'deco', $displayPath];
        }
        return self::compileSources($sources, false);
    }
    /** Read exact current bytes even when timestamps and lengths were preserved by an author. */
    private static function compileSources(array $definitions, bool $legacy): MapLayerSet
    {
        $sources = [];
        foreach ($definitions as $path => $definition) {
            $source = @file_get_contents($path);
            if ($source === false) { throw new InvalidArgumentException("Grid source {$definition[3]} could not be read."); }
            $sources[$path] = $source;
        }
        $key = 'layers:' . hash('sha256', serialize([$definitions, $sources, $legacy]));
        if (($cached = MapCompilationCache::find($key)) instanceof MapLayerSet) { return $cached; }
        $layers = [];
        $charge = array_sum(array_map(strlen(...), $sources));
        foreach ($definitions as $path => [$name, $order, $decoration, $displayPath]) {
            $layer = new MapLayer($name, $order, $decoration, $displayPath, MapGridSource::parseSource($sources[$path], $displayPath));
            $layers[] = $layer;
            // Conservative charge for source, cell strings, packed indices and the composed grid.
            $charge += $layer->width * $layer->height * 64;
        }
        $result = new MapLayerSet($layers, $legacy);
        MapCompilationCache::store($key, $result, $charge);
        return $result;
    }

}
