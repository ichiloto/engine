<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;
use LogicException;

/** Current project source owns availability and presentation; saves keep existing world facts. */
final class WorldAbilityCatalog implements ConfigInterface
{
  public const string FILE = 'Data/world-abilities.php';
  /** @var array<array-key,WorldAbility> */
  private array $abilities = [];
  /** @var array<array-key,bool> Reused only inside one synchronous availability query. */
  private array $queryResults = [];
  private int $queryDepth = 0;

  public function __construct(array $entries = [])
  {
    if (!array_is_list($entries)) { throw new InvalidArgumentException('World abilities must be a list.'); }
    foreach ($entries as $entry) {
      $ability = WorldAbility::fromArray($entry);
      if (isset($this->abilities[$ability->id])) { throw new InvalidArgumentException('Duplicate world ability ' . $ability->id . '.'); }
      $this->abilities[$ability->id] = $ability;
    }
    $visited = $active = [];
    foreach (array_keys($this->abilities) as $id) { $this->validateDependencies((string)$id, $active, $visited); }
  }

  public static function fromProject(?string $assetRoot = null): self
  {
    $file = rtrim($assetRoot ?? getcwd() . '/assets', '/\\') . '/' . self::FILE;
    if (!is_file($file)) { return new self(); }
    $entries = require $file;
    if (!is_array($entries)) { throw new InvalidArgumentException($file . ' must return a list of world abilities.'); }
    try { return new self($entries); }
    catch (InvalidArgumentException $error) { throw new InvalidArgumentException($file . ': ' . $error->getMessage(), previous: $error); }
  }

  /** The application installs one catalog per active project, before evaluating its world. */
  public static function getProjectCatalog(): self
  {
    $catalog = ConfigStore::has(self::class) ? ConfigStore::get(self::class) : null;
    return $catalog instanceof self ? $catalog
      : throw new LogicException('Install the current WorldAbilityCatalog before evaluating a world_ability condition.');
  }

  public function getAbility(string $id): WorldAbility
  {
    return $this->abilities[$id] ?? throw new InvalidArgumentException('Unknown world ability ' . $id . '.');
  }

  /** @return array<array-key,WorldAbility> */
  public function getAbilities(): array { return $this->abilities; }

  public bool $hasFirstUsePrompts {
    get => array_any($this->abilities, static fn(WorldAbility $ability): bool => $ability->firstUse !== null);
  }

  public function get(string $path, mixed $default = null): mixed { return $this->abilities[$path] ?? $default; }
  public function has(string $path): bool { return isset($this->abilities[$path]); }
  public function set(string $path, mixed $value): void
  {
    throw new LogicException('World abilities are immutable project source; use source-preserving authoring.');
  }
  public function persist(): void
  {
    throw new LogicException('Runtime world-ability catalogs do not write project source.');
  }

  /** @return array<array-key,WorldAbility> Current acquired powers in authored order, without changing world facts. */
  public function getUnlockedAbilities(GameState $state, ?Party $party = null, ?QuestLog $quests = null): array
  {
    $this->queryDepth++;
    try {
      return array_filter($this->abilities, fn(WorldAbility $ability): bool => $this->isUnlocked($ability->id, $state, $party, $quests));
    } finally {
      if (--$this->queryDepth === 0) { $this->queryResults = []; }
    }
  }

  public function isUnlocked(string $id, GameState $state, ?Party $party = null, ?QuestLog $quests = null): bool
  {
    $ability = $this->getAbility($id);
    $this->queryDepth++;
    try {
      return $this->queryResults[$id] ??= WorldConditionEvaluator::allHold($ability->conditions, $state, $party, $quests, $this);
    } finally {
      if (--$this->queryDepth === 0) { $this->queryResults = []; }
    }
  }

  /** Consumer conditions are a conjunction: only positive power requirements explain a usable feature.
   * @return list<WorldAbility>
   */
  public function getRequiredAbilities(array $conditions): array
  {
    $selected = [];
    foreach ($conditions as $condition) {
      if (trim($condition['type'] ?? '') !== WorldConditionType::WORLD_ABILITY->value
        || ($condition['negate'] ?? false)) { continue; }
      $ability = $this->getAbility(trim($condition['name']));
      $selected[$ability->id] = $ability;
    }
    return array_values($selected);
  }

  /** @param array<array-key,true> $active @param array<array-key,true> $visited */
  private function validateDependencies(string $id, array &$active, array &$visited): void
  {
    if (isset($active[$id])) {
      throw new InvalidArgumentException('World ability dependency cycle: ' . implode(' -> ', [...array_keys($active), $id]) . '.');
    }
    if (isset($visited[$id])) { return; }
    $ability = $this->getAbility($id); $active[$id] = true;
    foreach ($ability->conditions as $condition) {
      if (trim($condition['type']) === WorldConditionType::WORLD_ABILITY->value) {
        $this->validateDependencies(trim($condition['name']), $active, $visited);
      }
    }
    unset($active[$id]); $visited[$id] = true;
  }
}
