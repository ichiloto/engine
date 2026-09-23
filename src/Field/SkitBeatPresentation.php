<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Util\Debug;

/** Optional presentation never invalidates a skit's authored dialogue. */
final readonly class SkitBeatPresentation
{
  public function __construct(public string $emotion, public ?string $voicePath) {}

  public static function getFromBeat(string $assets, string $skitId, array $beat, DialoguePresentationCatalog $catalogue): self
  {
    $speaker = is_string($beat['speaker'] ?? null) ? $beat['speaker'] : '';
    $emotion = $beat['emotion'] ?? 'Neutral';
    $emotions = $catalogue->actors[$speaker]['emotions'] ?? [];
    if (! is_string($emotion) || ($emotion !== 'Neutral' && (! is_array($emotions) || ! array_key_exists($emotion, $emotions)))) {
      Debug::warn("Unknown skit emotion for $speaker in $skitId; using Neutral.");
      $emotion = 'Neutral';
    }

    $voice = $beat['voice'] ?? null;
    if ($voice === null || $voice === '') {
      return new self($emotion, null);
    }
    if (! is_string($voice) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9 _.-]*\z/D', $voice)
      || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/D', $skitId)
      || (pathinfo($voice, PATHINFO_EXTENSION) !== '' && strtolower(pathinfo($voice, PATHINFO_EXTENSION)) !== 'mp3')) {
      Debug::warn("Invalid voice reference in skit $skitId; continuing without voice.");
      return new self($emotion, null);
    }
    $root = realpath($assets . '/Audio/Voice/Skits');
    $name = pathinfo($voice, PATHINFO_EXTENSION) === '' ? "$voice.mp3" : $voice;
    $path = realpath($assets . '/Audio/Voice/Skits/' . $skitId . '/' . $name);
    if ($root === false || $path === false || ! is_file($path) || ! is_readable($path)
      || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR . $skitId . DIRECTORY_SEPARATOR)) {
      Debug::warn("Voice file missing or outside its skit directory: $skitId/$name; continuing without voice.");
      return new self($emotion, null);
    }
    return new self($emotion, $path);
  }
}
