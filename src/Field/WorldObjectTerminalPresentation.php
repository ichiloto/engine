<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/** A replacement anchor cell, or a foreground treatment over the object's owned glyph footprint. */
final readonly class WorldObjectTerminalPresentation
{
    private function __construct(private ?string $symbol, private ?string $foregroundPrefix = null) {}

    public static function read(mixed $data): ?self
    {
        if ($data === null) { return null; }
        if (is_array($data)) {
            if (array_keys($data) !== ['color'] || !is_string($data['color']) || trim($data['color']) === '') {
                throw new InvalidArgumentException('World object terminal styling requires a color.');
            }
            $sample = (new OutputFormatterStyle($data['color']))->apply(' ');
            return new self(null, substr($sample, 0, strpos($sample, ' ')));
        }
        if (!is_string($data) || TerminalText::symbolCount($data) !== 1 || TerminalText::displayWidth($data) !== 1) {
            throw new InvalidArgumentException('World object terminal presentation requires one styled cell, an owned-footprint color, or null.');
        }
        return new self(TerminalText::visibleSymbols($data)[0]);
    }

    public function usesOwnedFootprint(): bool { return $this->symbol === null; }

    /** Preserve the authored glyph, background and attributes while replacing only foreground. */
    public function getSymbol(string $source): string
    {
        if ($this->symbol !== null) { return $this->symbol; }
        return preg_replace_callback('/\A(?:\x1B\[[0-9;]*m)*/',
            fn(array $prefix): string => $prefix[0] . $this->foregroundPrefix, $source, 1) . "\e[39m";
    }
}
