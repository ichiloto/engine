<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Waymarks\FieldWaymark;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteBob;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
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
  /** @var array<string, FieldWaymark> Waymark sessions keyed by session id. */
  private array $waymarks = [];
  /** @var array<string, Npc> NPC cue sessions keyed by session id. */
  private array $npcCues = [];
  private ?bool $waymarksUsable = null;
  /** Presentation seconds, for the cue bob; advanced only by update(). */
  private float $clock = 0.0;
  /** @var array<string, true> */
  private array $notes = [];
  private string $mapId = '';
  private ?bool $supported = null;
  private bool $turnsSupported = false;
  /** Whether the renderer draws a declared bob at display rate; otherwise the bob is a lift sent each frame. */
  private bool $bobsDeclared = false;
  /** Whether the renderer slides screen-pinned sprites, so edge markers carry the camera's motion. */
  private bool $pinnedMotion = false;
  private ?bool $actionPromptUsable = null;
  private ?Vector2 $viewerPosition = null;

  public function __construct(string $assetRoot, private readonly EffectPresentation $presentation = EffectPresentation::GRAPHICAL)
  {
    $this->library = new EffectTimelineLibrary($assetRoot);
    try { $this->catalog = FieldPresentationCatalog::load($assetRoot, $presentation); }
    catch (Throwable $error) {
      $this->catalog = new FieldPresentationCatalog();
      $this->note('catalog', 'Field cue bindings are unusable; original glyphs remain: ' . $error->getMessage());
    }
  }

  /**
   * @param bool $bob The renderer draws a declared bob ({@see PresentationSpriteBob}).
   * @param bool $pinnedMotion The renderer slides screen-pinned sprites with a motion.
   */
  public function setCapabilities(bool $images, bool $quarterTurns, bool $bob = false, bool $pinnedMotion = false): void
  {
    $this->supported = $images;
    $this->turnsSupported = $quarterTurns;
    $this->bobsDeclared = $bob;
    $this->pinnedMotion = $pinnedMotion;
    $this->waymarksUsable = null;
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
      $binding = $this->catalog->cues[$event->cue->color] ?? null;
      if ($binding === null) {
        $this->note('binding:' . $event->cue->color, 'No graphical binding for cue color ' . $event->cue->color . '; its original glyph remains.');
        continue;
      }
      // One cue per separate placement of the trigger, as the terminal shows it.
      foreach (array_combine(self::getCueIds($event), $event->cue->findPositions($event->area)) as $id => $cell) {
        $this->cues[$id] = $event;
        $this->startEffect($id, $binding['effect'], FieldEffectAnchor::fromArray(['cell' => ['x' => (int)$cell->x, 'y' => (int)$cell->y]]));
      }
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

  /**
   * The cue session ids of an event, one per separate placement of its
   * trigger: `cue-<marker>` for the first, then `cue-<marker>-2` and on.
   *
   * @return list<string>
   */
  public static function getCueIds(EventTrigger $event): array
  {
    $base = 'cue-' . ($event->marker ?? '');
    $count = $event->cue === null ? 1 : count($event->cue->findPositions($event->area));

    return array_map(static fn(int $index): string => $index === 0 ? $base : $base . '-' . ($index + 1), range(0, max(1, $count) - 1));
  }

  /** Whether graphical cue art draws every placement's cue, so its glyphs stand down. */
  public function canPresentCue(EventTrigger $event): bool
  {
    if (!$this->supported || $this->presentation !== EffectPresentation::GRAPHICAL) { return false; }
    foreach (self::getCueIds($event) as $id) {
      $session = $this->sessions[$id] ?? null;
      if ($session === null
        || !array_any($session->playback->timeline->playbackSegments, static fn(array $segment): bool => $segment['layer'] === 'image')) {
        return false;
      }
    }
    return true;
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

  /** The game's objective waymark style, shared with the terminal presenter. */
  public FieldWaymarkStyle $waymarkStyle { get => $this->catalog->waymarks; }

  /** How every cue mark stays readable, shared with the terminal presenters. */
  public FieldCueStyle $cueStyle { get => $this->catalog->cueStyle; }

  /**
   * Whether graphical art draws every waymark: the cue binding of the
   * waymark colour has an image effect and all eight pointer images this
   * renderer can draw. Otherwise the terminal glyphs remain the waymarks.
   */
  public function canPresentWaymarks(): bool
  {
    if (!$this->supported || $this->presentation !== EffectPresentation::GRAPHICAL) { return false; }
    if ($this->waymarksUsable !== null) { return $this->waymarksUsable; }
    $color = $this->catalog->waymarks->color;
    $binding = $this->catalog->cues[$color] ?? null;
    if ($binding === null) {
      $this->note('waymark-binding', "No graphical cue binding for waymark color {$color}; waymark glyphs remain.");
      return $this->waymarksUsable = false;
    }
    foreach (FieldPresentationCatalog::DIRECTIONS as $direction) {
      $image = $binding['pointer'][$direction] ?? null;
      if ($image === null) {
        $this->note('waymark-edges', "The {$color} cue binding has no {$direction} pointer image; waymark glyphs remain.");
        return $this->waymarksUsable = false;
      }
      if (($image['quarterTurns'] ?? 0) > 0 && !$this->turnsSupported) {
        $this->note('turns', 'This renderer cannot rotate field pointer images; waymark glyphs remain.');
        return $this->waymarksUsable = false;
      }
    }
    try {
      $timeline = $this->library->load($binding['effect'], presentation: $this->presentation);
      $this->waymarksUsable = array_any($timeline->playbackSegments, static fn(array $segment): bool => $segment['layer'] === 'image');
      if (!$this->waymarksUsable) { $this->note('waymark-effect', 'The waymark cue effect has no graphical image; waymark glyphs remain.'); }
    } catch (Throwable $error) {
      $this->waymarksUsable = false;
      $this->note('waymark-effect', 'The waymark cue effect is unusable; waymark glyphs remain: ' . $error->getMessage());
    }
    return $this->waymarksUsable;
  }

  /**
   * Shows exactly these waymarks. An unchanged waymark keeps its playback;
   * moving subjects are followed by object identity.
   *
   * @param list<FieldWaymark> $waymarks
   */
  public function showWaymarks(array $waymarks): void
  {
    $wanted = [];
    if ($this->canPresentWaymarks()) {
      $effect = $this->catalog->cues[$this->catalog->waymarks->color]['effect'];
      foreach ($waymarks as $waymark) {
        $id = 'waymark-' . $waymark->id;
        $wanted[$id] = true;
        $current = $this->waymarks[$id] ?? null;
        $anchor = $waymark->anchor;
        $unchanged = $current !== null && isset($this->sessions[$id]) && $current->anchor->objectId === $anchor->objectId
          && ($anchor->objectId !== null || $current->anchor->cell == $anchor->cell);
        if (!$unchanged) {
          $this->startEffect($id, $effect, $anchor->objectId !== null
            ? FieldEffectAnchor::fromArray(['object' => $anchor->objectId])
            : FieldEffectAnchor::createAtPosition($anchor->cell));
        }
        if (isset($this->sessions[$id])) { $this->waymarks[$id] = $waymark; }
      }
    }
    foreach (array_keys($this->waymarks) as $id) {
      if (!isset($wanted[$id])) { $this->removeEffect($id); }
    }
  }

  /**
   * Shows exactly these NPCs' cues: each with its colour's cue art, above the
   * NPC it follows. A colour without a binding keeps its glyph.
   *
   * @param list<Npc> $npcs NPCs whose cues show now.
   */
  public function showNpcCues(array $npcs): void
  {
    $wanted = [];
    foreach ($npcs as $npc) {
      if ($npc->cue === null) { continue; }
      $binding = $this->catalog->cues[$npc->cue->color] ?? null;
      if ($binding === null) {
        $this->note('binding:' . $npc->cue->color, 'No graphical binding for cue color ' . $npc->cue->color . '; its original glyph remains.');
        continue;
      }
      $id = self::getNpcCueId($npc);
      $wanted[$id] = true;
      if (!isset($this->sessions[$id])) {
        $this->startEffect($id, $binding['effect'], FieldEffectAnchor::fromArray(['object' => $npc->getGraphicalSpriteId()]));
      }
      if (isset($this->sessions[$id])) { $this->npcCues[$id] = $npc; }
    }
    foreach (array_keys($this->npcCues) as $id) {
      if (!isset($wanted[$id])) { $this->removeEffect($id); }
    }
  }

  /** Whether graphical cue art draws an NPC's cue, so its glyph stands down. */
  public function canPresentNpcCue(Npc $npc): bool
  {
    if (!$this->supported || $this->presentation !== EffectPresentation::GRAPHICAL) { return false; }
    $session = $this->sessions[self::getNpcCueId($npc)] ?? null;
    return $session !== null
      && array_any($session->playback->timeline->playbackSegments, static fn(array $segment): bool => $segment['layer'] === 'image');
  }

  /** The cue's colour is part of its identity, so a page showing another colour restarts the art. */
  private static function getNpcCueId(Npc $npc): string
  {
    return 'npc-cue-' . $npc->getGraphicalSpriteId() . ':' . ($npc->cue?->color ?? '');
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
    unset($this->sessions[$id], $this->cues[$id], $this->waymarks[$id], $this->npcCues[$id]);
  }
  public function clear(): void
  {
    foreach (array_keys($this->sessions) as $id) { $this->removeEffect($id); }
    $this->cues = []; $this->waymarks = []; $this->npcCues = []; $this->mapId = ''; $this->viewerPosition = null;
  }
  public int $count { get => count($this->sessions); }

  /** @param iterable<GraphicalSpriteProviderInterface>|null $objects */
  public function update(float $seconds, bool $reducedMotion, ?iterable $objects = null, bool $showGuidance = true,
    ?Vector2 $viewerPosition = null): void
  {
    $this->viewerPosition = $viewerPosition === null ? null : clone $viewerPosition;
    if ($objects !== null) { $this->reconcileObjects($objects); }
    $this->clock += $seconds;
    foreach ($this->sessions as $id => $session) {
      if (!$showGuidance && $this->isGuidance($id)) { continue; }
      if (!$this->canShowEventCue($id, $session)) { continue; }
      FieldEffectSession::playCues($session->update($seconds, $reducedMotion)->crossedCues);
      if ($session->playback->isCompleted) { unset($this->sessions[$id]); }
    }
  }

  /** @param iterable<GraphicalSpriteProviderInterface> $objects */
  public function renderText(Camera $camera, iterable $objects, bool $reducedMotion): void
  {
    $indexed = $this->reconcileObjects($objects);
    foreach ($this->sessions as $id => $session) {
      // Prompt, cue and waymark glyphs already have their authoritative terminal presenters.
      if ($this->isGuidance($id)) { continue; }
      $position = $session->anchor->objectId === null ? $session->anchor->cell
        : ($indexed[$session->anchor->objectId] ?? null)?->getGraphicalSpriteWorldPosition();
      if ($position === null) { continue; }
      Console::withLayer($session->id, fn() => $session->renderText($camera,
        $camera->getScreenSpacePosition($position), $this->presentation, $reducedMotion),
        PresentationLayerPolicy::FIELD_EFFECT_FRONT);
    }
  }

  /**
   * Every field effect, cue and waymark image to draw now.
   *
   * Guidance is UI over the field: it stays anchored to what it marks and
   * never moves for a field sprite. While the player's sprite overlaps a
   * mark, the whole mark eases to the game's overlap opacity, so the player
   * shows beneath it ({@see FieldCueStyle::getOverlapFade()}).
   *
   * @param iterable<GraphicalSpriteProviderInterface> $objects
   * @param array{x: int, y: int} $origin
   * @param string|null $playerId The player's sprite identity among `$objects`.
   * @param PresentationSpriteMotion|null $cameraMotion The step the camera scrolls with now, when it follows a
   *   stepping character: edge markers slide with it to their new places, for a renderer that slides pinned sprites.
   * @return list<FieldEffectSprite>
   */
  public function getSprites(iterable $objects, ?FieldViewport $viewport, array $origin, bool $reducedMotion,
    bool $showGuidance = true, ?string $playerId = null, ?PresentationSpriteMotion $cameraMotion = null): array
  {
    $indexed = $this->reconcileObjects($objects);
    if ($playerId !== null) {
      $this->viewerPosition = isset($indexed[$playerId]) ? clone $indexed[$playerId]->getGraphicalSpriteWorldPosition() : null;
    }
    $player = $playerId === null || !isset($indexed[$playerId]) ? null
      : FieldSpriteBounds::findBounds($indexed[$playerId], $viewport, $origin);
    $sprites = [];
    foreach ($this->sessions as $id => $session) {
      $marks = $this->composeSession($id, $session, $indexed, $viewport, $origin, $reducedMotion, $showGuidance, $cameraMotion);
      // Guidance the player carries, such as the action prompt over the player, is never under the player.
      if ($marks !== [] && $this->isGuidance($id) && ($playerId === null || $session->anchor->objectId !== $playerId)) {
        $marks = $this->fadeUnderPlayer($marks, $player, $viewport, $origin, $reducedMotion);
      }
      array_push($sprites, ...$marks);
    }
    return $sprites;
  }

  /**
   * The images one session shows now: an effect, a cue or a waymark on its
   * cell, or a waymark's edge marker.
   *
   * @param array<string, GraphicalSpriteProviderInterface> $indexed
   * @param array{x: int, y: int} $origin
   * @return list<FieldEffectSprite>
   */
  private function composeSession(string $id, FieldEffectSession $session, array $indexed, ?FieldViewport $viewport,
    array $origin, bool $reducedMotion, bool $showGuidance, ?PresentationSpriteMotion $cameraMotion): array
  {
    if (!$showGuidance && $this->isGuidance($id)) { return []; }
    $object = $session->anchor->objectId === null ? null : ($indexed[$session->anchor->objectId] ?? null);
    if ($session->anchor->objectId !== null && $object === null) { $this->removeEffect($id); return []; }
    if (!$this->supported) { return []; }
    $cue = $this->cues[$id] ?? null;
    if (!$this->canShowEventCue($id, $session)) { return []; }
    $position = $object?->getGraphicalSpriteWorldPosition() ?? $session->anchor->cell;
    if ($position === null) { return []; }
    $waymark = $this->waymarks[$id] ?? null;
    $npcCue = isset($this->npcCues[$id]);
    // Waymark art draws all or nothing; otherwise the terminal glyphs are the waymarks.
    if ($waymark !== null && !$this->canPresentWaymarks()) { return []; }
    $cueMark = $cue !== null || $waymark !== null || $npcCue;
    // A renderer that draws the bob is told it once; any other is sent this frame's whole-pixel rise.
    $lift = $cueMark && !$this->bobsDeclared ? $this->catalog->cueStyle->getBobLift($this->clock, $reducedMotion) : 0;
    // An NPC occupies its cell, so its cue floats above it.
    if ($npcCue) { $position = new Vector2($position->x, $position->y - 1); }
    if ($waymark !== null) {
      // A character occupies its cell, so its waymark floats above it.
      if ($object !== null) { $position = new Vector2($position->x, $position->y - 1); }
      $edge = $viewport === null ? null
        : FieldCueEdgePlacement::locatePixels($position, $origin, $viewport, $this->catalog->waymarks->inset);
      if ($edge !== null) {
        return $this->composeEdgeMarker($session, $edge, $viewport, $lift, $reducedMotion,
          $this->pinnedMotion ? $cameraMotion ?? $object?->getGraphicalSpriteMotion() : null);
      }
      if ($this->isCuePresented($waymark->anchor->eventMarker)
        || ($waymark->anchor->objectId !== null && $this->isNpcCuePresented($waymark->anchor->objectId))) { return []; }
    }
    $marks = $session->getSprites($position, $reducedMotion, $object?->getGraphicalSpriteMotion(), $this->isGuidance($id));
    if ($cueMark) {
      $bob = $this->bobsDeclared ? $this->catalog->cueStyle->getBob($reducedMotion) : null;
      $color = $cue?->cue?->color ?? ($npcCue ? $this->npcCues[$id]->cue?->color : $this->catalog->waymarks->color);
      $marks = [...$this->createPlate($session->id . ':plate', (string)$color, $position, $lift, $bob,
        $object?->getGraphicalSpriteMotion()),
        ...array_map(static fn(FieldEffectSprite $mark): FieldEffectSprite => self::raise($mark, $lift, $bob), $marks)];
    }
    return $marks;
  }

  /**
   * One guidance mark's images, all eased to the game's overlap opacity
   * while the player's sprite overlaps any of them and back to opaque once
   * it does not, so a mark and its plate and pointer always fade together.
   *
   * @param list<FieldEffectSprite> $marks
   * @param array{x: int, y: int} $origin
   * @return list<FieldEffectSprite>
   */
  private function fadeUnderPlayer(array $marks, ?FieldSpriteBounds $player, ?FieldViewport $viewport, array $origin,
    bool $reducedMotion): array
  {
    $bounds = FieldSpriteBounds::join(array_map(static fn(FieldEffectSprite $mark): ?FieldSpriteBounds
      => FieldSpriteBounds::findBounds($mark, $viewport, $origin), $marks));
    $fade = $this->catalog->cueStyle->getOverlapFade($player !== null && $bounds?->overlaps($player) === true, $reducedMotion);
    if ($fade === null) { return $marks; }

    return array_map(static fn(FieldEffectSprite $mark): FieldEffectSprite => $mark->applyFade($fade), $marks);
  }

  /**
   * An off-screen waymark at the view edge: its upright mark on its colour's
   * plate, inside, and a pointer on the outward side. Only the pointer turns.
   * Each is pinned to the screen at its exact pixel: the grid cell, the lift
   * for the rest of the row, the pivot for the rest of the column. Given the
   * motion the view or its subject moved with, each slides there with it.
   *
   * @param array{direction: string, x: float, y: float, outward: array{x: float, y: float}} $edge
   * @param int $rise This frame's whole-pixel bob, for a renderer that cannot draw a declared one.
   * @return list<FieldEffectSprite>
   */
  private function composeEdgeMarker(FieldEffectSession $session, array $edge, FieldViewport $view, int $rise,
    bool $reducedMotion, ?PresentationSpriteMotion $motion): array
  {
    $zoom = $view->zoom;
    $size = (int)round(FieldViewport::TILE_SIZE * $zoom);
    $bob = $this->bobsDeclared ? $this->catalog->cueStyle->getBob($reducedMotion, $zoom) : null;
    $markX = $edge['x'] - $edge['outward']['x'] * $this->catalog->cueStyle->pointerSpacingX * $zoom;
    $markY = $edge['y'] - $edge['outward']['y'] * $this->catalog->cueStyle->pointerSpacingY * $zoom;
    $place = static fn(int $width): array => FieldCueEdgePlacement::findCell($markX, $markY, $view, $width);
    $pointer = FieldCueEdgePlacement::findCell($edge['x'], $edge['y'], $view, $size);
    $extra = (int)round($rise * $zoom);
    $sprites = [];
    $plate = $this->catalog->cues[$this->catalog->waymarks->color]['plate'] ?? null;
    if ($plate !== null) {
      $cell = $place($size);
      $plateBob = self::fitBob($bob, $size);
      $sprites[] = new FieldScreenSprite($session->id . ':edge-plate', new GraphicalSpriteDefinition($plate, $size, $size,
        PresentationSpriteAnchor::BOTTOM_CENTER, PresentationLayerPolicy::FIELD_GUIDANCE,
        lift: self::fitLift($cell['lift'] + $extra, $size, $plateBob), pivot: $cell['pivot'], bob: $plateBob),
        $cell['position'], $motion);
    }
    foreach ($session->getSprites(new Vector2(0, 0), $reducedMotion, guidance: true) as $index => $frame) {
      $definition = $frame->getGraphicalSpriteDefinition();
      $width = max(1, (int)round($definition->width * $zoom));
      $height = max(1, (int)round($definition->height * $zoom));
      $cell = $place($width);
      $pivot = $definition->pivot === null ? $cell['pivot']
        : new PresentationSpritePivot(max(0.0, min(1.0, $definition->pivot->x + $cell['pivot']->x - 0.5)), $definition->pivot->y);
      $markBob = self::fitBob($bob, $height);
      $sprites[] = new FieldScreenSprite($session->id . ':edge-mark-' . $index, new GraphicalSpriteDefinition($definition->asset,
        $width, $height, $definition->anchor, $definition->layer, $definition->sourceRect,
        self::fitLift($cell['lift'] + (int)round($definition->lift * $zoom) + $extra, $height, $markBob), 0, $pivot, $markBob),
        $cell['position'], $motion);
    }
    $image = $this->catalog->cues[$this->catalog->waymarks->color]['pointer'][$edge['direction']];
    $pointerBob = self::fitBob($bob, $size);
    $sprites[] = new FieldEdgeSprite($session->id . ':edge', new GraphicalSpriteDefinition($image['asset'], $size, $size,
      PresentationSpriteAnchor::BOTTOM_CENTER, PresentationLayerPolicy::FIELD_GUIDANCE_FRONT,
      lift: self::fitLift($pointer['lift'] + $extra, $size, $pointerBob), quarterTurns: $image['quarterTurns'] ?? 0,
      pivot: $pointer['pivot'], bob: $pointerBob), $pointer['position'], $motion);
    return $sprites;
  }

  /** The plate a cue colour draws behind its mark at a world cell, when it has one. @return list<FieldEffectSprite> */
  private function createPlate(string $id, string $color, Vector2 $position, int $lift, ?PresentationSpriteBob $bob,
    ?PresentationSpriteMotion $motion): array
  {
    $plate = $this->catalog->cues[$color]['plate'] ?? null;
    if ($plate === null) { return []; }
    $bob = self::fitBob($bob, FieldViewport::TILE_SIZE);
    return [new FieldEffectSprite($id, new GraphicalSpriteDefinition($plate, FieldViewport::TILE_SIZE, FieldViewport::TILE_SIZE,
      PresentationSpriteAnchor::BOTTOM_CENTER, PresentationLayerPolicy::FIELD_GUIDANCE,
      lift: self::fitLift($lift, FieldViewport::TILE_SIZE, $bob), bob: $bob), $position, $motion)];
  }

  /** The same mark drawn higher by this frame's rise, or bobbing on its own. */
  private static function raise(FieldEffectSprite $mark, int $lift, ?PresentationSpriteBob $bob): FieldEffectSprite
  {
    if ($lift === 0 && $bob === null) { return $mark; }
    $definition = $mark->getGraphicalSpriteDefinition();
    $bob = self::fitBob($bob, $definition->height);
    return new FieldEffectSprite($mark->getGraphicalSpriteId(), new GraphicalSpriteDefinition($definition->asset, $definition->width,
      $definition->height, $definition->anchor, $definition->layer, $definition->sourceRect,
      self::fitLift($definition->lift + $lift, $definition->height, $bob), $definition->quarterTurns, $definition->pivot, $bob),
      $mark->getGraphicalSpriteWorldPosition(), $mark->getGraphicalSpriteMotion());
  }

  /** A bob no taller than the image that rises by it. */
  private static function fitBob(?PresentationSpriteBob $bob, int $height): ?PresentationSpriteBob
  {
    return $bob === null || $bob->height <= $height ? $bob : new PresentationSpriteBob($height, $bob->seconds);
  }

  /** A lift that leaves room for the bob above it, within the image's own height. */
  private static function fitLift(int $lift, int $height, ?PresentationSpriteBob $bob): int
  {
    return max(0, min($height - ($bob?->height ?? 0), $lift));
  }

  /** Guidance hides while a cinematic owns the field: the action prompt, cues and waymarks. */
  private function isGuidance(string $id): bool
  {
    return $id === self::ACTION_PROMPT_ID || isset($this->cues[$id]) || isset($this->waymarks[$id]) || isset($this->npcCues[$id]);
  }

  /** Whether an event's own cue art marks it now, so its waymark need not. */
  private function isCuePresented(?string $marker): bool
  {
    if ($marker === null) { return false; }
    foreach ($this->cues as $id => $event) {
      if ($event->marker === $marker && isset($this->sessions[$id])
        && $this->canShowEventCue($id, $this->sessions[$id])) { return true; }
    }
    return false;
  }

  private function canShowEventCue(string $id, FieldEffectSession $session): bool
  {
    $event = $this->cues[$id] ?? null;
    return $event === null || ($session->anchor->cell !== null && $event->shouldRenderCue($this->viewerPosition)
      && ($event->cue?->isInRange($session->anchor->cell, $this->viewerPosition) ?? false));
  }

  /** Whether the NPC with this sprite identity shows its own cue art now, so its waymark need not. */
  private function isNpcCuePresented(string $objectId): bool
  {
    foreach ($this->npcCues as $id => $npc) {
      if ($npc->getGraphicalSpriteId() === $objectId && isset($this->sessions[$id])) { return true; }
    }
    return false;
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
