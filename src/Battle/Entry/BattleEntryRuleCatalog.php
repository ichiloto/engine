<?php

namespace Ichiloto\Engine\Battle\Entry;

use Assegai\Util\Path;
use Ichiloto\Engine\Battle\BattleClassification;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldStateWriter;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats\StatKey;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use Ichiloto\Engine\Util\Stores\ActorStore;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Validated project-owned battle-entry definitions. */
final class BattleEntryRuleCatalog implements ConfigInterface
{
  /** @var array<string, BattleEntryRule> */
  private array $rulesById = [];
  /** @var BattleEntryRule[] */
  private array $orderedRules = [];

  /** @param array<string, mixed>|array<int, mixed> $data */
  public function __construct(
    array $data = [],
    public readonly string $source = 'battle-entry rules',
    ?ActorStore $actorStore = null,
  )
  {
    $entries = array_key_exists('rules', $data) ? $data['rules'] : $data;

    if (! is_array($entries) || ! array_is_list($entries)) {
      throw new InvalidArgumentException(sprintf('%s field "rules" must be a list.', $source));
    }

    foreach ($entries as $index => $entry) {
      if (! is_array($entry)) {
        throw new InvalidArgumentException(sprintf('%s rule at position %d must be an array.', $source, $index));
      }

      $rule = $this->hydrateRule($entry, $index, $actorStore);

      if (isset($this->rulesById[$rule->id])) {
        throw new InvalidArgumentException(sprintf(
          '%s rule "%s" duplicates a stable rule ID.',
          $source,
          $rule->id,
        ));
      }

      $this->rulesById[$rule->id] = $rule;
      $this->orderedRules[] = $rule;
    }

    usort(
      $this->orderedRules,
      static fn(BattleEntryRule $left, BattleEntryRule $right): int =>
        [$left->priority, $left->declarationOrder] <=> [$right->priority, $right->declarationOrder],
    );
  }

  public static function fromProject(?string $filename = null, ?ActorStore $actorStore = null): self
  {
    $filename ??= Path::join(
      Path::getCurrentWorkingDirectory(),
      'assets',
      'Data',
      'battle-entry-rules.php',
    );

    if (! is_file($filename)) {
      return new self([], $filename, $actorStore);
    }

    try {
      $data = require $filename;
    } catch (Throwable $exception) {
      throw new RuntimeException(sprintf(
        'Battle-entry rule file %s could not be loaded: %s',
        $filename,
        $exception->getMessage(),
      ), previous: $exception);
    }

    if (! is_array($data)) {
      throw new InvalidArgumentException(sprintf('Battle-entry rule file %s must return an array.', $filename));
    }

    return new self($data, $filename, $actorStore);
  }

  public static function empty(): self
  {
    return new self();
  }

  /** @return BattleEntryRule[] */
  public function rules(): array
  {
    return $this->orderedRules;
  }

  public function get(string $path, mixed $default = null): mixed
  {
    return $this->rulesById[trim($path)] ?? $default;
  }

  public function set(string $path, mixed $value): void
  {
    throw new InvalidArgumentException('Battle-entry rule definitions are project-authored and immutable at runtime.');
  }

  public function has(string $path): bool
  {
    return isset($this->rulesById[trim($path)]);
  }

  public function persist(): void
  {
  }

