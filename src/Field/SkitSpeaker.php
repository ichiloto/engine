<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Util\Stores\ActorStore;

/** Shared runtime and authoring resolution; display aliases never become identity. */
final readonly class SkitSpeaker
{
  /** @param string[] $notices @param string[] $errors */
  public function __construct(
    public ?string $actorId,
    public string $name,
    public array $notices = [],
    public array $errors = [],
  ) {}

  public static function getFromBeat(array $beat, ActorStore $actors): self
  {
    if (array_key_exists('actor', $beat)) {
      if (array_key_exists('speaker', $beat)) {
        return new self(null, '', errors: ['A skit beat must use either actor or speaker, not both.']);
      }
      $id = $beat['actor'];
      $definition = is_string($id) ? $actors->get($id) : null;
      if ($definition === null || $definition->id !== $id) {
        return new self(null, '', errors: ['Skit actor must match a registered stable actor id exactly.']);
      }
      return new self($definition->id, strval($definition->data()['name'] ?? $definition->id));
    }
    $name = $beat['speaker'] ?? '';
    if (! is_string($name)) {
      return new self(null, '', errors: ['Skit speaker must be display text.']);
    }
    $definition = $actors->get($name);
    if ($definition !== null && $definition->id === $name) {
      return new self($definition->id, strval($definition->data()['name'] ?? $definition->id),
        notices: ['Deprecated skit speaker actor reference; select this identity through actor instead.']);
    }
    return new self(null, $name);
  }
}
