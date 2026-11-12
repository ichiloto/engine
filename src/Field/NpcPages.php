<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Messaging\Dialogue\ConditionalDialogue;
use Ichiloto\Engine\Quests\QuestLog;
use InvalidArgumentException;

/**
 * An NPC's ordered interaction pages: talking runs the first page whose
 * conditions hold and which has not retired, so the most advanced story
 * state goes first and an unconditional page last.
 *
 * Pages are the NPC's `dialogue` variants ({@see ConditionalDialogue}).
 * Two shorthands author a single unconditional page: a plain list of lines,
 * or a top-level `script`, which replaces any dialogue. Only a shorthand NPC carries its cue on the NPC
 * itself; with a page list, each page owns its cue, so the NPC's guidance
 * always comes from the page talking would run.
 *
 * Runtime, waymarks, validation and the Editor read pages through this one
 * definition.
 */
final class NpcPages
{
  /** @param list<NpcPage> $pages */
  private function __construct(public array $pages, public ?string $npcId)
  {
  }

  /**
   * Reads an authored NPC entry's pages.
   *
   * @throws InvalidArgumentException When the pages or their owner are malformed.
   */
  public static function fromEntry(array $entry): self
  {
    $id = trim(strval($entry['id'] ?? ''));
    $id = $id === '' ? null : $id;
    $dialogue = $entry['dialogue'] ?? [];
    $script = $entry['script'] ?? [];
    if (!is_array($dialogue) || !is_array($script)) {
      throw new InvalidArgumentException('NPC dialogue and script must be lists.');
    }
    $dialogue = array_values(array_filter($dialogue, is_array(...)));
    $script = array_values(array_filter($script, is_array(...)));
    $cue = $entry['cue'] ?? null;
    if ($cue !== null && !is_array($cue)) {
      throw new InvalidArgumentException('NPC cue must be an array.');
    }

    if ($script === [] && $dialogue !== [] && ConditionalDialogue::isVariantList($dialogue)) {
      if ($cue !== null) {
        throw new InvalidArgumentException('NPC cue belongs on one of its pages: the page talking would run shows its own cue.');
      }
      $pages = array_map(static fn(array $page, int $index): NpcPage => NpcPage::fromArray($page, $index),
        $dialogue, array_keys($dialogue));
    } else {
      // A top-level script replaces any dialogue, as it always has; validation names the unused dialogue.
      $shorthand = array_filter(['lines' => $script === [] ? $dialogue : [], 'script' => $script, 'cue' => $cue],
        static fn(mixed $value): bool => $value !== [] && $value !== null);
      $pages = $shorthand === [] ? [] : [NpcPage::fromArray($shorthand, 0)];
    }

    $ids = [];
    foreach ($pages as $page) {
      if ($page->id === null) { continue; }
      if (isset($ids[$page->id])) {
        throw new InvalidArgumentException(sprintf('NPC pages %d and %d share the id "%s".', $ids[$page->id] + 1, $page->index + 1, $page->id));
      }
      $ids[$page->id] = $page->index;
    }
    if (array_any($pages, static fn(NpcPage $page): bool => !$page->reusable)) {
      if ($id === null) {
        throw new InvalidArgumentException('An NPC with a page that runs once needs a stable id.');
      }
      NpcPage::readId($id, 'NPC id');
    }

    return new self($pages, $id);
  }

  /** Whether talking does anything on any page. */
  public bool $isTalkable {
    get => array_any($this->pages, static fn(NpcPage $page): bool => $page->isTalkable);
  }

  /** Whether any page shows a cue. */
  public bool $hasCue {
    get => array_any($this->pages, static fn(NpcPage $page): bool => $page->cue !== null);
  }

  /**
   * The saved one-shot identity of a page, within its map's one-shot events:
   * `{npcId}/{pageId}`, so the store key is `{map}:{npcId}/{pageId}`.
   */
  public static function getCompletionMarker(string $npcId, string $pageId): string
  {
    return "{$npcId}/{$pageId}";
  }

  /** Selects the page talking runs now, or null when every page is gated out or retired. */
  public function selectPage(string $mapId, GameState $state, ?Party $party = null, ?QuestLog $questLog = null): ?NpcPage
  {
    foreach ($this->pages as $page) {
      if ($this->isRetired($page, $mapId, $state)) { continue; }
      if (WorldConditionEvaluator::allHold($page->conditions, $state, $party, $questLog)) {
        return $page;
      }
    }
    return null;
  }

  /** Whether a one-shot page has already completed. */
  public function isRetired(NpcPage $page, string $mapId, GameState $state): bool
  {
    return !$page->reusable && $this->npcId !== null && $page->id !== null
      && $state->isEventComplete($mapId, self::getCompletionMarker($this->npcId, $page->id));
  }

  /** Records a one-shot page's completion; reusable pages record nothing. */
  public function retire(NpcPage $page, string $mapId, GameState $state): void
  {
    if (!$page->reusable && $this->npcId !== null && $page->id !== null) {
      $state->markEventComplete($mapId, self::getCompletionMarker($this->npcId, $page->id));
    }
  }
}