  /** @param array<string, mixed> $entry */
  private function hydrateRule(array $entry, int $index, ?ActorStore $actorStore): BattleEntryRule
  {
    $positionSource = sprintf('%s rule at position %d', $this->source, $index);
    $id = trim(strval($entry['id'] ?? ''));

    if ($id === '') {
      throw new InvalidArgumentException(sprintf('%s field "id" must be a non-empty stable identity.', $positionSource));
    }

    $ruleSource = sprintf('%s rule "%s"', $this->source, $id);
    $priority = $entry['priority'] ?? 0;
    if (! is_int($priority)) {
      throw new InvalidArgumentException(sprintf('%s field "priority" must be an integer.', $ruleSource));
    }

    $classification = BattleClassification::resolve($entry['classification'] ?? null, $ruleSource);
    $conditions = $entry['conditions'] ?? [];
    if (! is_array($conditions) || ! array_is_list($conditions)) {
      throw new InvalidArgumentException(sprintf('%s field "conditions" must be a list.', $ruleSource));
    }
    WorldConditionEvaluator::validateAll($conditions, $ruleSource . ' field "conditions"');

    $actorEntries = $entry['actors'] ?? null;
    if (! is_array($actorEntries) || ! array_is_list($actorEntries) || $actorEntries === []) {
      throw new InvalidArgumentException(sprintf('%s field "actors" must be a non-empty list.', $ruleSource));
    }
    $actors = [];
    foreach ($actorEntries as $actorIndex => $actorEntry) {
      $actorSource = sprintf('%s field "actors[%d]"', $ruleSource, $actorIndex);
      if (! is_array($actorEntry)) {
        throw new InvalidArgumentException(sprintf('%s must be an array.', $actorSource));
      }

      $actorId = $this->actorId($actorEntry['actor'] ?? null, $actorSource, $actorStore);
      $actors[] = new BattleEntryActorPredicate(
        $actorId,
        BattleEntryActorPresence::require($actorEntry['presence'] ?? null, $actorSource),
      );
    }

    $effectEntries = $entry['effects'] ?? null;
    if (! is_array($effectEntries) || ! array_is_list($effectEntries) || $effectEntries === []) {
      throw new InvalidArgumentException(sprintf('%s field "effects" must be a non-empty list.', $ruleSource));
    }
    $effects = [];
    foreach ($effectEntries as $effectIndex => $effectEntry) {
      $effectSource = sprintf('%s field "effects[%d]"', $ruleSource, $effectIndex);
      if (! is_array($effectEntry)) {
        throw new InvalidArgumentException(sprintf('%s must be an array.', $effectSource));
      }

      if (($effectEntry['type'] ?? null) !== 'stat_stage') {
        throw new InvalidArgumentException(sprintf(
          '%s field "type" has unsupported effect "%s"; expected "stat_stage".',
          $effectSource,
          strval($effectEntry['type'] ?? ''),
        ));
      }

      $actorId = $this->actorId($effectEntry['actor'] ?? null, $effectSource, $actorStore);
      try {
        $stat = StatKey::require(strval($effectEntry['stat'] ?? ''));
      } catch (InvalidArgumentException $exception) {
        throw new InvalidArgumentException(sprintf(
          '%s field "stat" is invalid: %s',
          $effectSource,
          $exception->getMessage(),
        ), previous: $exception);
      }

      if (! in_array($stat->value, Character::buffableStats(), true)) {
        throw new InvalidArgumentException(sprintf(
          '%s field "stat" references "%s", which does not support temporary battle stages.',
          $effectSource,
          $stat->value,
        ));
      }

      $delta = $effectEntry['delta'] ?? null;
      if (! is_int($delta)) {
        throw new InvalidArgumentException(sprintf('%s field "delta" must be a signed integer.', $effectSource));
      }

      $effects[] = new BattleEntryStatStageEffect($actorId, $stat, $delta);
    }

    $writes = $entry['writes'] ?? [];
    if (! is_array($writes) || ! array_is_list($writes)) {
      throw new InvalidArgumentException(sprintf('%s field "writes" must be a list.', $ruleSource));
    }
    WorldStateWriter::validateAll($writes, $ruleSource . ' field "writes"', transactional: true);

    return new BattleEntryRule(
      $id,
      $priority,
      $index,
      $classification,
      $actors,
      $conditions,
      $effects,
      $writes,
      $ruleSource,
    );
  }

  private function actorId(mixed $value, string $source, ?ActorStore $actorStore): string
  {
    if (! is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException(sprintf('%s field "actor" must be a non-empty stable actor identity.', $source));
    }

    $actorId = trim($value);
    if ($actorStore instanceof ActorStore) {
      $canonicalId = $actorStore->canonicalId($actorId);
      if ($canonicalId === null) {
        throw new InvalidArgumentException(sprintf(
          '%s field "actor" references unknown actor "%s".',
          $source,
          $actorId,
        ));
      }

      return $canonicalId;
    }

    return $actorId;
  }
}
