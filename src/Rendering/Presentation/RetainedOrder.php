<?php

namespace Ichiloto\Engine\Rendering\Presentation;

/**
 * Sparse wire ranks for retained records whose paint order is relative within one order space.
 *
 * Surviving records keep their previous rank whenever their relative order allows, so inserting,
 * removing or moving one record re-sends that record only, never every record painted after it.
 */
final class RetainedOrder
{
    public const int STRIDE = 1024;
    /** Ranks are unsigned 32-bit on the renderer wire. */
    public const int MAX_RANK = 4294967295;

    /**
     * @param list<string> $keys Records in paint order.
     * @param array<string, int> $previous The ranks these records already carry, by key.
     * @return array<string, int> Strictly increasing ranks in paint order.
     */
    public static function getStableOrders(array $keys, array $previous): array
    {
        $kept = self::getKeptKeys($keys, $previous);
        $orders = [];
        $low = -1;
        $run = [];
        foreach ($keys as $key) {
            if (!isset($kept[$key])) { $run[] = $key; continue; }
            if (!self::fillBetween($orders, $run, $low, $previous[$key])) { return self::getRenumberedOrders($keys); }
            $orders[$key] = $low = $previous[$key];
            $run = [];
        }
        $high = min(self::MAX_RANK + 1, $low + (count($run) + 1) * self::STRIDE);
        return self::fillBetween($orders, $run, $low, $high) ? $orders : self::getRenumberedOrders($keys);
    }

    /**
     * The longest run of previous ranks that is already increasing in the new paint order.
     *
     * @param list<string> $keys
     * @param array<string, int> $previous
     * @return array<string, true>
     */
    private static function getKeptKeys(array $keys, array $previous): array
    {
        $tails = $tailKeys = $parents = [];
        foreach ($keys as $key) {
            $rank = $previous[$key] ?? null;
            if (!is_int($rank) || $rank < 0 || $rank > self::MAX_RANK) { continue; }
            [$low, $high] = [0, count($tails)];
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                if ($tails[$middle] < $rank) { $low = $middle + 1; } else { $high = $middle; }
            }
            $parents[$key] = $low > 0 ? $tailKeys[$low - 1] : null;
            $tails[$low] = $rank;
            $tailKeys[$low] = $key;
        }
        $kept = [];
        for ($key = $tailKeys === [] ? null : $tailKeys[count($tailKeys) - 1]; $key !== null; $key = $parents[$key]) {
            $kept[$key] = true;
        }
        return $kept;
    }

    /**
     * Spread new or moved records evenly between two exclusive bounds.
     *
     * @param array<string, int> $orders
     * @param list<string> $run
     */
    private static function fillBetween(array &$orders, array $run, int $low, int $high): bool
    {
        if ($run === []) { return true; }
        $step = intdiv($high - $low, count($run) + 1);
        if ($step < 1) { return false; }
        foreach ($run as $index => $key) { $orders[$key] = $low + $step * ($index + 1); }
        return true;
    }

    /**
     * @param list<string> $keys
     * @return array<string, int>
     */
    private static function getRenumberedOrders(array $keys): array
    {
        $stride = max(1, min(self::STRIDE, intdiv(self::MAX_RANK, count($keys) + 1)));
        $orders = [];
        foreach ($keys as $index => $key) { $orders[$key] = ($index + 1) * $stride; }
        return $orders;
    }
}
