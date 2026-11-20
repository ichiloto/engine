<?php

declare(strict_types=1);

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicFramingDefinition;

it('binds described subjects without declaring conversation speakers or facing', function (): void {
    $definition = new CinematicFramingDefinition(['subjects' => [
        'recovering-subject' => ['kind' => 'npc', 'id' => 'patient'],
        'falling-prop' => ['kind' => 'staged_actor', 'id' => 'beam'],
    ]]);
    expect($definition->getSubjectReferences(['recovering-subject', 'falling-prop']))->toBe([
        ['kind' => 'npc', 'id' => 'patient'], ['kind' => 'staged_actor', 'id' => 'beam'],
    ]);
});

it('lets a reusable common event select direct subjects without cinematic-local bindings', function (): void {
    expect((new CinematicFramingDefinition())->getSubjectReferences([
        ['kind' => 'player'], ['kind' => 'staged_actor', 'id' => 'prop'],
    ]))->toBe([['kind' => 'player'], ['kind' => 'staged_actor', 'id' => 'prop']]);
});

it('rejects invalid phase requirements before a caller can change its current phase', function (array $requirements): void {
    $definition = new CinematicFramingDefinition(['subjects' => ['subject' => ['kind' => 'npc', 'id' => 'person']]]);
    expect(fn() => $definition->getSubjectReferences($requirements))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown cinematic binding' => [['unknown-key']],
    'duplicate real reference through a key' => [['subject', ['kind' => 'npc', 'id' => 'person']]],
    'missing stable actor identity' => [[['kind' => 'staged_actor']]],
    'renderer geometry in subject data' => [[['kind' => 'npc', 'id' => 'person', 'height' => 48]]],
    'spatial location in place of an actor' => [[['kind' => 'position', 'x' => 1, 'y' => 2]]],
    'speaker changes mixed with framing' => [[['kind' => 'npc', 'id' => 'person', 'facing' => 'North']]],
    'ambiguous player identity' => [[['kind' => 'player', 'id' => 'person']]],
    'empty phase instead of explicit restore' => [[]],
]);


it('exports a shared plain-data authoring contract for nested framing phases', function (): void {
    $schema = \Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema::export();
    expect($schema['framingRequirements']['subjectKinds'])->toBe(CinematicFramingDefinition::SUBJECT_KINDS)
        ->and($schema['framingRequirements']['definitionField'])->toBe('presentation.framing')
        ->and($schema['framingRequirements']['commandFields'])->toBe(['subjects', 'commands'])
        ->and($schema['nestedBlockShapes']['framing'])->toBe(['commands'])
        ->and($schema['commandTypes'])->toContain('framing');
});
