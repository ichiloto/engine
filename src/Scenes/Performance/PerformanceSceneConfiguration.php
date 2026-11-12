<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Performance;

use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use InvalidArgumentException;

final class PerformanceSceneConfiguration implements SceneConfigurationInterface
{
  public private(set) string $title;
  /** @var non-empty-list<PerformancePhrase> */
  public private(set) array $phrases;
  public private(set) ?string $accompaniment;

  /** @param non-empty-list<PerformancePhrase> $phrases */
  public function __construct(string $title, array $phrases, ?string $accompaniment = null)
  {
    if (trim($title) === '' || ($accompaniment !== null && trim($accompaniment) === '')
      || $phrases === [] || !array_is_list($phrases)
      || !array_all($phrases, static fn(mixed $phrase): bool => $phrase instanceof PerformancePhrase)) {
      throw new InvalidArgumentException('A performance requires a title, ordered phrases and an optional sound reference.');
    }
    $this->title = $title;
    $this->phrases = $phrases;
    $this->accompaniment = $accompaniment;
  }

  public function __toString(): string { return $this->title; }

  public function serialize(): string { return serialize($this->__serialize()); }

  public function unserialize(string $data): void
  {
    $record = unserialize($data, ['allowed_classes' => false]);
    if (!is_array($record)) { throw new InvalidArgumentException('Invalid performance record.'); }
    $this->__unserialize($record);
  }

  public function __serialize(): array
  {
    return [
      'title' => $this->title,
      'phrases' => array_map(static fn(PerformancePhrase $phrase): array => [
        'prompt' => $phrase->prompt, 'choices' => $phrase->choices, 'matchingChoice' => $phrase->matchingChoice,
      ], $this->phrases),
      'accompaniment' => $this->accompaniment,
    ];
  }

  public function __unserialize(array $data): void
  {
    $title = $data['title'] ?? null;
    $phrases = $data['phrases'] ?? null;
    $accompaniment = $data['accompaniment'] ?? null;
    if (!is_string($title) || !is_array($phrases) || ($accompaniment !== null && !is_string($accompaniment))) {
      throw new InvalidArgumentException('Invalid performance record.');
    }
    $this->__construct($title, array_map(static function (mixed $phrase): PerformancePhrase {
      if (!is_array($phrase) || !is_string($phrase['prompt'] ?? null)
        || !is_array($phrase['choices'] ?? null) || !is_int($phrase['matchingChoice'] ?? null)) {
        throw new InvalidArgumentException('Invalid performance phrase record.');
      }
      return new PerformancePhrase($phrase['prompt'], $phrase['choices'], $phrase['matchingChoice']);
    }, $phrases), $accompaniment);
  }

  public function jsonSerialize(): array { return $this->__serialize(); }
}
