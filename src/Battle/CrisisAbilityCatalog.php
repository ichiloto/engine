<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\WorldConditionExclusion;
use InvalidArgumentException;

/** Shared crisis definitions are project resources referenced by actors. Saves retain mutable actor state. */
final class CrisisAbilityCatalog
{
  public const string FILE = 'Data/crisis-abilities.php';
  /** @var array<string, CrisisAbility> */
  private array $abilities = [];

  public function __construct(array $entries = [])
  {
    if (!array_is_list($entries)) { throw new InvalidArgumentException('Crisis abilities must be a list.'); }
    foreach ($entries as $entry) {
      if (!is_array($entry) || !is_string($entry['id'] ?? null)
        || trim($entry['id']) === '' || $entry['id'] !== trim($entry['id'])) {
        throw new InvalidArgumentException('Each crisis ability requires an explicit stable id.');
      }
      $id = $entry['id'];
      if (isset($this->abilities[$id])) { throw new InvalidArgumentException(sprintf('Duplicate crisis ability "%s".', $id)); }
      unset($entry['id']);
      $ability = CrisisAbility::fromArray($entry);
      if ($ability !== null) { $this->abilities[$id] = $ability; }
    }
  }

  public static function fromProject(?string $assetRoot = null): self
  {
    $filename = Path::join($assetRoot ?? Path::join(Path::getCurrentWorkingDirectory(), 'assets'), self::FILE);
    if (!is_file($filename)) { return new self(); }
    $entries = require $filename;
    if (!is_array($entries)) { throw new InvalidArgumentException(sprintf('%s must return a list.', $filename)); }
    return new self($entries);
  }

  public function getAbility(string $id): CrisisAbility
  {
    return $this->abilities[$id] ?? throw new InvalidArgumentException(sprintf('Unknown crisis ability "%s".', $id));
  }

  /** @return array<string, CrisisAbility> */
  public function getAbilities(): array
  {
    return $this->abilities;
  }

  /** Checks variant conditions that cannot distinguish techniques during authoring. */
  public function validateVariantConditions(): void
  {
    foreach ($this->abilities as $id => $ability) {
      if (count($ability->variants) < 2) { continue; }

      $seen = [];
      foreach ($ability->variants as $index => $variant) {
        if ($variant['conditions'] === []) {
          throw new InvalidArgumentException(sprintf(
            'Crisis "%s" variant %d requires conditions when the ability has multiple variants.', $id, $index + 1,
          ));
        }

        $key = WorldConditionExclusion::getConjunctionKey($variant['conditions']);
        if (isset($seen[$key])) {
          throw new InvalidArgumentException(sprintf(
            'Crisis "%s" variants %d and %d have identical conditions.', $id, $seen[$key], $index + 1,
          ));
        }
        $seen[$key] = $index + 1;
      }
    }
  }

  public static function requireReference(mixed $reference): ?string
  {
    if ($reference === null) { return null; }
    if (!is_string($reference) || trim($reference) === '' || trim($reference) !== $reference) {
      throw new InvalidArgumentException('Actor crisisAbility must reference a crisis ability id.');
    }
    return $reference;
  }
}
