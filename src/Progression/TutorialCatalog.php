<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use InvalidArgumentException;

/** The project source shared by runtime and tutorial authoring. */
final readonly class TutorialCatalog
{
  public const string FILE = 'Data/tutorials.php';

  /** @var array<array-key, TutorialDefinition> */
  public array $definitions;

  public function __construct(array $entries = [])
  {
    if (!array_is_list($entries)) {
      throw new InvalidArgumentException('Tutorial definitions must be a list.');
    }

    $definitions = [];
    foreach ($entries as $entry) {
      $definition = TutorialDefinition::fromArray($entry);
      if (isset($definitions[$definition->id])) {
        throw new InvalidArgumentException('Duplicate tutorial id ' . $definition->id . '.');
      }
      $definitions[$definition->id] = $definition;
    }

    $this->definitions = $definitions;
  }

  public static function fromProject(string $assetRoot): self
  {
    $file = rtrim($assetRoot, '/\\') . '/' . self::FILE;
    if (!is_file($file)) {
      return new self();
    }
    $entries = require $file;
    if (!is_array($entries)) {
      throw new InvalidArgumentException($file . ' must return a list of tutorial definitions.');
    }

    try {
      return new self($entries);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException($file . ': ' . $error->getMessage(), previous: $error);
    }
  }

  public function findTutorial(string $id): ?TutorialDefinition
  {
    return $this->definitions[$id] ?? null;
  }
}
