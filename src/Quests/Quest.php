<?php

namespace Ichiloto\Engine\Quests;

use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use InvalidArgumentException;
use Ichiloto\Engine\Util\Debug;

/**
 * An authored quest definition.
 *
 * Definitions live in the project's `assets/Data/quests.php` and are pure
 * data — all progress is tracked by the {@see QuestLog}.
 *
 * @package Ichiloto\Engine\Quests
 */
class Quest
{
  /** @var array<string, true> Avoid repeating content diagnostics on every journal tick. */
  private array $reportedRewardReferences = [];
  /**
   * @param string $id The quest id.
   * @param string $name The quest name.
   * @param string $description The journal description.
   * @param string $giver The quest giver's name.
   * @param QuestObjective[] $objectives The objectives, in order.
   * @param array{gold?: int, experience?: int, items?: array<int, string|array{item: string, quantity?: int, price?: int}>} $rewards The completion rewards.
   * @param array<int, array<string, mixed>> $prerequisites Conditions that must hold before the quest can be accepted (same shapes as event-trigger conditions, plus `['type' => 'quest', 'name' => ..., 'status' => 'completed'|'active']`).
   * @param bool $isOptional True for a side quest, which is offered to the
   * player and only entered in the journal if they accept it.
   */
  public function __construct(
    protected(set) string $id,
    protected(set) string $name,
    protected(set) string $description = '',
    protected(set) string $giver = '',
    protected(set) array $objectives = [],
    protected(set) array $rewards = [],
    protected(set) array $prerequisites = [],
    protected(set) bool $isOptional = false,
  )
  {
  }

  /**
   * Builds the text of the offer shown when a side quest is proposed.
   *
   * @return string The offer prompt.
   */
  public function describeOffer(): string
  {
    $parts = [];

    if (trim($this->description) !== '') {
      $parts[] = trim($this->description);
    }

    if (($rewards = $this->describeRewards()) !== '') {
      $parts[] = sprintf('Reward: %s', $rewards);
    }

    $parts[] = 'Accept this quest?';

    return implode("\n", $parts);
  }

  /**
   * Summarizes the rewards for the journal.
   *
   * @return string The reward summary; empty when the quest has none.
   */
  public function describeRewards(): string
  {
    $parts = [];

    if (($gold = intval($this->rewards['gold'] ?? 0)) > 0) {
      $parts[] = sprintf('%d G', $gold);
    }

    if (($experience = intval($this->rewards['experience'] ?? 0)) > 0) {
      $parts[] = sprintf('%d EXP', $experience);
    }

    $itemStore = ConfigStore::has(ItemStore::class) ? ConfigStore::get(ItemStore::class) : null;

    foreach ((array) ($this->rewards['items'] ?? []) as $itemReference) {
      if (is_array($itemReference)) {
        $reference = is_string($itemReference['item'] ?? null) ? $itemReference['item'] : '';
        $quantity = max(0, intval($itemReference['quantity'] ?? 1));
        $name = $this->getRewardDisplayName($itemStore, $reference);
        if ($quantity > 0) {
          $parts[] = $name . ($quantity > 1 ? ' x' . $quantity : '');
        }
        continue;
      }
      if (! is_string($itemReference)) {
        continue;
      }

      $parts[] = $this->getRewardDisplayName($itemStore, $itemReference);
    }

    return implode(', ', $parts);
  }

  private function getRewardDisplayName(?ItemStore $store, string $reference): string
  {
    if ($store !== null && $reference !== '') {
      try { return $store->displayNameFor($reference, sprintf('describing rewards for quest "%s"', $this->id)); }
      catch (NotFoundException) { /* Display is best-effort; granting retains strict validation. */ }
    } elseif ($store === null && $reference !== '') { return $reference; }
    if (!isset($this->reportedRewardReferences[$reference])) {
      Debug::warn(sprintf('Quest "%s" has an unavailable reward reference: %s', $this->id, $reference ?: '(missing item)'));
      $this->reportedRewardReferences[$reference] = true;
    }
    return $reference === '' ? 'Unknown item' : $reference . ' (unavailable)';
  }

  /**
   * Hydrates a quest from its data-file entry.
   *
   * @param array<string, mixed> $data The quest entry.
   * @return self The quest.
   */
  public static function fromArray(array $data): self
  {
    $id = trim(strval($data['id'] ?? ''));

    if ($id === '') {
      throw new InvalidArgumentException('Quests require an id.');
    }

    $objectives = array_map(
      static fn(array $entry): QuestObjective => QuestObjective::fromArray($entry),
      array_values(array_filter((array) ($data['objectives'] ?? []), is_array(...)))
    );

    if (empty($objectives)) {
      throw new InvalidArgumentException(sprintf('Quest %s has no objectives.', $id));
    }

    return new self(
      $id,
      strval($data['name'] ?? $id),
      strval($data['description'] ?? ''),
      strval($data['giver'] ?? ''),
      $objectives,
      is_array($data['rewards'] ?? null) ? $data['rewards'] : [],
      is_array($data['prerequisites'] ?? null) ? $data['prerequisites'] : [],
      (bool) ($data['optional'] ?? false),
    );
  }
}
