<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/** One authored grid of two-column cells. Presentation and collision retain its layer identity. */
final readonly class MapLayer
{
    public const int MIN_ORDER = 0;
    public const int MAX_ORDER = 99;
    /** @var list<list<string>> Styled cells, as authored. */
    public array $grid;
    /** @var list<list<string>> The same cells without styles. */
    public array $glyphs;

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
        $this->grid = self::parseGrid($text, $path);
        $stripped = [];
        $this->glyphs = array_map(static fn(array $row): array => array_map(
            static function (string $cell) use (&$stripped): string {
                return $stripped[$cell] ??= TerminalText::stripAnsi($cell);
            }, $row), $this->grid);
    }

    /** @return list<list<string>> Rows of two-column cells. */
    public static function parseGrid(string $text, string $path = 'Map layer'): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', rtrim($text, "\r\n")) ?: [] as $y => $line) {
            $rows[] = MapCell::parseRow($line, "{$path} row {$y}");
        }
        return $rows;
    }
}
