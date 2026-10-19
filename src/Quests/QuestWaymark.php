<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use InvalidArgumentException;

/**
 * Where an objective sends the player: a map and, optionally, one stable
 * subject on it.
 *
 * An objective lists its waymarks in order. Each is shown while the
 * objective is incomplete and its own conditions hold, so phases are
 * conditions and free-order work is several waymarks shown at once.
 * Waymarks never change progress; they only guide.
 */
final readonly class QuestWaymark
{
  public const array FIELDS = ['map', 'event', 'npc', 'object', 'landmark', 'conditions'];
  public const int MAX_CONDITIONS = 32;

  /**
   * @param string $map The map id (its asset path, as `reach_map` uses).
   * @param QuestWaymarkSubject|null $subject The kind of subject, or null for the whole map.
   * @param string $subjectId The subject's stable id on that map; empty for the whole map.
   * @param list<array<string, mixed>> $conditions World conditions that must hold for it to show.
   */
  public function __construct(
    public string $map,
    public ?QuestWaymarkSubject $subject = null,
    public string $subjectId = '',
    public array $conditions = [],
  )
  {
    if (trim($this->map) === '') {
      throw new InvalidArgumentException('A quest waymark requires a map.');
    }
    if (($this->subject === null) !== ($this->subjectId === '')) {
      throw new InvalidArgumentException('A quest waymark subject needs both a kind and an id.');
    }
  }

  /** Whether this waymark currently shows, ignoring objective progress. */
  public function holds(GameState $gameState, ?Party $party = null): bool
  {
    return $this->conditions === [] || WorldConditionEvaluator::allHold($this->conditions, $gameState, $party);
  }

  /**
   * Reads one authored waymark.
   *
   * Shape: `['map' => 'town', 'event' => 'H', 'conditions' => [...]]`, with
   * at most one of `event`, `npc`, `object` or `landmark`.
   *
   * @param mixed $data The authored entry.
   */
  public static function fromArray(mixed $data): self
  {
    if (!is_array($data) || array_diff(array_keys($data), self::FIELDS) !== []) {
      throw new InvalidArgumentException('A quest waymark accepts map, one of event, npc, object or landmark, and conditions.');
    }
    $map = $data['map'] ?? null;
    if (!is_string($map) || trim($map) === '') {
      throw new InvalidArgumentException('A quest waymark requires a map.');
    }
    $subjects = array_values(array_filter(QuestWaymarkSubject::cases(),
      static fn(QuestWaymarkSubject $subject): bool => array_key_exists($subject->value, $data)));
    if (count($subjects) > 1) {
      throw new InvalidArgumentException('A quest waymark points at one subject at most.');
    }
    $subject = $subjects[0] ?? null;
    $subjectId = $subject === null ? '' : $data[$subject->value];
    if ($subject !== null && (!is_string($subjectId) || trim($subjectId) === '')) {
      throw new InvalidArgumentException(sprintf('A quest waymark %s must be a non-empty id.', $subject->value));
    }
    $conditions = $data['conditions'] ?? [];
    if (!is_array($conditions) || !array_is_list($conditions) || count($conditions) > self::MAX_CONDITIONS) {
      throw new InvalidArgumentException('Quest waymark conditions must be a list of at most 32 conditions.');
    }
    WorldConditionEvaluator::validateAll($conditions, 'quest waymark conditions');

    return new self(trim($map), $subject, trim(strval($subjectId)), $conditions);
  }

  /** @return array<string, mixed> The authored shape. */
  public function toArray(): array
  {
    return array_filter([
      'map' => $this->map,
      ...($this->subject === null ? [] : [$this->subject->value => $this->subjectId]),
      'conditions' => $this->conditions,
    ], static fn(mixed $value): bool => $value !== []);
  }
}
