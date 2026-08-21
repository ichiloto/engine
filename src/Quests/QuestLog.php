<?php

namespace Ichiloto\Engine\Quests;

/**
 * The party's quest progress.
 *
 * Pure serializable state: which quests are active (with per-objective
 * progress counts) and which are completed. Round-trips through
 * {@see \Ichiloto\Engine\Scenes\Game\GameConfig} into save files as a
 * plain array.
 *
 * @package Ichiloto\Engine\Quests
 */
class QuestLog
{
  /**
   * @var array<string, int[]> Active quests, keyed by quest id, holding one progress count per objective.
   */
  protected array $active = [];
  /**
   * @var string[] Completed quest ids, in completion order.
   */
  protected(set) array $completed = [];

  /**
   * Accepts a quest.
   *
   * @param string $questId The quest id.
   * @param int $objectiveCount The quest's objective count.
   * @return bool True when newly accepted; false when already known.
   */
  public function accept(string $questId, int $objectiveCount): bool
  {
    $questId = trim($questId);

    if ($questId === '' || isset($this->active[$questId]) || $this->isCompleted($questId)) {
      return false;
    }

    $this->active[$questId] = array_fill(0, max(1, $objectiveCount), 0);

    return true;
  }

  /**
   * Determines whether a quest is active.
   *
   * @param string $questId The quest id.
   * @return bool True when active.
   */
  public function isActive(string $questId): bool
  {
    return isset($this->active[trim($questId)]);
  }

  /**
   * Determines whether a quest is completed.
   *
   * @param string $questId The quest id.
   * @return bool True when completed.
   */
  public function isCompleted(string $questId): bool
  {
    return in_array(trim($questId), $this->completed, true);
  }

  /**
   * Returns the ids of the active quests.
   *
   * @return string[] The active quest ids.
   */
  public function activeQuestIds(): array
  {
    return array_keys($this->active);
  }

  /**
   * Returns an active quest's per-objective progress counts.
   *
   * @param string $questId The quest id.
   * @return int[] The progress counts; empty when the quest is not active.
   */
  public function getProgress(string $questId): array
  {
    return $this->active[trim($questId)] ?? [];
  }

  /**
   * Sets one objective's progress count.
   *
   * @param string $questId The quest id.
   * @param int $objectiveIndex The objective index.
   * @param int $count The progress count.
   * @return bool True when the stored count changed.
   */
  public function setProgress(string $questId, int $objectiveIndex, int $count): bool
  {
    $questId = trim($questId);

    if (! isset($this->active[$questId][$objectiveIndex])) {
      return false;
    }

    $count = max(0, $count);

    if ($this->active[$questId][$objectiveIndex] === $count) {
      return false;
    }

    $this->active[$questId][$objectiveIndex] = $count;

    return true;
  }

  /**
   * Moves an active quest to the completed list.
   *
   * @param string $questId The quest id.
   * @return bool True when the quest was active.
   */
  public function markCompleted(string $questId): bool
  {
    $questId = trim($questId);

    if (! isset($this->active[$questId])) {
      return false;
    }

    unset($this->active[$questId]);
    $this->completed[] = $questId;

    return true;
  }

  /**
   * @return array{active: array<string, int[]>, completed: string[]}
   */
  public function toArray(): array
  {
    return [
      'active' => $this->active,
      'completed' => $this->completed,
    ];
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    $log = new self();

    foreach ((array) ($data['active'] ?? []) as $questId => $progress) {
      if (! is_string($questId) || trim($questId) === '' || ! is_array($progress)) {
        continue;
      }

      $log->active[trim($questId)] = array_map(
        static fn(mixed $count): int => max(0, intval($count)),
        array_values($progress)
      );
    }

    foreach ((array) ($data['completed'] ?? []) as $questId) {
      if (is_string($questId) && trim($questId) !== '' && ! $log->isCompleted($questId)) {
        $log->completed[] = trim($questId);
      }
    }

    return $log;
  }
}
