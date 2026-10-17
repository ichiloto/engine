<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use InvalidArgumentException;

/** Installed static map source. Moving markers never rebuild its bounded rasters. */
final class MapOverview
{
    public readonly int $width;
    public readonly int $height;
    private array $terminalRasters = [];

    public function __construct(private readonly array $tiles, private readonly ?array $collisions = null)
    {
        $this->width = $tiles === [] ? 0 : max(array_map('count', $tiles));
        $this->height = count($tiles);
        if ($this->width < 1 || $this->height < 1) { throw new InvalidArgumentException('An overview requires a nonempty map.'); }
    }

    /** Schematic physical ground, without copying decorative letters or miniature field artwork.
     *  Read-only sources without collision facts show only their extent. Runtime
     *  sources use the already resolved physical occupancy, never glyph spelling.
     *  Cache owns at most two screen-sized variants and releases with its map installation.
     *  @return list<list<string>> */
    public function getTerminalRaster(int $width, int $height, ?MapProjection $projection = null): array
    {
        if ($width < 1 || $height < 1 || $width > 512 || $height > 256) {
            throw new InvalidArgumentException('Terminal overview dimensions must be within 512 by 256 cells.');
        }
        $projection ??= $this->getTerminalProjection($width, $height);
        $key = "{$width}:{$height}:" . serialize($projection);
        if (isset($this->terminalRasters[$key])) { return $this->terminalRasters[$key]; }
        $raster = array_fill(0, $height, array_fill(0, $width, ' '));
        // Visit only the bounded target raster; a large source does not require
        // iterating or copying every cell when the player opens the atlas.
        for ($y = (int) floor($projection->top); $y < min($height, (int) ceil($projection->top + $projection->height)); $y++) {
            $sourceY = max(0, min($this->height - 1, (int) floor($projection->sourceY+($y + 0.5 - $projection->top) / $projection->scaleY)));
            for ($x = (int) floor($projection->left); $x < min($width, (int) ceil($projection->left + $projection->width)); $x++) {
                $sourceX = max(0, min($this->width - 1, (int) floor($projection->sourceX+($x + 0.5 - $projection->left) / $projection->scaleX)));
                if (!isset($this->tiles[$sourceY][$sourceX])) { continue; }
                $raster[$y][$x] = $this->getBoundarySymbol($sourceX,$sourceY);
            }
        }
        if (count($this->terminalRasters) === 2) { array_shift($this->terminalRasters); }
        return $this->terminalRasters[$key] = $raster;
    }

    /** Occupancy supplies boundaries. Decorative glyphs never define the atlas. */
    private function getBoundarySymbol(int $x, int $y): string
    {
        if ($this->collisions===null || !$this->isSolid($x,$y)) { return ' '; }
        $horizontal = !$this->isSolid($x,$y-1) || !$this->isSolid($x,$y+1);
        $vertical = !$this->isSolid($x-1,$y) || !$this->isSolid($x+1,$y);
        $corner=!$this->isSolid($x-1,$y-1) || !$this->isSolid($x+1,$y-1)
            || !$this->isSolid($x-1,$y+1) || !$this->isSolid($x+1,$y+1);
        return $horizontal && $vertical ? '+' : ($horizontal ? '-' : ($vertical ? '|' : ($corner ? '+' : ' ')));
    }

    private function isSolid(int $x, int $y): bool
    {
        if (!isset($this->tiles[$y][$x])) { return true; }
        $value=$this->collisions[$y][$x] ?? null;
        return $value===null || (CollisionType::tryFrom($value)?->blocksMovement() ?? true);
    }

    public function getTerminalProjection(int $width, int $height): MapProjection
    {
        return MapProjection::fit($this->width, $this->height, new Rect(0, 0, $width, $height), 1, 2);
    }
}
