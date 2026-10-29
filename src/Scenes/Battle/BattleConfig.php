<?php

namespace Ichiloto\Engine\Scenes\Battle;

use Ichiloto\Engine\Battle\EscapePolicy;
use Ichiloto\Engine\Battle\ReservePolicy;
use Ichiloto\Engine\Battle\BattlePartyRoster;
use Ichiloto\Engine\Battle\BattleClassification;
use Ichiloto\Engine\Battle\Entry\BattleEntryContext;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;

/**
 * Represents the battle configuration.
 *
 * @package Ichiloto\Engine\Scenes\Battle
 */
class BattleConfig implements SceneConfigurationInterface
{
  protected(set) BattleClassification $classification;
  protected(set) string $entryExecutionId;
  protected(set) ?BattleEntryContext $battleEntryContext = null;
  /** @var string[] */
  protected(set) array $appliedEntryRuleIds = [];
  private bool $hasEvaluatedEntryRules = false;
  private ?BattlePartyRoster $roster = null;
  private ?\Ichiloto\Engine\Battle\Formation\BattleFormation $formationOwner = null;
  public \Ichiloto\Engine\Battle\Formation\BattleFormation $formation {
    get => $this->getFormation();
  }

  public function getFormation(): \Ichiloto\Engine\Battle\Formation\BattleFormation
  {
    $party = $this->partyRoster->battlers;
    $troop = $this->troop->members->toArray();
    $authored = $this->settings['partyFormation'] ?? [];
    if (!is_array($authored) || !array_is_list($authored) || count($authored) > Party::MAX_BATTLE_SIZE) {
      throw new \InvalidArgumentException('partyFormation requires at most eight logical positions or null.');
    }
    $positions = array_map(static fn($data) => $data === null ? null
      : \Ichiloto\Engine\Battle\Formation\BattlePosition::fromArray($data, 'partyFormation'), $authored);
    $partyPositions = array_map(static fn(int $index) => $positions[$index] ?? null, array_keys($party));
    $troopPositions = array_map($this->troop->getBattlePosition(...), $troop);
    if ($this->formationOwner === null) {
      return $this->formationOwner = new \Ichiloto\Engine\Battle\Formation\BattleFormation($party, $troop, $partyPositions, $troopPositions);
    }
    $this->formationOwner->synchronizeSide('party', $party, $partyPositions);
    $this->formationOwner->synchronizeSide('troop', $troop, $troopPositions);
    return $this->formationOwner;
  }
  private ?\Ichiloto\Engine\Battle\BattleReactions $reactionOwner = null;
  public \Ichiloto\Engine\Battle\BattleReactions $reactions {
    get => $this->getReactions();
  }
  public function getReactions(?\Ichiloto\Engine\Battle\Resolution\CombatRandomSource $random = null): \Ichiloto\Engine\Battle\BattleReactions
  {
    return $this->reactionOwner ??= new \Ichiloto\Engine\Battle\BattleReactions(random: $random);
  }
  public BattlePartyRoster $partyRoster {
    get => $this->roster ??= new BattlePartyRoster($this->party, $this->getReservePolicy());
  }
  /**
   * Creates a new instance of the battle configuration.
   *
   * @param Party $party The party of player characters.
   * @param Troop $troop The troop of enemies.
   * @param array $events The battle events.
   * @param array<string, mixed> $settings Runtime battle settings.
   */
  public function __construct(
    protected(set) Party $party,
    protected(set) Troop $troop,
    protected(set) array $events = [],
    protected(set) array $settings = [],
    ?BattleClassification $classification = null,
    ?string $entryExecutionId = null,
    ?BattlePartyRoster $partyRoster = null,
  )
  {
    $this->classification = $classification ?? $troop->classification;
    $policy = $this->getReservePolicy();
    if ($partyRoster !== null && ($partyRoster->party !== $party || $partyRoster->policy !== $policy)) {
      throw new \InvalidArgumentException('The battle roster must belong to this party and reserve policy.');
    }
    $this->roster = $partyRoster;
    $entryExecutionId = is_string($entryExecutionId) ? trim($entryExecutionId) : '';
    $this->entryExecutionId = $entryExecutionId !== '' ? $entryExecutionId : bin2hex(random_bytes(16));
    $this->getFormation();
  }

