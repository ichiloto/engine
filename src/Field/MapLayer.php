<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\TerminalText;

/** One authored grid. Presentation and collision retain its layer identity. */
final readonly class MapLayer
{
    /** @var list<list<string>> */
    public array $grid;

    public function __construct(
        public string $name,
        public int $order,
        public bool $decoration,
        public string $path,
        public string $text,
    ) {
        $this->grid = self::parseGrid($text);
    }

    /** @return list<list<string>> */
    public static function parseGrid(string $text): array
    {
        return array_map(TerminalText::visibleSymbols(...), preg_split('/\r\n|\n|\r/', rtrim($text, "\r\n")) ?: []);
    }
}
