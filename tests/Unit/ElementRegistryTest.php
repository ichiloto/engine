<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Elements\ElementRegistry;
use Ichiloto\Engine\Entities\Enumerations\ElementType;

it('reads a project\'s element identities without configuring the running game', function () {
    ElementRegistry::configure(['Ember']);

    expect(ElementRegistry::getCanonicalIdentities([' Frost ', 'Gale']))->toBe(['Frost', 'Gale'])
        ->and(ElementRegistry::getCanonicalIdentities())->toBe(array_map(static fn(ElementType $element): string => $element->value, ElementType::cases()))
        ->and(ElementRegistry::identities())->toBe(['Ember'])
        ->and(fn() => ElementRegistry::getCanonicalIdentities(['Frost', 'frost']))->toThrow(InvalidArgumentException::class, 'Duplicate canonical element identity: frost.')
        ->and(fn() => ElementRegistry::getCanonicalIdentities(['']))->toThrow(InvalidArgumentException::class, 'Element identities must be non-empty strings.');

    ElementRegistry::configure();
});
