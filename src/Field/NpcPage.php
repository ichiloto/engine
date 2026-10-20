<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Events\Triggers\EventCue;
use Ichiloto\Engine\Field\Validation\MapEventSpeakerRule;
use InvalidArgumentException;

/**
 * One interaction page of an NPC, as RPG Maker's event pages: what talking to
 * the NPC does while the page's conditions hold.
 *
 * A page shows its `lines`, then runs at most one action: inline `script`
 * commands, the shared event script `scriptId` (assets/Events/<id>.php, the
 * same files map script events and common events use) or the first-class
 * Cinematic `cinematicId`. Its `sets` apply when it finishes. Its `cue` is
 * the NPC's guidance while it is the page talking would run. A page with
 * `reusable` false retires once its action completes; it needs a stable `id`,
 * which names its completion in saves ({@see NpcPages::getCompletionMarker()}).
 * `speakers` names the other NPCs on the map its scene stages beside the NPC.
 */
final class NpcPage
{
  public const array FIELDS = ['id', 'conditions', 'lines', 'script', 'scriptId', 'cinematicId', 'sets', 'cue', 'reusable', 'speakers'];
  /** Characters an NPC or page id may not use: they separate the parts of a saved completion. */
  public const string RESERVED_ID_CHARACTERS = ':/';

  /**
   * @param list<array<string, mixed>> $conditions
   * @param list<array<string, mixed>> $lines
   * @param list<array<string, mixed>> $script
   * @param list<array<string, mixed>> $sets
   * @param list<string> $speakers
   */
  public function __construct(
    public readonly int $index,
    public readonly ?string $id = null,
    public readonly array $conditions = [],
    public readonly array $lines = [],
    public readonly array $script = [],
    public readonly ?string $scriptId = null,
    public readonly ?string $cinematicId = null,
    public readonly array $sets = [],
    public readonly ?EventCue $cue = null,
    public readonly bool $reusable = true,
    public readonly array $speakers = [],
  )
  {
  }

  /** Whether talking runs anything beyond the page's lines. */
  public bool $hasAction {
    get => $this->script !== [] || $this->scriptId !== null || $this->cinematicId !== null;
  }

  /** Whether talking on this page does anything at all. */
  public bool $isTalkable {
    get => $this->lines !== [] || $this->hasAction;
  }

  /**
   * Reads one authored page.
   *
   * @throws InvalidArgumentException When the page is malformed.
   */
  public static function fromArray(array $page, int $index): self
  {
    $unknown = array_diff(array_keys($page), self::FIELDS);
    if ($unknown !== []) {
      throw new InvalidArgumentException(sprintf('NPC page %d has unknown field "%s".', $index + 1, reset($unknown)));
    }
    $read = static function (string $field) use ($page, $index): array {
      $value = $page[$field] ?? [];
      if (!is_array($value) || !array_is_list($value) || array_any($value, static fn(mixed $entry): bool => !is_array($entry))) {
        throw new InvalidArgumentException(sprintf('NPC page %d %s must be a list of entries.', $index + 1, $field));
      }
      return $value;
    };
    $id = self::readId($page['id'] ?? null, sprintf('NPC page %d id', $index + 1));
    $scriptId = self::readName($page['scriptId'] ?? null, sprintf('NPC page %d scriptId', $index + 1));
    $cinematicId = self::readName($page['cinematicId'] ?? null, sprintf('NPC page %d cinematicId', $index + 1));
    $script = $read('script');
    if (count(array_filter([$script !== [], $scriptId !== null, $cinematicId !== null])) > 1) {
      throw new InvalidArgumentException(sprintf('NPC page %d runs more than one of script, scriptId and cinematicId.', $index + 1));
    }
    $reusable = $page['reusable'] ?? true;
    if (!is_bool($reusable)) {
      throw new InvalidArgumentException(sprintf('NPC page %d reusable must be true or false.', $index + 1));
    }
    if (!$reusable && $id === null) {
      throw new InvalidArgumentException(sprintf('NPC page %d runs once, so it needs a stable id.', $index + 1));
    }
    $cue = $page['cue'] ?? null;
    if ($cue !== null && !is_array($cue)) {
      throw new InvalidArgumentException(sprintf('NPC page %d cue must be an array.', $index + 1));
    }
    return new self(
      $index,
      $id,
      $read('conditions'),
      $read('lines'),
      $script,
      $scriptId,
      $cinematicId,
      $read('sets'),
      EventCue::fromArray($cue),
      $reusable,
      array_key_exists('speakers', $page) ? MapEventSpeakerRule::readSpeakers($page) : [],
    );
  }

  /** Reads an optional stable identity that may name a saved completion. */
  public static function readId(mixed $value, string $label): ?string
  {
    $id = self::readName($value, $label);
    if ($id !== null && strpbrk($id, self::RESERVED_ID_CHARACTERS) !== false) {
      throw new InvalidArgumentException(sprintf('%s may not contain "%s".', $label, implode('" or "', str_split(self::RESERVED_ID_CHARACTERS))));
    }
    return $id;
  }

  private static function readName(mixed $value, string $label): ?string
  {
    if ($value === null) { return null; }
    if (!is_string($value) || trim($value) === '' || trim($value) !== $value) {
      throw new InvalidArgumentException(sprintf('%s must be a non-empty name without surrounding spaces.', $label));
    }
    return $value;
  }
}