  public function entryContext(GameState $worldState): BattleEntryContext
  {
    return $this->battleEntryContext ??= BattleEntryContext::capture(
      $this->classification,
      $this->troop->definitionId,
      $this->party,
      $worldState,
      $this->entryExecutionId,
      $this->partyRoster,
    );
  }

  public function entryRulesEvaluated(): bool
  {
    return $this->hasEvaluatedEntryRules;
  }

  public function markEntryRuleApplied(string $ruleId): void
  {
    if (! in_array($ruleId, $this->appliedEntryRuleIds, true)) {
      $this->appliedEntryRuleIds[] = $ruleId;
    }
  }

  public function hasAppliedEntryRule(string $ruleId): bool
  {
    return in_array($ruleId, $this->appliedEntryRuleIds, true);
  }

  public function markEntryRulesEvaluated(): void
  {
    $this->hasEvaluatedEntryRules = true;
  }

  /**
   * @inheritDoc
   */
  public function serialize(): ?string
  {
    return serialize($this->getData());
  }

  /**
   * @inheritDoc
   */
  public function unserialize(string $data): void
  {
    $this->setData(unserialize($data));
  }

  /**
   * @inheritDoc
   */
  public function __toString(): string
  {
    return json_encode($this);
  }

  /**
   * Returns the resolved retreat policy for this battle.
   */
  public function getEscapePolicy(): EscapePolicy
  {
    return EscapePolicy::resolve($this->settings['escapePolicy'] ?? null);
  }

  public function getReservePolicy(): ReservePolicy
  {
    return ReservePolicy::resolve($this->settings['reservePolicy'] ?? null);
  }

  /**
   * Serializes the battle configuration.
   *
   * @return array<string, mixed> The serialized data.
   */
  public function __serialize(): array
  {
    return $this->getData();
  }

  /**
   * Deserializes the battle configuration.
   *
   * @param array $data The data to unserialize.
   * @return void
   */
  public function __unserialize(array $data): void
  {
    $this->setData($data);
  }

  /**
   * @inheritDoc
   */
  public function jsonSerialize(): array
  {
    return $this->getData();
  }

  /**
   * Gets the data for the battle configuration.
   *
   * @return array<string, mixed> The data.
   */
  protected function getData(): array
  {
    return [
      'party' => $this->party,
      'troop' => $this->troop,
      'events' => $this->events,
      'settings' => $this->settings,
      'classification' => $this->classification->value,
      'entryExecutionId' => $this->entryExecutionId,
    ];
  }

  /**
   * Sets the data for the battle configuration.
   *
   * @param array $data The data to set.
   */
  protected function setData(array $data): void
  {
    foreach ($data as $key => $value) {
      if (in_array($key, [
        'classification',
        'entryExecutionId',
        'battleEntryContext',
        'appliedEntryRuleIds',
        'hasEvaluatedEntryRules',
        'roster',
        'partyRoster',
        'reactionOwner',
        'reactions',
        'formationOwner',
        'formation',
      ], true)) {
        continue;
      }

      if (property_exists($this, $key)) {
        $this->{$key} = $value;
      }
    }

    $this->events = is_array($data['events'] ?? null) ? $data['events'] : [];
    $this->settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $this->classification = BattleClassification::resolve(
      $data['classification'] ?? ($this->troop->classification ?? null),
      'serialized BattleConfig',
    );
    $executionId = trim(strval($data['entryExecutionId'] ?? ''));
    $this->entryExecutionId = $executionId !== '' ? $executionId : bin2hex(random_bytes(16));
    $this->battleEntryContext = null;
    $this->appliedEntryRuleIds = [];
    $this->hasEvaluatedEntryRules = false;
    $this->getReservePolicy();
    $this->roster = null;
    $this->reactionOwner = null;
    $this->formationOwner = null;
    $this->getFormation();
  }
}
