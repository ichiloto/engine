<?php

use Ichiloto\Engine\Field\MapRoleCatalog;

/** Synthetic roles: a region that leads only to open places, and two unrestricted ones. */
function syntheticMapRoles(): array
{
    return ['schema' => MapRoleCatalog::SCHEMA, 'roles' => [
        'region' => ['name' => ['message' => 'map_roles.region.name', 'default' => 'Region'], 'leadsTo' => ['region', 'yard']],
        'yard' => ['name' => ['message' => 'map_roles.yard.name', 'default' => 'Yard']],
        'room' => ['name' => ['message' => 'map_roles.room.name', 'default' => 'Room']],
    ]];
}

it('reads authored roles and the destinations each allows, leaving an unrestricted role open', function () {
    $roles = MapRoleCatalog::fromArray(syntheticMapRoles());

    expect($roles->getIds())->toBe(['region', 'yard', 'room'])
        ->and($roles->getName('yard'))->toBe('Yard')
        ->and($roles->getDestinationRoles('region'))->toBe(['region', 'yard'])
        ->and($roles->getDestinationRoles('room'))->toBeNull()
        ->and($roles->allowsDestination('region', 'yard'))->toBeTrue()
        ->and($roles->allowsDestination('region', 'room'))->toBeFalse()
        ->and($roles->allowsDestination('room', 'region'))->toBeTrue()
        ->and($roles->toArray())->toBe(syntheticMapRoles())
        ->and(MapRoleCatalog::getNameMessageKey('yard'))->toBe('map_roles.yard.name');
});

it('refuses roles that lead to unknown or repeated roles, malformed ids and unknown keys', function (array $roles, string $message) {
    expect(fn() => MapRoleCatalog::fromArray(['schema' => MapRoleCatalog::SCHEMA, 'roles' => $roles]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown destination' => [['a' => ['name' => ['message' => 'k', 'default' => 'A'], 'leadsTo' => ['b']]], 'which is not a map role'],
    'repeated destination' => [['a' => ['name' => ['message' => 'k', 'default' => 'A'], 'leadsTo' => ['a', 'a']]], 'distinct role ids'],
    'empty destinations' => [['a' => ['name' => ['message' => 'k', 'default' => 'A'], 'leadsTo' => []]], 'distinct role ids'],
    'bad id' => [['Bad Id' => ['name' => ['message' => 'k', 'default' => 'A']]], 'stable lowercase id'],
    'unknown key' => [['a' => ['name' => ['message' => 'k', 'default' => 'A'], 'kind' => 'x']], 'stable lowercase id'],
    'no name' => [['a' => []], 'stable lowercase id'],
]);

it('reads a map\'s role from its data and refuses a malformed one', function () {
    expect(MapRoleCatalog::readMapRole(['name' => 'Field']))->toBeNull()
        ->and(MapRoleCatalog::readMapRole(['role' => 'yard']))->toBe('yard')
        ->and(fn() => MapRoleCatalog::readMapRole(['role' => 3]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => MapRoleCatalog::fromArray(syntheticMapRoles())->allowsDestination('region', 'cave'))
        ->toThrow(InvalidArgumentException::class, "Unknown map role 'cave'");
});

it('loads a project without roles as none, and the project file when there is one', function () {
    $root = sys_get_temp_dir() . '/map-roles-' . bin2hex(random_bytes(4));
    mkdir($root . '/Data', 0777, true);
    try {
        expect(MapRoleCatalog::load($root)->getIds())->toBe([]);
        file_put_contents($root . '/' . MapRoleCatalog::FILE, '<?php return ' . var_export(syntheticMapRoles(), true) . ';');
        expect(MapRoleCatalog::load($root)->getIds())->toBe(['region', 'yard', 'room']);
    } finally {
        @unlink($root . '/' . MapRoleCatalog::FILE);
        @rmdir($root . '/Data');
        @rmdir($root);
    }
});
