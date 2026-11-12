<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Localization\LocalizedText;
use InvalidArgumentException;

/**
 * What a project's maps are to the world: an overworld, an outside place, an interior, a dungeon, or whatever roles
 * a game names. A map names its role in its data's `role`; the project's roles live in `Data/map-roles.php`, each
 * with a localized name and, optionally, the roles its maps may lead to. A role that lists none leads anywhere.
 * The Engine defines no role and enforces none: a game's travel rules are authored data its Editor validates.
 */
final readonly class MapRoleCatalog
{
    public const string FILE = 'Data/map-roles.php';
    public const string SCHEMA = 'ichiloto.map-roles/1';
    /** The map data key naming the map's role. */
    public const string MAP_KEY = 'role';
    public const int MAX_ROLES = 64;

    /** @var array<string, array{name: array, leadsTo?: list<string>}> Roles by stable id, in authored order. */
    public array $roles;

    public function __construct(array $roles = [])
    {
        if (count($roles) > self::MAX_ROLES) { throw new InvalidArgumentException('Map roles exceed 64 records.'); }
        $copy = [];
        foreach ($roles as $id => $entry) {
            if (!is_string($id) || preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1 || !is_array($entry)
                || array_diff(array_keys($entry), ['name', 'leadsTo']) !== [] || !array_key_exists('name', $entry)) {
                throw new InvalidArgumentException('A map role needs a stable lowercase id, a localized name and, optionally, the roles it leads to.');
            }
            LocalizedText::fromArray($entry['name']);
            if (array_key_exists('leadsTo', $entry) && (!is_array($entry['leadsTo']) || !array_is_list($entry['leadsTo'])
                || $entry['leadsTo'] === [] || array_filter($entry['leadsTo'], 'is_string') !== $entry['leadsTo']
                || count(array_unique($entry['leadsTo'])) !== count($entry['leadsTo']))) {
                throw new InvalidArgumentException(sprintf('Map role %s leads to a list of distinct role ids; leave it out to lead anywhere.', $id));
            }
            $copy[$id] = $entry;
        }
        foreach ($copy as $id => $entry) {
            foreach ($entry['leadsTo'] ?? [] as $destination) {
                if (!isset($copy[$destination])) {
                    throw new InvalidArgumentException(sprintf('Map role %s leads to %s, which is not a map role.', $id, $destination));
                }
            }
        }
        $this->roles = $copy;
    }

    /** The project's roles, or none when it declares none. */
    public static function load(string $assetRoot): self
    {
        $file = $assetRoot . '/' . self::FILE;
        if (!file_exists($file) && !is_link($file)) { return new self(); }
        $root = realpath($assetRoot); $path = realpath($file);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new InvalidArgumentException('Map role catalog must be a readable file inside assets.');
        }
        return self::fromArray((static fn(string $path): mixed => require $path)($path));
    }

    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA
            || array_diff(array_keys($data), ['schema', 'roles']) !== [] || !is_array($data['roles'] ?? null)) {
            throw new InvalidArgumentException('Map roles require schema ichiloto.map-roles/1 and a roles dictionary.');
        }
        return new self($data['roles']);
    }

    public function toArray(): array { return ['schema' => self::SCHEMA, 'roles' => $this->roles]; }

    public static function getNameMessageKey(string $id): string
    {
        if (preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1) { throw new InvalidArgumentException('Invalid map role id.'); }
        return 'map_roles.' . $id . '.name';
    }

    /** @return list<string> */
    public function getIds(): array { return array_keys($this->roles); }

    public function hasRole(string $id): bool { return isset($this->roles[$id]); }

    public function getName(string $id): string
    {
        return LocalizedText::fromArray($this->requireRole($id)['name'])->getText();
    }

    /**
     * The roles a map of this role may lead to, or null when it may lead anywhere.
     *
     * @return list<string>|null
     */
    public function getDestinationRoles(string $id): ?array
    {
        return $this->requireRole($id)['leadsTo'] ?? null;
    }

    /** Whether a map of one role may lead to a map of another. */
    public function allowsDestination(string $from, string $to): bool
    {
        $this->requireRole($to);
        $allowed = $this->getDestinationRoles($from);

        return $allowed === null || in_array($to, $allowed, true);
    }

    /**
     * The role a map's data names, or null when it names none.
     *
     * @param array<string, mixed> $mapData
     * @throws InvalidArgumentException When the role is not a stable id.
     */
    public static function readMapRole(array $mapData): ?string
    {
        if (!array_key_exists(self::MAP_KEY, $mapData)) { return null; }
        $role = $mapData[self::MAP_KEY];
        if (!is_string($role) || preg_match(MapLandmarkCatalog::ID_PATTERN, $role) !== 1) {
            throw new InvalidArgumentException('A map role is a stable lowercase id.');
        }
        return $role;
    }

    private function requireRole(string $id): array
    {
        return $this->roles[$id] ?? throw new InvalidArgumentException(sprintf('Unknown map role \'%s\'.', $id));
    }
}
