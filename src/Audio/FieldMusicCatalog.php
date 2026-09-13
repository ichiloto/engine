<?php

namespace Ichiloto\Engine\Audio;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;

/** Conditional field music, not another playback or persistent-state system. */
final class FieldMusicCatalog implements ConfigInterface
{
  /** @var FieldMusicRule[] Highest priority first; declaration order breaks ties. */
  private array $rules = [];

  public function __construct(array $entries = [], string $source = 'field music')
  {
    if (! array_is_list($entries)) {
      throw new InvalidArgumentException("$source must be an ordered list.");
    }
    $ids = [];
    foreach ($entries as $index => $entry) {
      $where = "$source rule [$index]";
      if (! is_array($entry)) {
        throw new InvalidArgumentException("$where must be an array.");
      }
      $id = $entry['id'] ?? null;
      if (! is_string($id) || trim($id) === '' || isset($ids[trim($id)])) {
        throw new InvalidArgumentException("$where needs a unique non-empty id.");
      }
      $ids[$id = trim($id)] = true;
      $where .= " ($id)";
      foreach (array_keys($entry) as $key) {
        if (! in_array($key, ['id', 'track', 'priority', 'conditions', 'maps'], true)) {
          throw new InvalidArgumentException("$where has unknown field '$key'.");
        }
      }
      if (! array_key_exists('track', $entry)
        || ($entry['track'] !== null && (! is_string($entry['track']) || trim($entry['track']) === ''))) {
        throw new InvalidArgumentException("$where track must be a non-empty reference or null for silence.");
      }
      $priority = $entry['priority'] ?? 0;
      if (! is_int($priority)) {
        throw new InvalidArgumentException("$where priority must be an integer.");
      }
      $conditions = $entry['conditions'] ?? [];
      if (! is_array($conditions) || ! array_is_list($conditions)) {
        throw new InvalidArgumentException("$where conditions must be a list.");
      }
      WorldConditionEvaluator::validateAll($conditions, "$where conditions");
      foreach ($conditions as $condition) {
        if (($condition['type'] ?? '') === 'quest'
          && ! in_array($condition['status'] ?? 'completed', ['active', 'completed'], true)) {
          throw new InvalidArgumentException("$where quest status must be active or completed.");
        }
      }
      $maps = $entry['maps'] ?? [];
      if (! is_array($maps) || ! array_is_list($maps) || (array_key_exists('maps', $entry) && $maps === [])) {
        throw new InvalidArgumentException("$where maps must be a non-empty list when specified.");
      }
      foreach ($maps as $map) {
        if (! is_string($map) || trim($map) === '' || str_contains($map, '*')) {
          throw new InvalidArgumentException("$where maps must contain exact non-empty map IDs, not patterns.");
        }
      }
      $this->rules[] = new FieldMusicRule(
        $id, $entry['track'] === null ? null : trim($entry['track']),
        $priority, $conditions, array_map('trim', $maps),
      );
    }
    // PHP's stable sort preserves authored order for equal priorities.
    usort($this->rules, static fn(FieldMusicRule $a, FieldMusicRule $b): int => $b->priority <=> $a->priority);
  }

  public static function fromProject(?string $filename = null): self
  {
    $filename ??= Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'field-music.php');
    if (! is_file($filename)) {
      return new self();
    }
    $entries = require $filename;
    if (! is_array($entries)) {
      throw new InvalidArgumentException("$filename must return an array.");
    }
    return new self($entries, $filename);
  }

  /** A matching null-track rule means deliberate silence; no rule means fallback. */
  public function resolve(string $mapId, GameState $state, ?Party $party = null): ?FieldMusicRule
  {
    foreach ($this->rules as $rule) {
      if (($rule->maps === [] || in_array($mapId, $rule->maps, true))
        && WorldConditionEvaluator::allHold($rule->conditions, $state, $party)) {
        return $rule;
      }
    }
    return null;
  }

  /** @return FieldMusicRule[] */
  public function rules(): array { return $this->rules; }
  public function get(string $path, mixed $default = null): mixed
  {
    return array_find($this->rules, static fn(FieldMusicRule $rule): bool => $rule->id === $path) ?? $default;
  }
  public function has(string $path): bool { return $this->get($path) !== null; }
  public function set(string $path, mixed $value): void
  {
    throw new InvalidArgumentException('Field music definitions are immutable project content.');
  }
  public function persist(): void {}
}
