<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Assegai\Util\Path;
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

  public static function requireReference(mixed $reference): ?string
  {
    if ($reference === null) { return null; }
    if (!is_string($reference) || trim($reference) === '' || trim($reference) !== $reference) {
      throw new InvalidArgumentException('Actor crisisAbility must reference a crisis ability id.');
    }
    return $reference;
  }
}
