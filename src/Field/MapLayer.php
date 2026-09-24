<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/** One authored grid. Presentation and collision retain its layer identity. */
final readonly class MapLayer
{
    public const int MIN_ORDER = 0;
    public const int MAX_ORDER = 99;
    /** @var list<list<string>> */
    public array $grid;

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
        $this->grid = self::parseGrid($text);
    }

    /** @return list<list<string>> */
    public static function parseGrid(string $text): array
    {
        return array_map(TerminalText::visibleSymbols(...), preg_split('/\r\n|\n|\r/', rtrim($text, "\r\n")) ?: []);
    }
}
