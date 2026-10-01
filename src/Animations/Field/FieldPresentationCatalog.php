<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Replaceable project cue bindings; no game-specific names or paths in the engine. */
final readonly class FieldPresentationCatalog
{
  public const string FILE = 'Data/Presentation/field.php';
  public const array DIRECTIONS = ['east', 'southeast', 'south', 'southwest', 'west', 'northwest', 'north', 'northeast'];

  /** @param array<string, array<string, mixed>> $cues */
  public function __construct(public array $cues = []) {}

  public static function load(string $assetRoot): self
  {
    $path = $assetRoot . '/' . self::FILE;
    return is_file($path) ? self::fromArray((static fn(string $file): mixed => require $file)($path), $assetRoot) : new self();
  }

  public static function fromArray(mixed $data, string $assetRoot): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['cues']) !== [] || !is_array($data['cues'] ?? [])) {
      throw new InvalidArgumentException('Field presentation accepts cue bindings keyed by terminal color.');
    }
    $cues = $data['cues'] ?? [];
    foreach ($cues as $color => $cue) {
      if (!is_string($color) || !is_array($cue) || array_diff(array_keys($cue), ['effect', 'edges']) !== []
        || !is_string($cue['effect'] ?? null)) {
        throw new InvalidArgumentException('A field cue binding needs an effect identity and optional directional edges.');
      }
      EffectTimelineLibrary::assertId($cue['effect']);
      $edges = $cue['edges'] ?? [];
      if (!is_array($edges) || array_diff(array_keys($edges), self::DIRECTIONS) !== []) {
        throw new InvalidArgumentException('Cue edges must be keyed by compass direction.');
      }
      foreach ($edges as $image) {
        if (!is_array($image) || array_diff(array_keys($image), ['asset', 'quarterTurns']) !== []
          || !is_string($image['asset'] ?? null) || !is_int($image['quarterTurns'] ?? 0)
          || ($image['quarterTurns'] ?? 0) < 0 || ($image['quarterTurns'] ?? 0) > 3) {
          throw new InvalidArgumentException('Cue edge images need asset and optional quarterTurns 0..3.');
        }
        SpriteValidation::validateAssetPath($image['asset']);
        PngAssetPreflight::inspect($assetRoot, $image['asset']);
      }
    }
    return new self($cues);
  }

  /** Explicit preflight for Editor validation; runtime fails safely with a diagnostic. */
  public function validateEffects(EffectTimelineLibrary $library): void
  {
    foreach ($this->cues as $cue) { $library->load($cue['effect']); }
  }
}
