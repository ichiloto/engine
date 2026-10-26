<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Localization\Vocabulary;

/** A multi-value Equipment entry projected from Party assignments; it never becomes an inventory item. */
final readonly class SummonEquipmentSlot
{
    /** @param list<SummonCutsceneDefinition> $definitions */
    private function __construct(private Character $character, private array $definitions) {}

    /** @param list<SummonCutsceneDefinition> $definitions */
    public static function createForCharacter(Party $party, Character $character, GameState $state, array $definitions): ?self
    {
        if (!$party->members->contains($character)) { return null; }
        $choices = array_values(array_filter($definitions, static fn(SummonCutsceneDefinition $definition): bool =>
            $definition->wielders !== null && ($definition->availability === null || $definition->isAvailable($state, $party))
            && ($character->hasSummon($definition->id) || $party->canAssignSummon($definition, $character, $state))));
        return $choices === [] ? null : new self($character, $choices);
    }

    /** Current Party ownership supplies every value; no single-item capacity or new license gate is imposed. */
    public function getAssignedNames(): array
    {
        return array_values(array_map(static fn(SummonCutsceneDefinition $definition): string => $definition->name,
            array_filter($this->definitions, fn(SummonCutsceneDefinition $definition): bool => $this->character->hasSummon($definition->id))));
    }

    public function getInfoText(): string
    {
        return implode("\n", $this->getAssignedNames());
    }

    public function getLabel(): string { return Vocabulary::getTerm('command.summon', 'Summons'); }

    public function getRow(bool $selected, bool $focused): MenuRow
    {
        return new MenuRow('summon-slot', $this->getLabel(), [new MenuRowValue(implode(', ', $this->getAssignedNames()))],
            selected: $selected, focused: $focused);
    }
}
