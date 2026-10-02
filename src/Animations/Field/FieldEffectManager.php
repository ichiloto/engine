<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Events\Triggers\EventCueKind;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use Throwable;

/** Owns concurrent effect sessions for one installed map. No renderer-side clock. */
final class FieldEffectManager
{
  /** The session id of the action prompt, of which there is at most one. */
  public const string ACTION_PROMPT_ID = 'action-prompt';
  private readonly EffectTimelineLibrary $library;
  private FieldPresentationCatalog $catalog;
  /** @var array<string, FieldEffectSession> */
  private array $sessions = [];
  /** @var array<string, EventTrigger> */
  private array $cues = [];
  /** @var array<string, true> */
  private array $notes = [];
  private string $mapId = '';
  private ?bool $supported = null;
  private bool $turnsSupported = false;
  private ?bool $actionPromptUsable = null;

  public function __construct(string $assetRoot, private readonly EffectPresentation $presentation = EffectPresentation::GRAPHICAL)
  {
    $this->library = new EffectTimelineLibrary($assetRoot);
    try { $this->catalog = FieldPresentationCatalog::load($assetRoot, $presentation); }
    catch (Throwable $error) {
      $this->catalog = new FieldPresentationCatalog();
      $this->note('catalog', 'Field cue bindings are unusable; original glyphs remain: ' . $error->getMessage());
    }
  }

  public function setCapabilities(bool $images, bool $quarterTurns): void
  {
    $this->supported = $images;
    $this->turnsSupported = $quarterTurns;
    if (!$images && $this->sessions !== []) {
      $this->note('capability', 'This renderer cannot draw field effects; the original glyphs and tiles remain.');
    }
  }

  /** @param list<EventTrigger> $events */
  public function installMap(string $mapId, mixed $declarations, ?MapGraphics $graphics, array $events): void
  {
    $this->clear();
    $this->mapId = $mapId;
    try { $declarations = self::readDeclarations($declarations); }
    catch (InvalidArgumentException $error) {
      $declarations = [];
      $this->note('declarations', 'Map field effects are unusable; gameplay is unchanged: ' . $error->getMessage());
    }
    foreach ($declarations as $effect) {
      $this->startEffect('map-' . $effect['id'], $effect['effect'], FieldEffectAnchor::fromArray($effect['anchor']));
    }
    foreach ($graphics === null ? [] : FieldPieceEffects::find($graphics) as $effect) {
      $this->startEffect($effect['id'], $effect['effect'], FieldEffectAnchor::fromArray($effect['anchor']));
    }
    foreach ($events as $event) {
      if ($event->cue === null) { continue; }
      $id = self::getCueId($event);
      $this->cues[$id] = $event;
      $binding = $this->catalog->cues[$event->cue->color] ?? null;
      if ($binding === null) {
        $this->note('binding:' . $event->cue->color, 'No graphical binding for cue color ' . $event->cue->color . '; its original glyph remains.');
        continue;
      }
      $cell = $event->cue->positionFor($event->area);
      $this->startEffect($id, $binding['effect'], FieldEffectAnchor::fromArray(['cell' => ['x' => (int)$cell->x, 'y' => (int)$cell->y]]));
    }
  }

  /** @return list<array{id: string, effect: string, anchor: array<string, mixed>}> */
  public static function readDeclarations(mixed $declarations): array
  {
    if ($declarations === null) { return []; }
    if (!is_array($declarations) || !array_is_list($declarations) || count($declarations) > 256) {
      throw new InvalidArgumentException('fieldEffects must be a list of at most 256 map-owned effects.');
    }
    $ids = [];
    foreach ($declarations as $declaration) {
      if (!is_array($declaration) || array_diff(array_keys($declaration), ['id', 'effect', 'anchor']) !== []
        || !is_string($declaration['id'] ?? null) || !is_string($declaration['effect'] ?? null)) {
        throw new InvalidArgumentException('A map effect needs id, effect and anchor.');
      }
      EffectTimelineLibrary::assertId($declaration['id']);
      EffectTimelineLibrary::assertId($declaration['effect']);
      if (isset($ids[$declaration['id']])) { throw new InvalidArgumentException('Map effect ids must be unique.'); }
      $ids[$declaration['id']] = true;
      FieldEffectAnchor::fromArray($declaration['anchor'] ?? null);
    }
    return $declarations;
  }

