<?php

namespace Ichiloto\Engine\Battle\Entry;

use Ichiloto\Engine\Battle\BattleClassification;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use InvalidArgumentException;

/**
 * Immutable facts captured once when a battle begins.
 *
 * The world state remains private: callers can ask whether the shared
 * condition vocabulary holds but cannot use this context to write state.
 */
final readonly class BattleEntryContext
{
  /** @var array<string, BattleEntryActor> */
  private array $activeById;
  /** @var array<string, BattleEntryActor> */
  private array $reserveById;

  /**
   * @param BattleEntryActor[] $activeActors
   * @param BattleEntryActor[] $reserveActors
   */
  public function __construct(
    public BattleClassification $classification,
    public ?string $troopId,
    public array $activeActors,
    public array $reserveActors,
    private GameState $worldState,
    private Party $party,
    public string $executionId,
  )
  {
    $this->activeById = $this->index($activeActors, 'active');
    $this->reserveById = $this->index($reserveActors, 'reserve');

    $duplicates = array_intersect_key($this->activeById, $this->reserveById);
    if ($duplicates !== []) {
      throw new InvalidArgumentException(sprintf(
        'Battle entry execution %s captured actor identity "%s" in both active and reserve rosters.',
        $executionId,
        array_key_first($duplicates),
      ));
    }
  }

  public static function capture(
    BattleClassification $classification,
    ?string $troopId,
    Party $party,
    GameState $worldState,
    string $executionId,
  ): self
  {
    $activeCharacters = array_values(array_filter(
      $party->battlers->toArray(),
      static fn(mixed $member): bool => $member instanceof Character,
    ));
    $activeObjectIds = array_fill_keys(
      array_map(static fn(Character $actor): int => spl_object_id($actor), $activeCharacters),
      true,
    );
    $reserveCharacters = array_values(array_filter(
      $party->members->toArray(),
      static fn(mixed $member): bool => $member instanceof Character
        && ! isset($activeObjectIds[spl_object_id($member)]),
    ));

    $capture = static fn(Character $actor): BattleEntryActor => new BattleEntryActor(
      $actor->actorId,
      $actor,
    );

    return new self(
      $classification,
      $troopId,
      array_map($capture, $activeCharacters),
      array_map($capture, $reserveCharacters),
      $worldState,
      $party,
      $executionId,
    );
  }

  /** @param array<int, mixed> $conditions */
  public function conditionsHold(array $conditions): bool
  {
    return WorldConditionEvaluator::allHold($conditions, $this->worldState, $this->party);
  }

  public function hasActor(string $actorId, BattleEntryActorPresence $presence): bool
  {
    return $this->actor($actorId, $presence) instanceof BattleEntryActor;
  }

  public function actor(string $actorId, BattleEntryActorPresence $presence = BattleEntryActorPresence::ANY): ?BattleEntryActor
  {
    $actorId = trim($actorId);

    return match ($presence) {
      BattleEntryActorPresence::ACTIVE => $this->activeById[$actorId] ?? null,
      BattleEntryActorPresence::RESERVE => $this->reserveById[$actorId] ?? null,
      BattleEntryActorPresence::ANY => $this->activeById[$actorId] ?? $this->reserveById[$actorId] ?? null,
    };
  }

  /** @param BattleEntryActor[] $actors @return array<string, BattleEntryActor> */
  private function index(array $actors, string $roster): array
  {
    $indexed = [];

    foreach ($actors as $actor) {
      $actorId = trim($actor->actorId);

      if ($actorId === '') {
        throw new InvalidArgumentException(sprintf(
          'Battle entry execution %s captured an actor with no stable identity in the %s roster.',
          $this->executionId,
          $roster,
        ));
      }

      if (isset($indexed[$actorId])) {
        throw new InvalidArgumentException(sprintf(
          'Battle entry execution %s captured duplicate actor identity "%s" in the %s roster.',
          $this->executionId,
          $actorId,
          $roster,
        ));
      }

      $indexed[$actorId] = $actor;
    }

    return $indexed;
  }
}
