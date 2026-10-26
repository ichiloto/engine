<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Util\Stores\ActorStore;
use InvalidArgumentException;

/**
 * The battle test a project keeps in its system data, as RPG Maker keeps its
 * Battle Test troop and party: an optional `battleTest` entry in
 * Data/system.php naming the troop to preselect, the party
 * ({@see BattleTestSetup}) and its arena. These are test settings: normal
 * play never reads them and saves never hold them. With no party the test
 * uses the starting party; a party that is present but malformed is refused,
 * never replaced by another.
 */
final readonly class ProjectBattleTest
{
  /** The entry's key in Data/system.php. */
  public const string SYSTEM_KEY = 'battleTest';

  /**
   * @param string|null $troop The troop the arena preselects, by name; null for none.
   * @param BattleTestSetup|null $setup The party and arena; null for the starting party.
   * @param string|null $arena The arena when no party is set (a setup carries its own).
   */
  public function __construct(
    public ?string $troop = null,
    public ?BattleTestSetup $setup = null,
    public ?string $arena = null,
  ) {
    if ($troop !== null && trim($troop) === '') {
      throw new InvalidArgumentException('A battle test troop is named, or left out.');
    }
    if ($setup !== null && $arena !== null) {
      throw new InvalidArgumentException('A battle test party carries its own arena.');
    }
  }

  /**
   * The entry as Data/system.php writes it: optional `troop`, `members` (as
   * {@see BattleTestSetup::fromArray()}) and `arena`. Anything else is
   * refused, never ignored.
   *
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the entry is malformed.
   */
  public static function fromArray(array $data): self
  {
    $unknown = array_diff(array_keys($data), ['troop', 'members', 'arena']);
    if ($unknown !== []) {
      throw new InvalidArgumentException('A project battle test has no ' . implode(', ', $unknown) . '.');
    }
    $troop = $data['troop'] ?? null;
    if ($troop !== null && !is_string($troop)) {
      throw new InvalidArgumentException('A project battle test\'s troop is a troop name.');
    }
    if (array_key_exists('members', $data)) {
      return new self($troop, BattleTestSetup::fromArray(array_intersect_key($data, ['members' => true, 'arena' => true])));
    }
    $arena = $data['arena'] ?? null;
    if ($arena !== null && (!is_string($arena) || trim($arena) === '')) {
      throw new InvalidArgumentException('A project battle test\'s arena is an arena key.');
    }

    return new self($troop, null, $arena);
  }

  /**
   * The project's battle test, read from Data/system.php; an empty test when
   * the project keeps none.
   *
   * @throws InvalidArgumentException When the entry is malformed.
   */
  public static function loadFromProject(): self
  {
    $system = asset('Data/system.php', true);
    $data = is_array($system) ? ($system[self::SYSTEM_KEY] ?? []) : [];
    if (!is_array($data)) {
      throw new InvalidArgumentException(sprintf('Data/system.php\'s %s is not a battle test entry.', self::SYSTEM_KEY));
    }

    return self::fromArray($data);
  }

  /**
   * The entry as Data/system.php writes it, leaving out what is unset.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      ...($this->troop === null ? [] : ['troop' => $this->troop]),
      ...($this->setup?->toArray() ?? []),
      ...($this->arena === null ? [] : ['arena' => $this->arena]),
    ];
  }

  /**
   * The setup a battle test starts from: the project's party when it keeps
   * one, else the starting party; with the project's arena either way. An
   * editor passes the starting party as it stands, unsaved edits included;
   * otherwise it is read from Data/system.php.
   *
   * @param list<mixed>|null $startingParty The starting party's actor references, or null to read them.
   * @throws InvalidArgumentException When no party can be set up.
   */
  public function createSetup(ActorStore $actors, ?array $startingParty = null): BattleTestSetup
  {
    return $this->setup ?? ($startingParty === null
      ? BattleTestSetup::getFromStartingParty($actors)
      : BattleTestSetup::getFromPartyReferences($actors, $startingParty))->withArena($this->arena);
  }
}