  public static function getCueId(EventTrigger $event): string { return 'cue-' . ($event->marker ?? ''); }

  public function canPresentCue(EventTrigger $event): bool
  {
    $session = $this->sessions[self::getCueId($event)] ?? null;
    return $this->supported && $this->presentation === EffectPresentation::GRAPHICAL && $session !== null
      && array_any($session->playback->timeline->playbackSegments, static fn(array $segment): bool => $segment['layer'] === 'image');
  }

  /**
   * Whether the bound action prompt effect draws the prompt instead of its
   * glyph: the game binds one, its timeline loads and this renderer draws
   * field effects. An unusable binding is noted once and the glyph remains.
   */
  public function canPresentActionPrompt(): bool
  {
    if (!$this->supported || $this->presentation !== EffectPresentation::GRAPHICAL || $this->catalog->actionPrompt === null) { return false; }
    if ($this->actionPromptUsable === null) {
      try {
        $timeline = $this->library->load($this->catalog->actionPrompt, presentation: $this->presentation);
        $this->actionPromptUsable = array_any($timeline->playbackSegments,
          static fn(array $segment): bool => $segment['layer'] === 'image');
        if (!$this->actionPromptUsable) {
          $this->note('prompt', 'Action prompt effect has no graphical image; the prompt glyph remains.');
        }
      } catch (Throwable $error) {
        $this->actionPromptUsable = false;
        $this->note('prompt', "Action prompt effect {$this->catalog->actionPrompt} is unusable; the prompt glyph remains: "
          . $error->getMessage());
      }
    }
    return $this->actionPromptUsable;
  }

  /**
   * Shows the action prompt over a field object, or hides it given null.
   * Showing it over the object it already marks keeps its playback running,
   * so it opens once each time the object comes to be able to act and then
   * idles, however often the object is redrawn.
   */
  public function showActionPrompt(?string $objectId): void
  {
    if ($objectId === null || $this->catalog->actionPrompt === null) {
      $this->removeEffect(self::ACTION_PROMPT_ID);
      return;
    }
    if (($this->sessions[self::ACTION_PROMPT_ID] ?? null)?->anchor->objectId !== $objectId) {
      $this->startEffect(self::ACTION_PROMPT_ID, $this->catalog->actionPrompt, FieldEffectAnchor::fromArray(['object' => $objectId]));
    }
  }

  public function startEffect(string $id, string $effect, FieldEffectAnchor $anchor): void
  {
    try {
      $timeline = $this->library->load($effect, presentation: $this->presentation);
      if (isset($this->sessions[$id])) { $this->sessions[$id]->playback->pause(); }
      $this->sessions[$id] = new FieldEffectSession('field-effect:' . $this->mapId . ':' . $id, $anchor,
        $timeline);
      if ($this->supported === false) {
        $this->note('capability', 'This renderer cannot draw field effects; the original glyphs and tiles remain.');
      }
    } catch (Throwable $error) {
      $this->note('effect:' . $effect, "Field effect {$effect} is unusable; its original glyph or tile remains: " . $error->getMessage());
    }
  }

  public function removeEffect(string $id): void
  {
    if (isset($this->sessions[$id])) { $this->sessions[$id]->playback->pause(); }
    unset($this->sessions[$id], $this->cues[$id]);
  }
  public function clear(): void
  {
    foreach (array_keys($this->sessions) as $id) { $this->removeEffect($id); }
    $this->cues = []; $this->mapId = '';
  }
  public int $count { get => count($this->sessions); }

  /** @param iterable<GraphicalSpriteProviderInterface>|null $objects */
  public function update(float $seconds, bool $reducedMotion, ?iterable $objects = null): void
  {
    if ($objects !== null) { $this->reconcileObjects($objects); }
    foreach ($this->sessions as $id => $session) {
      if (isset($this->cues[$id]) && !$this->cues[$id]->shouldRenderCue()) { continue; }
      FieldEffectSession::playCues($session->update($seconds, $reducedMotion)->crossedCues);
      if ($session->playback->isCompleted) { unset($this->sessions[$id]); }
    }
  }

