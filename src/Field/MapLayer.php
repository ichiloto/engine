<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Generator;
use Ichiloto\Engine\Core\PackedGrid;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * One authored grid. Presentation and collision retain its layer identity.
 *
 * Its cells are held compactly: each distinct styled cell once, with its
 * stripped glyph, and each row as packed palette indices ({@see PackedGrid}),
 * so a world map costs bytes per cell. Read cells with {@see getCellAt()} and
 * {@see getGlyphAt()} or a row at a time; {@see readGrid()} and
 * {@see readGlyphs()} build whole grids for callers that need them.
 */
final readonly class MapLayer
{
    public const int MIN_ORDER = 0;
    public const int MAX_ORDER = 99;
    /** Number of rows. */
    public int $height;
    /** The longest row's cell count; every row of a layer in a matched set has it. */
    public int $width;
    /** @var list<string> Each distinct styled cell, by palette index. */
    private array $cells;
    /** @var list<string> Each palette cell's stripped glyph; styles remain in the cells. */
    private array $glyphPalette;
    private PackedGrid $indices;

    public function __construct(
        public string $name,
        public int $order,
        public bool $decoration,
        public string $path,
        public string $text,
    ) {
        if ($order < self::MIN_ORDER || $order > self::MAX_ORDER
            || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $name) !== 1) {
            throw new InvalidArgumentException("Layer {$path} requires a two-digit order and an alphanumeric, underscore or hyphen name beginning with a letter.");
        }
        $palette = [];
        $this->indices = PackedGrid::fromRows(self::readIndexRows($text, $palette));
        // Numeric glyphs such as "8" are integer palette keys.
        $this->cells = array_map(strval(...), array_keys($palette));
        $this->glyphPalette = array_map(TerminalText::stripAnsi(...), $this->cells);
        $this->height = $this->indices->height;
        $this->width = $this->indices->width;
    }

    /** @return list<list<string>> */
    public static function parseGrid(string $text): array
    {
        return array_map(TerminalText::visibleSymbols(...), self::splitRows($text));
    }

    /** The styled cell at a position, or null outside the layer. */
    public function getCellAt(int $x, int $y): ?string
    {
        $index = $this->indices->getValueAt($x, $y);
        return $index === null ? null : $this->cells[$index];
    }

    /** The stripped glyph at a position, or null outside the layer. */
    public function getGlyphAt(int $x, int $y): ?string
    {
        $index = $this->indices->getValueAt($x, $y);
        return $index === null ? null : $this->glyphPalette[$index];
    }

    /** The number of cells in a row; 0 outside the layer. */
    public function getRowLength(int $y): int
    {
        return $this->indices->getRowLength($y);
    }

    /** @return list<string> A row's styled cells; empty outside the layer. */
    public function getRowCells(int $y): array
    {
        return array_map(fn(int $index): string => $this->cells[$index], $this->indices->getRowValues($y));
    }

    /** @return list<string> A row's stripped glyphs; empty outside the layer. */
    public function getRowGlyphs(int $y): array
    {
        return array_map(fn(int $index): string => $this->glyphPalette[$index], $this->indices->getRowValues($y));
    }

    /** @return list<list<string>> Every row's styled cells, built for the caller. */
    public function readGrid(): array
    {
        return $this->height === 0 ? [] : array_map($this->getRowCells(...), range(0, $this->height - 1));
    }

    /** @return list<list<string>> Every row's stripped glyphs, built for the caller. */
    public function readGlyphs(): array
    {
        return $this->height === 0 ? [] : array_map($this->getRowGlyphs(...), range(0, $this->height - 1));
    }

    /**
     * @param array<string, int> $palette Distinct cells found so far, by first appearance.
     * @return Generator<int, list<int>>
     */
    private static function readIndexRows(string $text, array &$palette): Generator
    {
        foreach (self::splitRows($text) as $line) {
            $row = [];
            foreach (TerminalText::visibleSymbols($line) as $cell) {
                $row[] = $palette[$cell] ??= count($palette);
            }
            yield $row;
        }
    }

    /** @return list<string> */
    private static function splitRows(string $text): array
    {
        return preg_split('/\r\n|\n|\r/', rtrim($text, "\r\n")) ?: [];
    }
}
