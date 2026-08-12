<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use Ichiloto\Engine\Entities\Character;

/**
 * Defines who may wield a summon and how many members may hold it at once.
 *
 * Eligibility modes:
 * - `all`: any party member may be assigned the summon.
 * - `roles`: only characters whose role name appears in `$roles`.
 * - `characters`: only the named characters.
 *
 * Tenancy:
 * - `shared`: any number of eligible members may hold the summon at once.
 * - `exclusive`: only one member may hold the summon at a time.
 *
 * A summon whose data file declares no `wielders` block has no policy at all
 * and remains openly usable by the whole party without assignment.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonWielderPolicy
{
  protected bool $valid = true;
  /**
   * Any party member may be assigned the summon.
   */
  public const string MODE_ALL = 'all';
  /**
   * Only characters with a listed role name may be assigned the summon.
   */
  public const string MODE_ROLES = 'roles';
  /**
   * Only the listed characters may be assigned the summon.
   */
  public const string MODE_CHARACTERS = 'characters';
  /**
   * Any number of eligible members may hold the summon at once.
   */
  public const string TENANCY_SHARED = 'shared';
  /**
   * Only one member may hold the summon at a time.
   */
  public const string TENANCY_EXCLUSIVE = 'exclusive';

  /**
   * @var string[] The eligible role names (mode `roles`).
   */
  public array $roles = [];
  /**
   * @var string[] The eligible character names (mode `characters`).
   */
  public array $characters = [];

  /**
   * @param string $mode The eligibility mode.
   * @param string[] $roles The eligible role names.
   * @param string[] $characters The eligible character names.
   * @param string $tenancy The tenancy mode.
   */
  public function __construct(
    public string $mode = self::MODE_ALL,
    array $roles = [],
    array $characters = [],
    public string $tenancy = self::TENANCY_SHARED,
  )
  {
    $normalizedMode = strtolower(trim($mode));
    $this->mode = $normalizedMode;
    $this->valid = in_array($normalizedMode, [self::MODE_ALL, self::MODE_ROLES, self::MODE_CHARACTERS], true);

    $normalizedTenancy = strtolower(trim($tenancy));
    $this->tenancy = $normalizedTenancy;
    $this->valid = $this->valid
      && in_array($normalizedTenancy, [self::TENANCY_SHARED, self::TENANCY_EXCLUSIVE], true);

    $rolesAreValid = self::isNameList($roles);
    $charactersAreValid = self::isNameList($characters);
    $this->roles = self::normalizeNames($roles);
    $this->characters = self::normalizeNames($characters);
    $this->valid = $this->valid
      && $rolesAreValid
      && $charactersAreValid
      && ($this->mode !== self::MODE_ROLES || $this->roles !== [])
      && ($this->mode !== self::MODE_CHARACTERS || $this->characters !== []);
  }

  /**
   * Hydrates a declared policy without treating malformed data as omission.
   *
   * Omission means open access for backward compatibility. Once a project
   * declares the block, invalid structure must instead fail closed.
   */
  public static function fromAuthored(mixed $data): self
  {
    if (! is_array($data)) {
      return new self('__invalid__', [], [], '__invalid__');
    }

    $mode = $data['mode'] ?? self::MODE_ALL;
    $tenancy = $data['tenancy'] ?? self::TENANCY_SHARED;

    if (! is_string($mode) || ! is_string($tenancy)) {
      return new self('__invalid__', [], [], '__invalid__');
    }

    $roles = $data['roles'] ?? [];
    $characters = $data['characters'] ?? [];

    if (! is_array($roles) || ! is_array($characters)) {
      return new self('__invalid__', [], [], '__invalid__');
    }

    return new self($mode, $roles, $characters, $tenancy);
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      strval($data['mode'] ?? self::MODE_ALL),
      is_array($data['roles'] ?? null) ? $data['roles'] : [],
      is_array($data['characters'] ?? null) ? $data['characters'] : [],
      strval($data['tenancy'] ?? self::TENANCY_SHARED),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return array_filter([
      'mode' => $this->mode,
      'roles' => $this->roles,
      'characters' => $this->characters,
      'tenancy' => $this->tenancy,
    ], static fn(mixed $value): bool => $value !== []);
  }

  /**
   * Determines whether the given character is eligible to wield the summon.
   *
   * @param Character $character The character to check.
   * @return bool True when the character satisfies the eligibility mode.
   */
  public function allowsCharacter(Character $character): bool
  {
    if (! $this->valid) {
      return false;
    }

    return match ($this->mode) {
      self::MODE_ROLES => in_array(strtolower(trim($character->role->name)), array_map('strtolower', $this->roles), true),
      self::MODE_CHARACTERS => in_array(strtolower(trim($character->name)), array_map('strtolower', $this->characters), true),
      default => true,
    };
  }

  /**
   * Determines whether only one member may hold the summon at a time.
   *
   * @return bool True when tenancy is exclusive.
   */
  public function isExclusive(): bool
  {
    return $this->tenancy === self::TENANCY_EXCLUSIVE;
  }

  /** Returns whether the authored policy uses recognized values. */
  public function isValid(): bool
  {
    return $this->valid;
  }

  /**
   * @param array<int, mixed> $names
   * @return string[]
   */
  protected static function normalizeNames(array $names): array
  {
    return array_values(array_filter(
      array_map(static fn(mixed $name): string => is_string($name) ? trim($name) : '', $names),
      static fn(string $name): bool => $name !== ''
    ));
  }

  /** Returns whether the payload is a list of non-empty string identities. */
  protected static function isNameList(array $names): bool
  {
    if (! array_is_list($names)) {
      return false;
    }

    foreach ($names as $name) {
      if (! is_string($name) || trim($name) === '') {
        return false;
      }
    }

    return true;
  }
}
