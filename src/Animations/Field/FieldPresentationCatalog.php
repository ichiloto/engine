<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Field\WorldObjectResource;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/**
 * Replaceable project field bindings and named world-object resources;
 * no game-specific names or paths in the engine.
 */
final readonly class FieldPresentationCatalog
{
  public const string FILE = 'Data/Presentation/field.php';
  public const array DIRECTIONS = ['east', 'southeast', 'south', 'southwest', 'west', 'northwest', 'north', 'northeast'];
  /** @var array<string, WorldObjectResource> */
  public array $resources;
  /** The game's objective waymark style; the Engine default when not authored. */
  public FieldWaymarkStyle $waymarks;
  /** How every cue mark stays readable; the Engine default when not authored. */
  public FieldCueStyle $cueStyle;

  /**
   * @param array<string, array<string, mixed>> $cues
   * @param string|null $actionPrompt The effect drawn over the player while it can act, or null for its glyph.
   * @param array<string, WorldObjectResource> $resources Immutable reusable presentation definitions.
   * @param FieldWaymarkStyle|null $waymarks The objective waymark style.
   * @param FieldCueStyle|null $cueStyle How every cue mark stays readable.
   */
  public function __construct(public array $cues = [], public ?string $actionPrompt = null, array $resources = [],
    ?FieldWaymarkStyle $waymarks = null, ?FieldCueStyle $cueStyle = null)
  {
    $this->waymarks = $waymarks ?? new FieldWaymarkStyle();
    $this->cueStyle = $cueStyle ?? new FieldCueStyle();
    $copy = [];
    foreach ($resources as $id => $resource) {
      if (!$resource instanceof WorldObjectResource || $resource->id !== $id) {
        throw new InvalidArgumentException('Field resources require typed definitions keyed by their stable id.');
      }
      $copy[$id] = $resource;
    }
    $this->resources = $copy;
  }

  public static function load(string $assetRoot, EffectPresentation $presentation = EffectPresentation::GRAPHICAL): self
  {
    $path = $assetRoot . '/' . self::FILE;
    return is_file($path) ? self::fromArray((static fn(string $file): mixed => require $file)($path), $assetRoot, $presentation) : new self();
  }

  public static function fromArray(mixed $data, string $assetRoot, EffectPresentation $presentation = EffectPresentation::GRAPHICAL): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['cues', 'cueStyle', 'actionPrompt', 'resources', 'waymarks']) !== []
      || !is_array($data['cues'] ?? [])) {
      throw new InvalidArgumentException('Field presentation accepts cues, cueStyle, actionPrompt, named resources and waymarks.');
    }
    $prompt = $data['actionPrompt'] ?? null;
    if ($prompt !== null && (!is_array($prompt) || array_keys($prompt) !== ['effect'] || !is_string($prompt['effect']))) {
      throw new InvalidArgumentException('The action prompt binding needs only an effect identity.');
    }
    if ($prompt !== null) { EffectTimelineLibrary::assertId($prompt['effect']); }
    $cues = $data['cues'] ?? [];
    foreach ($cues as $color => $cue) {
      if (is_array($cue) && array_key_exists('edges', $cue)) {
        throw new InvalidArgumentException('Cue edges are now an upright marker and a turning pointer: replace edges with pointer images.');
      }
      if (!is_string($color) || !is_array($cue) || array_diff(array_keys($cue), ['effect', 'pointer']) !== []
        || !is_string($cue['effect'] ?? null)) {
        throw new InvalidArgumentException('A field cue binding needs an effect identity and optional directional pointer images.');
      }
      EffectTimelineLibrary::assertId($cue['effect']);
      $pointer = $cue['pointer'] ?? [];
      if (!is_array($pointer) || array_diff(array_keys($pointer), self::DIRECTIONS) !== []) {
        throw new InvalidArgumentException('Cue pointer images must be keyed by compass direction.');
      }
      foreach ($pointer as $image) {
        if (!is_array($image) || array_diff(array_keys($image), ['asset', 'quarterTurns']) !== []
          || !is_string($image['asset'] ?? null) || !is_int($image['quarterTurns'] ?? 0)
          || ($image['quarterTurns'] ?? 0) < 0 || ($image['quarterTurns'] ?? 0) > 3) {
          throw new InvalidArgumentException('Cue pointer images need asset and optional quarterTurns 0..3.');
        }
        SpriteValidation::validateAssetPath($image['asset']);
        if ($presentation === EffectPresentation::GRAPHICAL) { PngAssetPreflight::inspect($assetRoot, $image['asset']); }
      }
    }
    $cueStyle = FieldCueStyle::fromArray($data['cueStyle'] ?? null);
    if ($cueStyle->plate !== null && $presentation === EffectPresentation::GRAPHICAL) {
      PngAssetPreflight::inspect($assetRoot, $cueStyle->plate);
    }
    $declarations = array_key_exists('resources', $data) ? $data['resources'] : [];
    if (!is_array($declarations)) { throw new InvalidArgumentException('Field resources must be keyed by stable resource id.'); }
    $resources = [];
    foreach ($declarations as $id => $resource) {
      WorldObjectResource::assertId($id);
      $resources[$id] = WorldObjectResource::fromArray($id, $resource);
    }
    return new self($cues, $prompt['effect'] ?? null, $resources, FieldWaymarkStyle::fromArray($data['waymarks'] ?? null), $cueStyle);
  }

  public function getResource(string $id): WorldObjectResource
  {
    WorldObjectResource::assertId($id);
    return $this->resources[$id] ?? throw new InvalidArgumentException("Unknown field world-object resource '{$id}'.");
  }

  /** Explicit preflight for Editor validation; runtime fails safely with a diagnostic. */
  public function validateEffects(EffectTimelineLibrary $library): void
  {
    foreach ($this->cues as $cue) { $library->load($cue['effect']); }
    if ($this->actionPrompt !== null) { $library->load($this->actionPrompt); }
  }
}