  /** @param iterable<GraphicalSpriteProviderInterface> $objects */
  public function renderText(Camera $camera, iterable $objects, bool $reducedMotion): void
  {
    $indexed = $this->reconcileObjects($objects);
    foreach ($this->sessions as $id => $session) {
      // Prompt and cue glyphs already have their authoritative terminal presenters.
      if ($id === self::ACTION_PROMPT_ID || isset($this->cues[$id])) { continue; }
      $position = $session->anchor->objectId === null ? $session->anchor->cell
        : ($indexed[$session->anchor->objectId] ?? null)?->getGraphicalSpriteWorldPosition();
      if ($position === null) { continue; }
      Console::withLayer($session->id, fn() => $session->renderText($camera,
        $camera->getScreenSpacePosition($position), $this->presentation, $reducedMotion),
        PresentationLayerPolicy::FIELD_EFFECT_FRONT);
    }
  }

  /** @param iterable<GraphicalSpriteProviderInterface> $objects @param array{x: int, y: int} $origin @return list<FieldEffectSprite> */
  public function getSprites(iterable $objects, ?FieldViewport $viewport, array $origin, bool $reducedMotion): array
  {
    $indexed = $this->reconcileObjects($objects);
    $sprites = [];
    foreach ($this->sessions as $id => $session) {
      $object = $session->anchor->objectId === null ? null : ($indexed[$session->anchor->objectId] ?? null);
      if ($session->anchor->objectId !== null && $object === null) { $this->removeEffect($id); continue; }
      if (!$this->supported) { continue; }
      $cue = $this->cues[$id] ?? null;
      if ($cue !== null && !$cue->shouldRenderCue()) { continue; }
      $position = $object?->getGraphicalSpriteWorldPosition() ?? $session->anchor->cell;
      if ($position === null) { continue; }
      if ($cue?->cue?->kind === EventCueKind::STORY && $viewport !== null
        && ($edge = FieldCueEdgePlacement::locate($position, $origin, $viewport)) !== null) {
        $image = $this->catalog->cues[$cue->cue->color]['edges'][$edge['direction']] ?? null;
        if ($image === null) {
          $this->note('edge:' . $cue->cue->color, 'A story cue has no directional edge image; its cue art remains at its cell.');
        } elseif (($image['quarterTurns'] ?? 0) > 0 && !$this->turnsSupported) {
          $this->note('turns', 'This renderer cannot rotate field edge images; cue art remains at its cell.');
        } else {
          $size = (int)round(FieldViewport::TILE_SIZE * $viewport->zoom);
          $sprites[] = new FieldEdgeSprite($session->id . ':edge', new GraphicalSpriteDefinition($image['asset'], $size, $size,
            PresentationSpriteAnchor::BOTTOM_CENTER, PresentationLayerPolicy::FIELD_EFFECT_FRONT,
            quarterTurns: $image['quarterTurns'] ?? 0), $edge['position']);
          continue;
        }
      }
      array_push($sprites, ...$session->getSprites($position, $reducedMotion, $object?->getGraphicalSpriteMotion()));
    }
    return $sprites;
  }

  private function note(string $id, string $message): void
  {
    if (!isset($this->notes[$id])) { $this->notes[$id] = true; Debug::warn($message); }
  }

  /** @param iterable<GraphicalSpriteProviderInterface> $objects @return array<string, GraphicalSpriteProviderInterface> */
  private function reconcileObjects(iterable $objects): array
  {
    $indexed = [];
    foreach ($objects as $object) { $indexed[$object->getGraphicalSpriteId()] = $object; }
    foreach ($this->sessions as $id => $session) {
      if ($session->anchor->objectId !== null && !isset($indexed[$session->anchor->objectId])) {
        $this->removeEffect($id);
      }
    }
    return $indexed;
  }
}
