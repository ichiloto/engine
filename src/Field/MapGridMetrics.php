<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;
use Ichiloto\Engine\IO\Console\TerminalText;

/** @internal Map-owned derivations; at most the two terminal width policies. */
final class MapGridMetrics
{
    /** @var list<list<string>> */
    public readonly array $glyphs;
    /** @var array<int, list<list<int>>> */
    private array $widths = [];

    /** @param list<list<string>> $grid */
    public function __construct(array $grid)
    {
        $glyphs = $stripped = [];
        foreach ($grid as $y => $row) {
            $glyphs[$y] = [];
            foreach ($row as $x => $cell) {
                $glyphs[$y][$x] = $stripped[$cell] ??= TerminalText::stripAnsi($cell);
            }
        }
        $this->glyphs = $glyphs;
        $this->getWidths();
    }

    /** @return list<list<int>> Width of each logical cell, not padded display rows. */
    public function getWidths(): array
    {
        $policy = (int)TerminalCapabilities::supportsCompositeEmoji();
        if (isset($this->widths[$policy])) { return $this->widths[$policy]; }
        $started = LatencyTrace::getTimeNow();
        $widths = $measured = [];
        foreach ($this->glyphs as $y => $row) {
            $widths[$y] = [];
            foreach ($row as $x => $glyph) {
                $widths[$y][$x] = $measured[$glyph] ??= NormalizedRow::symbolWidth($glyph);
            }
        }
        LatencyTrace::end('map.measure', $started);
        return $this->widths[$policy] = $widths;
    }
}
