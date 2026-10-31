<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Progression\WorldAbilityCatalog;

/**
 * Proves that two lists of world conditions can never hold together.
 *
 * The Engine shows a guarded thing only while every condition in its list
 * holds (`WorldConditionEvaluator::allHold()`). Two lists therefore exclude
 * each other when one list has a condition and the other has its complement:
 * the same test expecting the opposite result, such as `event:x` and
 * `!event:x`, or `switch:x` and `switch:x:false`. Variable `==` and `!=`
 * against the same value are complements too, since `!=` is the negation of
 * `==`. Nothing else is inferred: two quest statuses, variable ranges or item
 * counts may look disjoint and still both hold, so they prove nothing.
 *
 * Each test is read as the Engine evaluates it: the trimmed name, the
 * parameters its type consults with the Engine's defaults, and `negate`. A
 * condition the Engine skips (an empty name) or refuses (an unknown type, a
 * map event without a marker) proves nothing.
 */
final class WorldConditionExclusion
{
    private function __construct()
    {
    }

    /**
     * Determines whether two condition lists can never both hold.
     *
     * @param array<int|string, mixed> $conditions One list.
     * @param array<int|string, mixed> $others The other list.
     * @return bool True when one list holds a complement of a condition in the other.
     */
    public static function proveExclusive(array $conditions, array $others, ?WorldAbilityCatalog $worldAbilities = null): bool
    {
        $conditions = self::expandAbilityConditions($conditions, $worldAbilities);
        $others = self::expandAbilityConditions($others, $worldAbilities);
        $expected = [];

        foreach ($conditions as $condition) {
            $test = self::readTest($condition);

            if ($test !== null) {
                $expected[$test[0]][$test[1] ? 1 : 0] = true;
            }
        }

        foreach ($others as $condition) {
            $test = self::readTest($condition);

            if ($test !== null && isset($expected[$test[0]][$test[1] ? 0 : 1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * An available ability implies all its source predicates. Its negation
     * implies the inverse predicate only when its source has exactly one.
     * Multi-predicate negation remains a disjunction and is never flattened.
     * The catalog has already rejected dependency cycles.
     */
    private static function expandAbilityConditions(array $conditions, ?WorldAbilityCatalog $catalog, array &$visited = []): array
    {
        $expanded = [];
        foreach ($conditions as $condition) {
            $expanded[] = $condition;
            if (!is_array($condition) || !is_string($condition['type'] ?? null)
                || trim($condition['type']) !== WorldConditionType::WORLD_ABILITY->value
                || !is_string($condition['name'] ?? null) || trim($condition['name']) === '') { continue; }
            $catalog ??= WorldAbilityCatalog::getProjectCatalog();
            $id = trim($condition['name']);
            $key = (($condition['negate'] ?? false) ? 'negative:' : 'positive:') . $id;
            if (isset($visited[$key])) { continue; }
            $visited[$key] = true;
            $source = $catalog->getAbility($id)->conditions;
            if ($condition['negate'] ?? false) {
                if (count($source) !== 1) { continue; }
                $source[0]['negate'] = !($source[0]['negate'] ?? false);
            }
            array_push($expanded, ...self::expandAbilityConditions($source, $catalog, $visited));
        }
        return $expanded;
    }

    /**
     * Reads a condition as the test the Engine runs and the result it
     * requires, or null when it proves nothing.
     *
     * @return array{0: string, 1: bool}|null The test's key and the result the condition holds on.
     */
    private static function readTest(mixed $condition): ?array
    {
        if (! is_array($condition) || ! is_scalar($condition['type'] ?? null) || ! is_scalar($condition['name'] ?? null)) {
            return null;
        }

        $type = WorldConditionType::tryFrom(trim(strval($condition['type'])));
        $name = trim(strval($condition['name']));

        if (! $type instanceof WorldConditionType || $name === '') {
            return null;
        }

        $holdsOn = ! (bool) ($condition['negate'] ?? false);
        $parameters = match ($type) {
            WorldConditionType::SWITCH, WorldConditionType::EVENT, WorldConditionType::KEY_ITEM, WorldConditionType::WORLD_ABILITY => [],
            WorldConditionType::MAP_EVENT => is_string($condition['marker'] ?? null) && trim($condition['marker']) !== ''
                ? [trim($condition['marker'])]
                : null,
            WorldConditionType::ITEM => is_scalar($condition['quantity'] ?? 1) ? [max(1, intval($condition['quantity'] ?? 1))] : null,
            WorldConditionType::QUEST => is_scalar($condition['status'] ?? 'completed') ? [strval($condition['status'] ?? 'completed')] : null,
            WorldConditionType::VARIABLE => self::readVariableParameters($condition, $holdsOn),
        };

        if ($parameters === null) {
            return null;
        }

        if ($type === WorldConditionType::SWITCH) {
            // A switch test compares the stored value with the expected one,
            // so `switch:x:false` holds exactly when `switch:x` does not.
            $holdsOn = $holdsOn === (bool) ($condition['value'] ?? true);
        }

        return [json_encode([$type->value, $name, ...$parameters], JSON_THROW_ON_ERROR), $holdsOn];
    }

    /**
     * Reads a variable comparison as an `==` test, folding `!=` into the
     * result it requires, or null for an ordering it cannot complement.
     *
     * @param array<string, mixed> $condition The condition.
     * @param bool $holdsOn The result it requires, updated for `!=`.
     * @return array<int, mixed>|null The test's parameters.
     */
    private static function readVariableParameters(array $condition, bool &$holdsOn): ?array
    {
        $operator = $condition['op'] ?? '==';
        $value = $condition['value'] ?? 0;

        if (! is_scalar($value) || ! in_array($operator, ['==', '!='], true)) {
            return null;
        }

        if ($operator === '!=') {
            $holdsOn = ! $holdsOn;
        }

        return ['==', $value];
    }
}
