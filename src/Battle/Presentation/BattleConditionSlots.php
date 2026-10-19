<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use WeakMap;

/** Presentation-only slots survive additions/cures without moving another condition. */
final class BattleConditionSlots
{
    private WeakMap $slots;

    public function __construct() { $this->slots = new WeakMap(); }

    public function assign(CharacterInterface $battler, array $entries, ?callable $measure = null): array
    {
        $slots = array_intersect_key($this->slots[$battler] ?? [], array_column($entries, null, 'key'));
        $widths = [];
        foreach ($entries as $entry) {
            $widths[$entry['key']] = max(1, $measure === null ? 1 : (int)$measure($entry));
            if (isset($slots[$entry['key']]) && $slots[$entry['key']][1] !== $widths[$entry['key']]) { unset($slots[$entry['key']]); }
        }
        $used = [];
        foreach ($slots as [$start, $length]) {
            foreach (range($start, $start + $length - 1) as $cell) { $used[$cell] = true; }
        }
        foreach ($entries as &$entry) {
            if (!isset($slots[$entry['key']])) {
                $slot = 0;
                $width = $widths[$entry['key']];
                while (array_any(range($slot, $slot + $width - 1), static fn($cell): bool => isset($used[$cell]))) { $slot++; }
                foreach (range($slot, $slot + $width - 1) as $cell) { $used[$cell] = true; }
                $slots[$entry['key']] = [$slot, $width];
            }
            $entry['slot'] = $slots[$entry['key']][0];
        }
        unset($entry);
        $this->slots[$battler] = $slots;
        return $entries;
    }
}
