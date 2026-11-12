<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectFlightContact;
use Ichiloto\Engine\Animations\Timelines\EffectFlightRoute;
use Ichiloto\Engine\Animations\Timelines\EffectStraightFlightFallback;
use Ichiloto\Engine\Core\Clocks\RuntimeClock;

use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextBatch;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureFallback;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationFallbackReport;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use RuntimeException;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;

/** Holds identity/layout, not combat state. Generating a frame has no gameplay effects. */
final class GraphicalBattlePresentation
{
  /** @var array<int, array{battler: CharacterInterface, party: bool, image: CanvasImage, art: BattlerArtwork, available: bool, poses: ?BattlePoseSet, scale: ?BattlerScale, grounding: ?BattlerGrounding, habitat: ?EnemyHabitat, strikePoint: ?BattlerStrikePoint}> */
  private array $participants = [];
  private array $poseDiagnostics = [];
  private readonly BattleConditionEffects $conditionEffects;
  private readonly BattleHeadAttachmentLayout $headAttachments;
  private readonly EnemyDefeatStyle $defeatStyle;
  /** Starting presentation anchors; current logical positions project movement over these. */
  private BattleStaging $staging;
  /** @var list<CharacterInterface> */
  private array $stagedFrontline;
  /** @var array<int, BattlerSlot> Troop member instance => its slot; defeat never renumbers survivors. */
  private array $enemySlots = [];
  /** The menu theme whose control sets draw HUD hints, read once; null when the project has none or it cannot load. */
  private ?MenuPresentationCatalog $controls = null;
  private bool $controlsRead = false;
  /** Where this battle logs any part of its graphical presentation that falls back. */
  public readonly PresentationFallbackReport $fallbacks;

  private function __construct(public readonly BattleArenaDefinition $arena, public readonly BattleCanvasLayout $layout,
    private readonly BattleConfig $battle,
    private readonly string $assetRoot, private readonly ?BattleScale $scale)
  {
    $this->headAttachments = new BattleHeadAttachmentLayout();
    $this->fallbacks = new PresentationFallbackReport();
    $this->defeatStyle = EnemyDefeatStyle::createFromConfig();
    $this->conditionEffects = BattleConditionEffects::createFromConfig(
      new \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary($assetRoot));
  }

  private function findControlsTheme(): ?MenuPresentationCatalog
  {
    if (!$this->controlsRead) {
      $this->controlsRead = true;
      try { $this->controls = MenuPresentationCatalog::load($this->assetRoot); }
      catch (\Throwable $error) { Debug::warn('Battle hints keep their text: ' . $error->getMessage()); }
    }
    return $this->controls;
  }

  private function placeBaseArtwork(BattlerSlot $slot, ?BattlerArtwork $art, ?BattlePoseSet $poses, int $identity,
    ?BattlerScale $profile = null): CanvasRectangle
  {
    $placed = BattleFormationLayout::getBasePlacement($slot, $art, $this->layout, $poses, $profile, $this->scale);
    $diagnostic = $identity . ':base-placement:' . $slot->x . ':' . $slot->y;
    if ($placed['diagnostic'] !== null && !isset($this->poseDiagnostics[$diagnostic])) {
      Debug::warn($placed['diagnostic']);
      $this->poseDiagnostics[$diagnostic] = true;
    }
    return $placed['bounds'];
  }

  /**
   * The body a battler visibly draws this frame: its artwork's visible pixels as placed, cropped, mirrored, clipped
   * and moved. Every overlay (cursor, condition badges, names, results and effects) attaches here, so transparent
   * canvas padding and pose-specific margins never carry an overlay away from the figure.
   */
  private function locateBody(CanvasImage $drawn, int $identity): CanvasRectangle
  {
    $visible = PngAssetBounds::getVisibleBounds($this->assetRoot, $drawn);
    if ($visible['bounds'] !== null) { return $visible['bounds']; }
    $diagnostic = $identity . ':body:' . $drawn->asset;
    if (!isset($this->poseDiagnostics[$diagnostic])) {
      Debug::warn('Battle overlays attach to the whole artwork rectangle; its visible body is unknown: ' . $drawn->asset
        . ': ' . ($visible['diagnostic'] ?? 'the artwork has no visible pixels.'));
      $this->poseDiagnostics[$diagnostic] = true;
    }
    return $drawn->destination;
  }

  /** Selected project-authored pose metadata also owns its command recovery lifetime. */
  public function getPose(CharacterInterface $battler, BattlePoseRole $role): ?BattlerPose
  {
    return ($this->participants[spl_object_id($battler)]['poses'] ?? null)?->getPose($role);
  }

  public function getAnimationPose(CharacterInterface $battler, ?int $animationId): ?BattlerPose
  {
    return ($this->participants[spl_object_id($battler)]['poses'] ?? null)?->getAnimationPose($animationId);
  }

  /** Snapshots current drawn geometry once; the same planner serves runtime and Editor inspection. */
  public function planActionTravel(CharacterInterface $actor, array $targets, \Ichiloto\Engine\Battle\BattleAction $action,
    float $poseElapsedSeconds = 0, bool $imageFlips = true, ?int $animationId = null,
    \Ichiloto\Engine\Animations\ActionMovement $movement = \Ichiloto\Engine\Animations\ActionMovement::RUN_TO_TARGET): BattleTravelPlan
  {
    \Ichiloto\Engine\Animations\ActionMovement::RUN_TO_TARGET->validateAction($action, $actor, $targets);
    $target = $targets[0];
    $sourceId = spl_object_id($actor); $targetId = spl_object_id($target);
    $source = $this->participants[$sourceId] ?? throw new RuntimeException('Travel source is absent from the battlefield.');
    $recipient = $this->participants[$targetId] ?? throw new RuntimeException('Travel target is absent from the battlefield.');
    if ($source['party'] === $recipient['party']) { throw new \InvalidArgumentException('Melee travel requires opposing sides.'); }
    $canvas = $this->frame(new BattlePresentationSnapshot(poseElapsedSeconds: $poseElapsedSeconds), reducedMotion: false);
    $images = [];
    foreach ($this->participants as $id => $participant) {
      $image = array_find($canvas->images, static fn(CanvasImage $image): bool => $image->id === 'combatant-' . $id);
      if ($image === null) { continue; }
      $body = PngAssetBounds::getVisibleBounds($this->assetRoot, $image)['bounds'];
      if ($body === null) { continue; }
      $slot = $this->getSlot($participant['battler']);
      $images[$id] = ['image' => $image, 'body' => $body, 'foot' => new Vector2($slot->x, $slot->y)];
    }
    if (!isset($images[$sourceId], $images[$targetId])) { throw new RuntimeException('Travel needs current visible source and target artwork.'); }
    $from = $images[$sourceId]; $to = $images[$targetId];
    $sign = $to['foot']->x === $from['foot']->x ? BattleEffectDirection::getOpponentsSign($source['party'])
      : ($to['foot']->x < $from['foot']->x ? -1 : 1);
    $mirror = $sign !== BattleEffectDirection::getOpponentsSign($source['party']);
    if ($mirror && !$imageFlips) { throw new RuntimeException('This renderer cannot keep the travelling battler facing its target.'); }
    $body = $from['body'];
    if ($mirror) { $body = new CanvasRectangle(2 * $from['foot']->x - $body->x - $body->width, $body->y, $body->width, $body->height); }
    $footing = BattleFormationClearance::getFooting($body);
    $unit = $this->scale === null || $source['scale'] === null ? $body->height
      : $this->scale->referenceHeight * $source['scale']->relativeSize * $this->getSlot($actor)->displayScale;
    $boundPose = $source['poses']?->getAnimationPose($animationId);
    $pose = $boundPose ?? $source['poses']?->getPose(BattlePoseRole::getForAction($action));
    $farPose = $boundPose?->farPose;
    $usesFarPose = $farPose !== null && $this->measureActionRange($actor, $targets) >= $farPose->minDistance;
    $strike = $recipient['strikePoint']?->getCanvasPosition($to['body'], $to['image']->flipX, $to['image']->flipY);
    $arrivalPose = null;
    if ($boundPose !== null && $boundPose->arrivalPoses !== []) {
      $approach = $body;
      if ($movement === \Ichiloto\Engine\Animations\ActionMovement::FLASH_STEP && $boundPose->guardFrame !== null) {
        $guard = $this->projectBattler($sourceId, $source, null, false, true, $poseElapsedSeconds, guardPose: $boundPose);
        $approach = $this->locateBody($guard['image'], $sourceId);
        if ($mirror) {
          $approach = new CanvasRectangle(2 * $from['foot']->x - $approach->x - $approach->width,
            $approach->y, $approach->width, $approach->height);
        }
      }
      $strike ??= new Vector2($sign < 0 ? $to['body']->x + $to['body']->width : $to['body']->x,
        $to['body']->y + $to['body']->height / 2);
      $angle = rad2deg(atan2($strike->y - ($approach->y + $approach->height / 2),
        $sign * ($strike->x - ($approach->x + $approach->width / 2))));
      $arrivalPose = $boundPose->selectArrivalPose($angle);
    }
    $poseReach = $arrivalPose?->reach ?? ($usesFarPose ? $farPose->reach : $pose?->reach);
    $reach = $poseReach?->getCanvasOffset($unit) ?? new Vector2(
      max(0, $sign < 0 ? $from['foot']->x - $body->x : $body->x + $body->width - $from['foot']->x), 0);
    // Movement cells fit the standing area; action-phase weapon overhang retains its existing presentation extent.
    $sequences = $movement === \Ichiloto\Engine\Animations\ActionMovement::FLASH_STEP
      ? [[$boundPose, BattlePoseSequence::ENTRY], [$boundPose, BattlePoseSequence::EXIT]]
      : [[$source['poses']?->getPose(BattlePoseRole::MOVE), BattlePoseSequence::MAIN],
        [$source['poses']?->getPose(BattlePoseRole::RETURNING), BattlePoseSequence::MAIN]];
    $envelope = $this->getTravelEnvelope($actor, $body, $mirror, $sequences);
    return BattleTravelPlanner::plan(new BattleTravelSubject($from['foot'], $envelope, $footing),
      new BattleTravelSubject($to['foot'], $to['body'], BattleFormationClearance::getFooting($to['body'])),
      $this->layout->battlerArea ?? new CanvasRectangle(0, 0, $this->layout->width, $this->layout->getHudTop()),
      $reach, array_values(array_map(static fn(array $entry): CanvasRectangle => BattleFormationClearance::getFooting($entry['body']),
        array_diff_key($images, [$sourceId => true, $targetId => true]))), $strike,
      $this->layout->advanceSpeed, $this->layout->returnSpeed, $sign, $usesFarPose, $arrivalPose);
  }

  /** @param list<array{?BattlerPose, BattlePoseSequence}> $sequences */
  private function getTravelEnvelope(CharacterInterface $actor, CanvasRectangle $body, bool $mirror, array $sequences): CanvasRectangle
  {
    $participant = $this->participants[spl_object_id($actor)];
    $slot = $this->getSlot($actor);
    $base = $this->placeBaseArtwork($slot, $participant['art'], $participant['poses'], spl_object_id($actor), $participant['scale']);
    $pixelScale = $base->width / $participant['art']->width / $slot->displayScale;
    $left = $body->x; $top = $body->y; $right = $left + $body->width; $bottom = $top + $body->height;
    foreach ($sequences as [$pose, $sequence]) {
      if ($pose === null) { continue; }
      foreach (array_keys(array_unique($pose->getSequenceFrames($sequence))) as $entry) {
        $art = $pose->getArtwork($this->assetRoot, $entry / $pose->fps, sequence: $sequence);
        if ($art === null) { throw new RuntimeException('Travel pose artwork is unavailable: ' . $pose->asset); }
        $bounds = BattleFormationLayout::getPosePlacement($slot, $art, $pixelScale, $this->layout,
          $participant['scale'], $this->scale, $pose->scaleSpan, $participant['poses']);
        if ($mirror) { $bounds = new CanvasRectangle(2 * $slot->x - $bounds->x - $bounds->width, $bounds->y, $bounds->width, $bounds->height); }
        $visible = PngAssetBounds::getVisibleBounds($this->assetRoot, new CanvasImage('travel-envelope', $art->asset,
          $bounds, sourceRect: $art->sourceRect, flipX: $mirror))['bounds'];
        if ($visible === null) { throw new RuntimeException('Travel pose has no visible body.'); }
        $left = min($left, $visible->x); $top = min($top, $visible->y);
        $right = max($right, $visible->x + $visible->width); $bottom = max($bottom, $visible->y + $visible->height);
      }
    }
    return new CanvasRectangle($left, $top, $right - $left, $bottom - $top);
  }

  /** Initial screen-space ground separation as a share of the canvas diagonal. */
  public function measureActionRange(CharacterInterface $actor, array $targets): ?float
  {
    if (count($targets) !== 1 || !isset($this->participants[spl_object_id($actor)], $this->participants[spl_object_id($targets[0])])) {
      return null;
    }
    $source = $this->getSlot($actor);
    $target = $this->getSlot($targets[0]);
    return hypot($target->x - $source->x, $target->y - $source->y) / hypot($this->layout->width, $this->layout->height);
  }

  /** Where a participant stands: its frontline slot, or its troop place on this arena's ground. */
  public function getSlot(CharacterInterface $battler): BattlerSlot
  {
    $base = $this->getBaseSlot($battler);
    $formation = $this->battle->formation;
    if ($formation->getPosition($battler) == $formation->getInitialPosition($battler)) { return $base; }
    $party = $this->participants[spl_object_id($battler)]['party'];
    $members = $party ? $this->battle->partyRoster->battlers : $this->battle->troop->members->toArray();
    $anchors = array_map(fn(CharacterInterface $member): array => ['battler' => $member,
      'point' => new \Ichiloto\Engine\Core\Vector2($this->getBaseSlot($member)->x, $this->getBaseSlot($member)->y)], $members);
    $point = BattleFormationProjection::project($formation, $battler, $anchors,
      ($party ? 1 : -1) * $base->width * $this->layout->spacing->lateral, $base->height * $this->layout->spacing->depth);
    $area = $party ? $this->layout->partyArea : $this->layout->enemyArea;
    $left = $area?->x ?? ($party ? $this->layout->width / 2 : 0);
    $right = $area === null ? ($party ? $this->layout->width : $this->layout->width / 2) : $area->x + $area->width;
    $ground = $this->staging->ground;
    $y = max($ground->top, min($ground->bottom, $point->y));
    $scale = $base->displayScale / $ground->getDepthScale($base->y) * $ground->getDepthScale($y);
    $participant = $this->participants[spl_object_id($battler)];
    $body = BattleFormationLayout::getStandingSize($participant['available'] ? $participant['art'] : null,
      $participant['poses'], $participant['scale'], $this->scale,
      $party ? null : $this->battle->troop->getStagePlace($battler), $this->assetRoot);
    return BattleStaging::fitMovedSlot($this->layout, $this->arena, $party,
      $this->participants[spl_object_id($battler)]['habitat'] ?? EnemyHabitat::LAND,
      new BattlerSlot(max($left, min($right, $point->x)), $y, $base->width, $base->height, $scale), $body,
      fitToAreas: $party && count($members) > count($this->layout->partySlots));
  }

  private function getBaseSlot(CharacterInterface $battler): BattlerSlot
  {
    $identity = spl_object_id($battler);
    $participant = $this->participants[$identity] ?? throw new RuntimeException('Not a participant of this graphical battle.');
    if (!$participant['party']) {
      return $this->enemySlots[$identity] ?? throw new RuntimeException('A troop participant lost its graphical formation slot.');
    }
    $index = array_search($battler, $this->battle->partyRoster->battlers, true);
    return $index === false ? throw new RuntimeException('Only frontline members stand in the party formation.')
      : $this->getStaging()->party[$index] ?? throw new RuntimeException('Graphical battle requires a slot for every active party member.');
  }

  public function getStaging(): BattleStaging
  {
    if ($this->stagedFrontline !== $this->battle->partyRoster->battlers) {
      $this->staging = $this->stage();
    }
    return $this->staging;
  }

  private function stage(): BattleStaging
  {
    $getSize = function (CharacterInterface $battler, ?BattleStageSlot $place): array {
      $participant = $this->participants[spl_object_id($battler)];
      return BattleFormationLayout::getStandingSize($participant['available'] ? $participant['art'] : null,
        $participant['poses'], $participant['scale'], $this->scale, $place, $this->assetRoot);
    };
    $enemies = $this->battle->troop->members->toArray();
    $places = array_map(fn($enemy): ?BattleStageSlot => $this->battle->troop->getStagePlace($enemy), $enemies);
    $this->stagedFrontline = $this->battle->partyRoster->battlers;
    $staging = BattleStaging::stage($this->layout, $this->arena,
      array_map(static fn(CharacterInterface $battler): array => $getSize($battler, null), $this->stagedFrontline),
      $places, array_map($getSize, $enemies, $places),
      array_map(static fn($enemy): EnemyHabitat => $enemy instanceof Enemy ? $enemy->habitat : EnemyHabitat::LAND, $enemies));
    foreach ($enemies as $index => $enemy) { $this->enemySlots[spl_object_id($enemy)] = $staging->enemies[$index]; }
    return $staging;
  }

  /** @return list<string> */
  public function requiredCapabilities(): array
  {
    // Effects may introduce crops and alpha after entry; negotiate them before
    // combat starts, not when the first command reaches its impact.
    return [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::SPRITE_SOURCE_RECT,
      RendererSessionConfig::CANVAS_CLIP_OPACITY,
      ...($this->layout->skin !== null ? [RendererSessionConfig::CANVAS_GLYPH_EFFECTS] : [])];
  }

  public static function prepare(BattleConfig $battle, BattlePresentationCatalog $catalog, string $assetRoot): ?self
  {
    $arena = $catalog->getArenaFor($battle);
    if ($arena === null) { return null; }
    $layout = ($catalog->ui ?? throw new RuntimeException('A graphical arena requires an explicit shared battle canvas layout.'))->getForArena($arena);
    $arena->background->destination->assertWithin($layout->width, $layout->height);
    $presentation = new self($arena, $layout, $battle, $assetRoot, $catalog->scale);
    $images = PngAssetPreflight::getAvailableSize($assetRoot, $arena->background->asset) === null ? [] : [$arena->background];
    foreach ([[$battle->party->participants->toArray(), true],
      [$battle->troop->members->toArray(), false]] as [$members, $party]) {
      foreach ($members as $member) {
        $key = $member instanceof Character ? $member->actorId : $member->name;
        $art = ($party ? $catalog->actors : $catalog->enemies)[$key] ?? null;
        if ($art === null) {
          Debug::warn("No battler artwork registered; using a name fallback: {$key}");
        }
        $probe = $art === null ? null : PngAssetPreflight::getAvailableSize($assetRoot, $art->asset);
        $available = $probe !== null;
        $art ??= BattleFormationLayout::getUnavailableArtwork();
        // Artwork changes throughout development; the image on disk is this
        // moment's truth. Authored metadata reconciles to it and the battle
        // renders best-effort, with the mismatch logged for the author
        // rather than blocking play. Assets only fail here when missing,
        // not PNGs, or corrupt.
        $reconciled = $probe === null ? $art : $art->clampedTo($probe['width'], $probe['height']);
        if ($reconciled !== $art) {
          Debug::warn(sprintf(
            'Battler artwork changed; rendering reconciled image bounds: %s (%s)',
            $key,
            $art->asset,
          ));
          $art = $reconciled;
        }
        $identity = spl_object_id($member);
        if (isset($presentation->participants[$identity])) {
          throw new RuntimeException('A graphical battle participant must be a distinct combatant instance.');
        }
        $poses = ($party ? $catalog->actorPoses : $catalog->enemyPoses)[$key] ?? null;
        $profile = $catalog->scale?->getProfile($key, $party);
        $presentation->participants[$identity] = ['battler' => $member, 'party' => $party,
          'image' => new CanvasImage('combatant-' . $identity, $art->asset, new CanvasRectangle(0, 0, 1, 1),
            BattleCanvasLayout::BATTLER_LAYER, $art->sourceRect),
          'art' => $art, 'available' => $available, 'poses' => $poses, 'scale' => $profile,
          'grounding' => ($party ? $catalog->actorGrounding : $catalog->enemyGrounding)[$key] ?? null,
          'habitat' => $member instanceof Enemy ? $member->habitat : null,
          'strikePoint' => ($party ? $catalog->actorStrikePoints : $catalog->enemyStrikePoints)[$key] ?? null];
      }
    }
    $presentation->staging = $presentation->stage();
    $partyIndices = array_flip(array_map(spl_object_id(...), $presentation->stagedFrontline));
    $arrangesFrontline = count($partyIndices) > count($layout->partySlots);
    foreach ($presentation->participants as $identity => $participant) {
      $available = $participant['available'];
      $art = $participant['art'];
      $member = $participant['battler'];
      $slots = $participant['party'] ? $presentation->staging->party : [$presentation->enemySlots[$identity]];
      if ($participant['party'] && $arrangesFrontline) {
        // Generated positions depend on their occupants. A reserve wave is
        // arranged when it enters, but its assets are admitted at battle setup.
        if (!isset($partyIndices[$identity])) {
          if ($available) { $images[] = $participant['image']; }
          continue;
        }
        $slots = [$slots[$partyIndices[$identity]]];
      }
      foreach ($slots as $possibleSlot) {
        $bounds = $presentation->placeBaseArtwork($possibleSlot, $available ? $art : null, $participant['poses'], $identity,
          $participant['scale']);
        $bounds->assertWithin($layout->width, $layout->height);
        if ($available) {
          $layout->skin?->targetCursor?->layout($presentation->locateBody(new CanvasImage('combatant-' . $identity,
            $art->asset, $bounds, BattleCanvasLayout::BATTLER_LAYER, $art->sourceRect), $identity), $layout->width, $layout->height);
        }
        if (min($bounds->width, $bounds->height) < 1) {
          throw new RuntimeException('Battler images must cover at least one graphical unit in each dimension.');
        }
      }
      $slot = $slots[0] ?? null;
      if ($slot === null) { throw new RuntimeException("Graphical battle requires a formation slot for every participant: {$member->name}"); }
      $image = new CanvasImage('combatant-' . $identity, $art->asset,
        $presentation->placeBaseArtwork($slot, $available ? $art : null, $participant['poses'], $identity, $participant['scale']),
        BattleCanvasLayout::BATTLER_LAYER, $art->sourceRect);
      $presentation->participants[$identity]['image'] = $image;
      if ($available) { $images[] = $image; }
    }
    GraphicalBattleHud::preflight($layout, $assetRoot, $images);
    // Resolve every image before battle-entry effects; no partially valid formation.
    new PresentationCanvas($layout->width, $layout->height, $images);
    if ($layout->battlerArea !== null || $layout->enemyArea !== null || $layout->partyArea !== null) {
      $resting = $presentation->frame();
      $partyImages = $enemyImages = [];
      $enemyIndices = array_flip(array_map(spl_object_id(...), $battle->troop->members->toArray()));
      $labels = [];
      foreach ($presentation->participants as $identity => $participant) {
        $image = array_find($resting->images, static fn(CanvasImage $image): bool => $image->id === 'combatant-' . $identity);
        if ($image === null) { continue; }
        if ($participant['party']) { $partyImages[] = $image; } else { $enemyImages[] = $image; }
        $labels[$image->id] = BattleFormationClearance::getBattlerLabel($participant['battler']->name,
          $participant['party'], ($participant['party'] ? $partyIndices : $enemyIndices)[$identity]);
      }
      foreach (BattleFormationClearance::inspect($layout, $partyImages, $enemyImages, $assetRoot, $labels) as $id => $issues) {
        foreach ($issues as $issue) { Debug::warn('Battle formation ' . $labels[$id] . ': ' . $issue); }
      }
    }
    return $presentation;
  }

  /** @param list<CanvasTextLayer> $ui */
  public function frame(?BattlePresentationState $field = null, array $ui = [], ?BattleHudSnapshot $hud = null,
    ?string $focus = null, ?float $now = null, bool $imageFlips = true, bool $compositing = true,
    ?bool $reducedMotion = null, bool $maskFlips = true, bool $imageTones = true,
    bool $imageRotation = false, bool $imageClips = true, bool $coverageDabs = false, bool $textureStrip = false, bool $bottomFade = false): PresentationCanvas
  {
    $now ??= RuntimeClock::getSeconds();
    $reducedMotion ??= Accessibility::prefersReducedMotion();
    try {
      return $this->composeFrame($field, $ui, $hud, $focus, $now, true, $imageFlips, $compositing, $reducedMotion, $maskFlips, $imageTones, $imageRotation, $imageClips, $coverageDabs, $textureStrip, $bottomFade);
    } catch (\InvalidArgumentException|RuntimeException $error) {
      // A failure in the base battlefield remains an error. Only optional pose
      // and effect composition can degrade, without losing combat or feedback.
      $fallback = $this->composeFrame($field, $ui, $hud, $focus, $now, false, $imageFlips, $compositing, $reducedMotion, $maskFlips, $imageTones, $imageRotation, $imageClips, $coverageDabs, $textureStrip, $bottomFade);
      $field?->getCommandPlayback()?->recordPresentationFailure($error);
      $this->fallbacks->report('Optional battle poses/effects unavailable; retaining base battlefield: '
        . $error->getMessage(), $error);
      return $fallback;
    }
  }

  /** @param list<CanvasTextLayer> $ui */
  private function composeFrame(?BattlePresentationState $field, array $ui, ?BattleHudSnapshot $hud,
    ?string $focus, float $now, bool $includeOptionalArtwork, bool $imageFlips, bool $compositing,
    bool $reducedMotion, bool $maskFlips, bool $imageTones, bool $imageRotation, bool $imageClips, bool $coverageDabs, bool $textureStrip, bool $bottomFade): PresentationCanvas
  {
    $playback = $field?->getCommandPlayback();
    $stageFrame = $includeOptionalArtwork && $playback !== null && !$playback->isCompleted
      ? $playback->plan->getCinematicStageFrame($playback->session->currentFrame, $reducedMotion) : null;
    $stageCanvas = $stageFrame === null ? null : CinematicStagePresentation::compose($stageFrame,
      $playback->plan->getCinematicStageSegments($playback->session->currentFrame), $this->assetRoot, $this->layout->width, $this->layout->height,
      $imageFlips, $compositing);
    if ($stageFrame?->active) {
      // The cutscene owns the battlefield, while the action callout remains screen-space UI.
      // Reuse the same HUD/message composition and stack it above even a cinematic cover.
      $message = $hud === null ? null : new BattleHudSnapshot(message: $hud->message, messageRows: $hud->messageRows);
      $overlay = $this->layout->skin !== null && $message !== null
        ? GraphicalBattleHud::compose($this->layout, $message, null, $now, $this->assetRoot,
          $this->findControlsTheme(), $this->fallbacks)
        : new PresentationCanvas($this->layout->width, $this->layout->height, textLayers: $ui);
      $canvas = PresentationCanvas::composeOverlay($stageCanvas, $overlay);
      CanvasImagePreflight::inspect($canvas->images, $this->assetRoot, $canvas->composites);
      return $canvas;
    }
    $images = PngAssetPreflight::getAvailableSize($this->assetRoot, $this->arena->background->asset) === null
      ? [] : [$this->arena->background];
    $indicators = $text = $composites = $feedbackParticipants = [];
    $cursorBounds = [];
    $feedback = $field?->getFeedback() ?? [];
    $selected = $field?->getSelectedBattlers() ?? [];
    $skin = $this->layout->skin;
    $composition = $skin !== null && $hud !== null
      ? GraphicalBattleHud::compose($this->layout, $hud, $focus, $now, $this->assetRoot, $this->findControlsTheme(),
        $this->fallbacks) : null;
    $hudBounds = array_map(static fn(CanvasImage $image) => $image->destination, $composition?->images ?? []);
    $focused = $field?->getFocusedBattlers() ?? [];
    $queued = $field?->getQueuedBattlers() ?? [];
    $frontline = $this->battle->partyRoster->battlers;
    $enemies = $this->battle->troop->members->toArray();
    $bodies = [];
    $combatantImages = [];
    $bodyOffsets = $subjectFrontImages = [];
    $conditionBattlers = [];
    $conditionPlaybacks = [];
    $commandTone = !$includeOptionalArtwork || $playback === null ? [] : BattleEffectTone::getMultipliers($playback, $reducedMotion);
    if (!$imageTones && $commandTone !== []) {
      $playback->recordPresentationFailure(new RuntimeException('Renderer cannot apply the authored battler image tone.'));
      $commandTone = [];
    }
    $groundAnchors = [];
    $attachmentPoints = [];
    $partySides = [];
    $protectedCombatants = [];
    foreach ($this->getDrawOrder($frontline, $enemies, $playback, $reducedMotion) as $identity => $participant) {
      $battler = $participant['battler'];
      $index = array_search($battler, $participant['party'] ? $frontline : $enemies, true);
      if ($index === false) { continue; }
      ['image' => $image, 'available' => $participant['available'], 'slot' => $slot, 'ground' => $groundAnchors[$identity],
        'pose' => $shownPose, 'usesKnockoutPose' => $usesKnockoutPose, 'usesConcedePose' => $usesConcedePose,
        'elevation' => $elevation, 'contact' => $contact] = $this->projectBattler($identity, $participant, $playback,
        $reducedMotion, $includeOptionalArtwork, $field?->getPoseElapsedSeconds() ?? 0);
      $partySides[$identity] = $participant['party'];
      $bounds = $image->destination;
      // Overlays attach to the body as drawn this frame, following steps, recoil, shake and pose changes.
      $bodies[$identity] = $participant['available'] ? $this->locateBody($image, $identity) : $image->destination;
      $bodyOffsets[$identity] = count($images);
      $popups = array_filter($feedback, static fn(array $popup) => $popup['battler'] === $battler);
      $defeat = $playback?->getEnemyDefeatTreatment($battler, $reducedMotion);
      if (!$participant['party'] && ($playback?->isPresentedKnockedOut($battler) ?? $battler->isKnockedOut) && !$playback?->isAwaitingFallbackHit($battler)
        && ($defeat !== null ? !$defeat['visible'] : $popups === [])) { continue; }
      $conditionBattlers[$identity] = $battler;
      $condition = $this->conditionEffects->createPlayback($battler, $field?->getPoseElapsedSeconds() ?? 0, $includeOptionalArtwork);
      if ($condition !== null) { $conditionPlaybacks[$identity] = $condition; }
      $conditionTone = $condition === null ? [] : BattleEffectTone::getMultipliers($condition, $reducedMotion);
      if (!$imageTones && $conditionTone !== []) {
        $condition->recordPresentationFailure(new RuntimeException('Renderer cannot apply the authored condition image tone.'));
        $conditionTone = [];
      }
      if ($participant['available']) {
        if ($includeOptionalArtwork && !$reducedMotion && $playback?->actor === $battler) {
          $unit = $this->scale !== null && $participant['scale'] !== null
            ? $this->scale->referenceHeight * $participant['scale']->relativeSize * $slot->displayScale
            : $slot->height;
          $wake = $this->getTransitWake($playback, $groundAnchors[$identity], $unit, $imageFlips);
          if ($wake !== null) {
            $images[] = $wake;
            $bodyOffsets[$identity]++;
          }
          foreach ($playback->getFlashStepEchoes() as $echoIndex => $echo) {
            $pose = $this->projectBattler($identity, $participant, $echo['sample'], false, true, $field?->getPoseElapsedSeconds() ?? 0);
            $ghost = $pose['image'];
            if ($pose['available']) {
              $images[] = new CanvasImage('command-echo-' . $identity . '-' . $echoIndex, $ghost->asset,
                $ghost->destination, $ghost->layer, $ghost->sourceRect, $echo['opacity'], $ghost->clipRect,
                $ghost->brightness, $ghost->flipX, $ghost->flipY);
              $bodyOffsets[$identity]++;
            }
          }
        }
        $drawn = new CanvasImage($image->id, $image->asset, $image->destination, $image->layer,
          $image->sourceRect, clipRect: $image->clipRect, opacity: $defeat['opacity'] ?? match (true) {
            $participant['party'] && ($playback?->isPresentedKnockedOut($battler) ?? $battler->isKnockedOut) && !$usesKnockoutPose => 0.4,
            // Without a concede pose, the enemy's current artwork is held, subdued.
            !$participant['party'] && ($battler->hasConceded ?? false) && !$usesConcedePose => $this->defeatStyle->concededOpacity,
            default => 1,
          }, brightness: $image->brightness, flipX: $image->flipX, flipY: $image->flipY);
        $combatantImages[$identity] = BattleEffectTone::applyToImage($drawn,
          ($commandTone[$identity] ?? 1.0) * ($conditionTone[$identity] ?? 1.0));
        $images[] = $bottomFade
          ? BattlerGroundContact::getAquaticBody($combatantImages[$identity], $contact, $groundAnchors[$identity]->y)
          : $combatantImages[$identity];
        $bodyOffsets[$identity]++;
        if ($shownPose !== null) {
          $attachmentPoints[$identity] = $shownPose->projectAttachmentPoints($drawn);
        }
        // Travel already owns the moving ground anchor. Keep the resting contact shape as the
        // travelling artwork changes, so a low weapon or cape cannot become a second footing.
        $contactArtwork = $combatantImages[$identity];
        $contactFootprint = $bounds->height;
        if ($contact === BattlerContact::SHADOW && $elevation <= 0 && !$reducedMotion
          && $playback?->actor === $battler && ($playback->plan->travel?->isPossible ?? false)
          && !$usesKnockoutPose && !$usesConcedePose) {
          $resting = $this->projectBattler($identity, $participant, null, false, $includeOptionalArtwork,
            $field?->getPoseElapsedSeconds() ?? 0);
          $contactArtwork = $resting['available'] ? self::projectTravelContactArtwork($resting['image'],
            $resting['ground'], $groundAnchors[$identity], $image->flipX) : null;
          $contactFootprint = $resting['image']->destination->height;
        }
        $profile = $participant['scale'];
        $surface = BattlerGroundContact::compose('contact-' . $identity, $groundAnchors[$identity]->x, $groundAnchors[$identity]->y,
          $profile !== null && $this->scale !== null ? $this->scale->referenceHeight * $profile->relativeSize * $slot->displayScale
            : $contactFootprint, ($profile?->horizontal ?? false) || $usesKnockoutPose, $bounds->width, $elevation, $contact,
          $this->layout, $this->arena, $participant['grounding'], $this->assetRoot, $field?->getPoseElapsedSeconds() ?? 0,
          $reducedMotion, $combatantImages[$identity]->opacity, $contactArtwork);
        array_push($images, ...$surface['images']);
        if ($compositing) { array_push($composites, ...$surface['composites']); }
        if (($defeat['pulse'] ?? false) && $compositing && $includeOptionalArtwork) {
          try {
            if (!$maskFlips && ($combatantImages[$identity]->flipX || $combatantImages[$identity]->flipY)) {
              throw new RuntimeException('Renderer cannot mirror the defeat pulse image-alpha mask; fade remains.');
            }
            $composites[] = \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageTint::compose(
              'enemy-defeat-' . $identity, $combatantImages[$identity], $defeat['color'], .6, $this->assetRoot);
          } catch (\Throwable $error) { $playback?->recordPresentationFailure($error); }
        } elseif (($defeat['pulse'] ?? false) && !$compositing) {
          $playback?->recordPresentationFailure(new RuntimeException('Renderer cannot tint enemy defeat pulses; fade and clear remain.'));
        }
        $protectedCombatants[] = $bodies[$identity];
      }
      $bounds = $bodies[$identity];
      $lines = $participant['available'] ? [] : [['text' => $battler->name, 'color' => null]];
      if (in_array($battler, $selected, true)) {
        if ($skin === null && $participant['available']) {
          $indicators[] = new CanvasIndicator('selected-' . $identity, $image->id, CanvasIndicatorKind::OUTLINE,
            $bounds, (int)min(2, $bounds->width, $bounds->height), PresentationColor::ansi16(14), 200);
        } elseif ($skin !== null && $participant['available']) {
          $hasSelectionArt = false;
          if ($skin->targetCursor !== null) {
            $cursor = $skin->targetCursor->layout($bounds, $this->layout->width, $this->layout->height, $now,
              $focus === 'target' && in_array($battler, $focused, true) && !\Ichiloto\Engine\UI\Accessibility::prefersReducedMotion(), $hudBounds);
            if ($cursor !== null && CanvasTextureFallback::isAvailable($cursor['texture'], $this->assetRoot)) {
              array_push($images, ...$cursor['texture']->images('target-cursor-' . $identity, $cursor['bounds'], 202));
              $cursorBounds[] = $cursor['envelope'];
              $hasSelectionArt = true;
            }
          } elseif (CanvasTextureFallback::isAvailable($skin->textures['target'], $this->assetRoot)) {
            array_push($images, ...$skin->textures['target']->images('selected-' . $identity, $bounds, 200));
            $hasSelectionArt = true;
          }
          if (!$hasSelectionArt && $participant['available']) {
            $indicators[] = new CanvasIndicator('selected-' . $identity, $image->id, CanvasIndicatorKind::OUTLINE,
              $bounds, (int)min(2, $bounds->width, $bounds->height), $skin->colors['focus'], 200);
          }
          if (in_array($battler, $queued, true)) {
            if (CanvasTextureFallback::isAvailable($skin->textures['queued'], $this->assetRoot)) {
              array_push($images, ...$skin->textures['queued']->images('queued-' . $identity,
                new CanvasRectangle(max(0, $bounds->x + $bounds->width - 32), $bounds->y, 32, 32), 201));
            } else { $lines[] = ['text' => 'Queued', 'color' => $skin->colors['selected']]; }
          }
          if ($skin->targetCursor === null && $focus === 'target' && $battler === ($focused[0] ?? null)
            && CanvasTextureFallback::isAvailable($skin->textures['selector'], $this->assetRoot)) {
            $offset = GraphicalBattleHud::cursorOffset($now, \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
            array_push($images, ...$skin->textures['selector']->images('field-cursor',
              new CanvasRectangle(max(0, min($this->layout->width - 20, $bounds->x - 24)) + $offset,
                max(0, $bounds->y + ($bounds->height - 16) / 2), 16, 16), 202));
          }
        }
        if (!$participant['available']) {
          $lines[0]['color'] = $skin?->colors['focus'] ?? PresentationColor::ansi16(14);
          if (in_array($battler, $queued, true)) { $lines[] = ['text' => 'Queued', 'color' => $skin?->colors['selected']]; }
        }
        if ($participant['available']) { $lines[] = ['text' => $battler->name, 'color' => null]; }
        // A foe whose HP has been revealed shows it beneath its name while it is targeted.
        if (($hp = BattlerInsight::getTargetLine($battler)) !== null) { $lines[] = ['text' => $hp, 'color' => null]; }
      }
      if ($skin !== null) {
        $feedbackParticipants[] = ['id' => $identity, 'bounds' => $bounds, 'persistent' => $lines, 'popups' => $popups];
        continue;
      }
      foreach ($popups as $popup) {
        foreach (GraphicalBattleFeedback::getVisibleLines($popup['lines']) as $line) {
          $color = SgrColorParser::parse($line['color']->value . 'x')['foreground'];
          $lines[] = ['text' => $line['text'], 'color' => $color];
        }
      }
      if ($lines !== []) { $text[] = $this->labels('feedback-' . $identity, $lines, $bounds, $ui); }
    }
    $effects = null;
    $conditionAreas = [];
    foreach ($conditionPlaybacks as $condition) {
      $ambient = GraphicalBattleEffects::compose($condition, $this->layout, $bodies, $this->assetRoot,
        $reducedMotion, $combatantImages, $imageFlips, $compositing, $groundAnchors, $partySides, $maskFlips,
        $attachmentPoints, subjectFrontImages: $subjectFrontImages);
      array_push($images, ...$ambient->images);
      array_push($text, ...$ambient->textLayers);
      array_push($composites, ...$ambient->composites);
      array_push($conditionAreas, ...$ambient->getOverlayProtection());
    }
    if ($playback !== null && $includeOptionalArtwork) {
      $effects = GraphicalBattleEffects::compose($playback, $this->layout, $bodies, $this->assetRoot,
        $reducedMotion, $combatantImages, $imageFlips, $compositing, $groundAnchors, $partySides, $maskFlips,
        $attachmentPoints, $imageRotation, $imageClips, $coverageDabs, $textureStrip, $subjectFrontImages);
      array_push($images, ...$effects->images);
      array_push($text, ...$effects->textLayers);
      array_push($composites, ...$effects->composites);
    }
    if ($composition !== null) {
      array_push($images, ...$composition->images);
      array_push($text, ...$composition->textLayers);
      array_push($composites, ...$composition->composites);
    }
    $cursorBodies = $badgeRecoil = [];
    if ($playback !== null && !$playback->isCompleted && !$reducedMotion) {
      foreach ($playback->targets as $target) {
        $identity = spl_object_id($target);
        if (!isset($conditionBattlers[$identity])) { continue; }
        $badgeRecoil[$identity] = $this->getSlot($target)->width * BattleCommandPlayback::DAMAGE_RECOIL_FRACTION;
      }
    }
    if ($skin?->targetCursor !== null && $playback !== null && !$playback->isCompleted) {
      foreach ([$playback->actor, ...$playback->targets] as $battler) {
        $identity = spl_object_id($battler);
        if (!isset($conditionBattlers[$identity])) { continue; }
        $resting = $this->projectBattler($identity, $this->participants[$identity], null, $reducedMotion,
          $includeOptionalArtwork, $field?->getPoseElapsedSeconds() ?? 0);
        $cursorBodies[$identity] = $resting['available'] ? $this->locateBody($resting['image'], $identity)
          : $resting['image']->destination;
      }
    }
    $badges = GraphicalBattleConditions::compose($this->layout, $conditionBattlers, $bodies, $this->assetRoot, $this->headAttachments,
      $cursorBodies, $cursorBounds, $badgeRecoil);
    array_push($images, ...$badges->images);
    array_push($text, ...$badges->textLayers);
    array_push($conditionAreas, ...$badges->getOverlayProtection());
    if ($skin !== null) {
      array_push($text, ...GraphicalBattleFeedback::compose($this->layout, $feedbackParticipants, $ui, $now,
        [...$hudBounds, ...$cursorBounds, ...$badges->getOverlayProtection()]));
    }
    // Protect participants, effects and HUD content, not the decorative arena behind them.
    $areas = [...$protectedCombatants, ...$cursorBounds, ...$hudBounds, ...$conditionAreas,
      ...array_map(static fn(CanvasTextLayer $layer) => $layer->clipRect ?? $layer->paintBounds, [...$text, ...$ui]),
      ...array_map(static fn(CanvasIndicator $indicator) => $indicator->bounds, $indicators),
      ...($effects?->getOverlayProtection() ?? [])];
    if ($subjectFrontImages !== []) {
      $images = self::orderSubjectEffects($images, $bodyOffsets, $subjectFrontImages);
    }
    $canvas = new PresentationCanvas($this->layout->width, $this->layout->height,
      [...$images, ...($stageCanvas?->images ?? [])], $indicators, CanvasTextBatch::compact([...$text, ...$ui]),
      composites: [...$composites, ...($stageCanvas?->composites ?? [])],
      protectedAreas: [...$areas, ...($stageCanvas?->getOverlayProtection() ?? [])]);
    CanvasImagePreflight::inspect($canvas->images, $this->assetRoot, $canvas->composites);
    return $canvas;
  }

  /** Insert each attached image into the existing ground order at its subject's draw position. */
  private static function orderSubjectEffects(array $images, array $bodyOffsets, array $subjects): array
  {
    $bySubject = $atOffset = $ordered = [];
    foreach ($images as $image) {
      if (isset($subjects[$image->id])) { $bySubject[$subjects[$image->id]][] = $image; }
    }
    foreach ($bodyOffsets as $identity => $offset) {
      if (isset($bySubject[$identity])) {
        $atOffset[$offset] ??= [];
        array_push($atOffset[$offset], ...$bySubject[$identity]);
      }
    }
    foreach ($images as $offset => $image) {
      array_push($ordered, ...($atOffset[$offset] ?? []));
      if (!isset($subjects[$image->id])) { $ordered[] = $image; }
    }
    return $ordered;
  }

  /**
   * @return array{image: CanvasImage, available: bool, slot: BattlerSlot, ground: Vector2,
   *   pose: ?BattlerPose, usesKnockoutPose: bool, usesConcedePose: bool, elevation: float, contact: BattlerContact}
   */
  /** The wake follows the drawn ground pivot and the route's current direction. */
  private function getTransitWake(BattleCommandPlayback $playback, Vector2 $ground, float $unit, bool $imageFlips): ?CanvasImage
  {
    $wake = $playback->plan->flashStep->wake ?? null;
    $travel = $playback->plan->travel;
    $phase = $playback->phase;
    if ($wake === null || !$travel?->isPossible || $playback->isCompleted || !in_array($phase, ['advance', 'return'], true)) {
      return null;
    }
    $window = $playback->plan->phases[$phase];
    $elapsed = $playback->session->currentFrame - $window['start'];
    $index = intdiv($elapsed * count($wake->opacityPercent), $window['length']);
    $opacity = $wake->opacityPercent[$index] / 100;
    if ($opacity <= 0) { return null; }
    $returning = $phase === 'return';
    $direction = $travel->getHorizontalDirectionAtProgress($playback->getAdvanceFraction()) * ($returning ? -1 : 1);
    $flip = $direction > 0;
    if ($flip && !$imageFlips) {
      $playback->recordPresentationFailure(new RuntimeException('Renderer cannot mirror the authored transit wake.'));
      return null;
    }
    $size = PngAssetPreflight::getAvailableSize($this->assetRoot, $wake->image);
    $crop = $wake->crop;
    if ($size === null || $crop->x + $crop->width > $size['width'] || $crop->y + $crop->height > $size['height']) {
      $playback->recordPresentationFailure(new RuntimeException('Transit wake crop is unavailable in ' . $wake->image));
      return null;
    }
    $horizontalSpan = abs($travel->getPositionAtProgress(1)->x - $travel->getPositionAtProgress(0)->x);
    $width = max(1, round(min($wake->maxWidth * $unit, $wake->baseWidth * $unit + $horizontalSpan * $wake->widthPerDistance)));
    $height = max(1, round($wake->height * $unit));
    $gap = $returning ? 0 : $wake->outboundGap * $unit;
    $x = $ground->x + ($flip ? -$gap - $width : $gap);
    try {
      return CanvasImage::createClipped('command-transit-wake', $wake->image, round($x), round($ground->y + $wake->offsetY * $unit),
        $width, $height, $this->layout->width, $this->layout->height, BattleCanvasLayout::BATTLER_LAYER - 1, $crop,
        flipX: $flip, opacity: $opacity);
    } catch (\InvalidArgumentException $error) {
      $playback->recordPresentationFailure($error);
      return null;
    }
  }

  /** Places the resting contact sample at the current ground point and facing. */
  private static function projectTravelContactArtwork(CanvasImage $resting, Vector2 $restingGround, Vector2 $ground,
    bool $flipX): CanvasImage
  {
    $box = $resting->destination;
    $relativeX = $box->x - $restingGround->x;
    if ($flipX !== $resting->flipX) { $relativeX = -$relativeX - $box->width; }
    return new CanvasImage($resting->id, $resting->asset,
      new CanvasRectangle($ground->x + $relativeX, $ground->y + $box->y - $restingGround->y,
        $box->width, $box->height, allowOutsideCanvas: true),
      $resting->layer, $resting->sourceRect, flipX: $flipX, flipY: $resting->flipY);
  }

  private function projectBattler(int $identity, array $participant, ?BattleCommandPlayback $playback,
    bool $reducedMotion, bool $includeOptionalArtwork, float $idleSeconds, ?BattlerPose $guardPose = null): array
  {
    $battler = $participant['battler'];
    $image = $participant['image'];
    $size = PngAssetPreflight::getAvailableSize($this->assetRoot, $image->asset);
    $available = $size !== null;
    $art = $size === null ? $participant['art'] : $participant['art']->clampedTo($size['width'], $size['height']);
    $slot = $this->getSlot($battler);
    $bounds = $this->placeBaseArtwork($slot, $available ? $art : null,
      $participant['poses'], $identity, $participant['scale']);
    $artworkScale = $available ? $bounds->width / $art->width / $slot->displayScale : null;
    $role = $playback?->getPoseRole($battler, $participant['poses'], $reducedMotion) ?? BattlePoseRole::getRestingRole($battler);
    $actionPose = $includeOptionalArtwork ? ($guardPose ?? $playback?->getBoundActionPose($battler, $role, $reducedMotion)) : null;
    $pose = $includeOptionalArtwork ? ($actionPose ?? $participant['poses']?->getPose($role)) : null;
    $usesKnockoutPose = false;
    $usesConcedePose = false;
    $shownPose = null;
    if ($includeOptionalArtwork && $pose === null && $role !== BattlePoseRole::IDLE) {
      $diagnostic = $identity . ':' . $role->value . ':unregistered';
      if (!isset($this->poseDiagnostics[$diagnostic])) {
        $actorId = $battler instanceof Character ? $battler->actorId : $battler->name;
        Debug::warn("No battle pose registered for {$actorId}: {$role->value}; retaining available resting artwork.");
        $this->poseDiagnostics[$diagnostic] = true;
      }
    }
    $candidates = [];
    if ($actionPose !== null) { $candidates[] = [$role->value, $actionPose]; }
    if ($includeOptionalArtwork) {
      foreach (array_unique([$role->value, BattlePoseRole::getRestingRole($battler)->value, BattlePoseRole::IDLE->value]) as $candidateRole) {
        $candidate = $participant['poses']?->getPose(BattlePoseRole::from($candidateRole));
        if ($candidate !== null && $candidate !== $actionPose) { $candidates[] = [$candidateRole, $candidate]; }
      }
    }
    foreach ($candidates as [$candidateRole, $pose]) {
      try {
        $posed = $pose->getArtwork($this->assetRoot,
          $playback !== null && $candidateRole === $role->value && $role !== BattlePoseRole::IDLE
            ? $playback->getPoseElapsedSeconds($battler, $role) : $idleSeconds, $reducedMotion,
          impactAtSeconds: $playback !== null && $candidateRole === $role->value && $role === $playback->pose
            ? $playback->getPoseImpactSeconds($battler) : null,
          durationSeconds: $playback !== null && $candidateRole === $role->value
            ? $playback->getPoseDurationSeconds($battler, $role) : null,
          sequence: match (true) {
            $pose === $guardPose => BattlePoseSequence::GUARD,
            $playback !== null && $candidateRole === $role->value => $playback->getPoseSequence($battler, $role),
            default => BattlePoseSequence::MAIN,
          },
          useFarPose: $pose === $actionPose && ($playback?->plan->travel?->usesFarPose ?? false),
          arrivalPose: $pose === $actionPose ? $playback?->plan->travel?->arrivalPose : null);
        if ($posed === null) { throw new RuntimeException('Pose image is unavailable.'); }
        $posedBounds = BattleFormationLayout::getPosePlacement($slot, $posed, $artworkScale,
          $this->layout, $participant['scale'], $this->scale, $pose->scaleSpan, $participant['poses']);
        $art = $posed;
        $bounds = $posedBounds;
        $available = true;
        $usesKnockoutPose = $candidateRole === BattlePoseRole::KNOCKOUT->value;
        $usesConcedePose = $candidateRole === BattlePoseRole::CONCEDE->value;
        $shownPose = $pose;
        break;
      } catch (\InvalidArgumentException|RuntimeException $error) {
        $diagnostic = $identity . ':' . $candidateRole . ':' . $pose->asset;
        if (!isset($this->poseDiagnostics[$diagnostic])) {
          Debug::warn('Battle pose unavailable; retaining available resting artwork: ' . $diagnostic . ': ' . $error->getMessage());
          $this->poseDiagnostics[$diagnostic] = true;
        }
      }
    }
    // The body rises or sinks by its elevation, hidden below the surface line; a knocked-out one lies on the ground.
    $elevation = BattlerGroundContact::getElevation($participant['grounding'], $participant['habitat'], $this->layout,
      ($playback?->isPresentedKnockedOut($battler) ?? $battler->isKnockedOut) && !$playback?->isAwaitingFallbackHit($battler), $shownPose);
    $contact = BattlerGroundContact::getContact($participant['grounding'], $participant['habitat'], $elevation, $shownPose);
    [$bounds, $clip] = BattlerGroundContact::place($bounds, BattlerGroundContact::getOffset($elevation, $slot, $this->scale),
      $slot->y, $this->layout);
    $travel = !$reducedMotion && $playback?->actor === $battler ? $playback->plan->travel : null;
    $mirror = $travel?->isPossible && $travel->facingSign !== BattleEffectDirection::getOpponentsSign($participant['party']);
    if ($mirror) { $bounds = new CanvasRectangle(2 * $slot->x - $bounds->x - $bounds->width, $bounds->y, $bounds->width, $bounds->height); }
    $offset = $travel !== null ? $playback->getTravelOffset() : new Vector2(0, 0);
    $advance = $travel === null && !$reducedMotion && $playback?->actor === $battler ? $playback->getAdvanceFraction() : 0;
    $recoil = ($playback?->getRecoilFraction($battler, $reducedMotion) ?? 0)
      + ($playback?->getShakeFraction($battler, $reducedMotion) ?? 0);
    $unmovedX = $bounds->x;
    $unmovedY = $bounds->y;
    $bounds = new CanvasRectangle(clamp($bounds->x + $offset->x + $slot->width * $recoil
      + BattleEffectDirection::getOpponentsSign($participant['party']) * $slot->width * .25 * $advance,
      0, max(0, $this->layout->width - $bounds->width)),
      clamp($bounds->y + $offset->y, 0, max(0, $this->layout->height - $bounds->height)), $bounds->width, $bounds->height);
    $ground = new Vector2($slot->x + ($bounds->x - $unmovedX), $slot->y + ($bounds->y - $unmovedY));
    $image = new CanvasImage($image->id, $art->asset, $bounds, $image->layer, $art->sourceRect,
      $image->opacity, $clip === null ? null : new CanvasRectangle($bounds->x, $clip->y + $offset->y, $bounds->width, $clip->height),
      $image->brightness, $mirror ? !$image->flipX : $image->flipX, $image->flipY);
    return ['image' => $image, 'available' => $available, 'slot' => $slot, 'ground' => $ground,
      'pose' => $shownPose, 'usesKnockoutPose' => $usesKnockoutPose, 'usesConcedePose' => $usesConcedePose,
      'elevation' => $elevation, 'contact' => $contact];
  }

  /** Bind the command's authored graphical anchor captures before its clock starts. */
  public function bindCommandPosePoints(BattleCommandPlayback $playback, bool $reducedMotion, float $idleSeconds): void
  {
    $snapshotFrame = array_find($playback->plan->timeline->cueSchedule,
      static fn(array $cue): bool => $cue['type'] === 'commandCaptureTargetSnapshot')['frame'] ?? null;
    $geometryFrame = null;
    $geometry = [];
    // Every capture on one command frame shares the exact same projected poses.
    $captureGeometry = function (BattleCommandPlayback $sample) use ($reducedMotion, $idleSeconds, $snapshotFrame, &$geometryFrame, &$geometry): array {
      if ($snapshotFrame !== null && $geometryFrame === $snapshotFrame
        && $sample->session->currentFrame === $snapshotFrame) { return $geometry; }
      $clock = $idleSeconds + $sample->session->currentFrame / BattleCommandTimeline::FPS;
      $next = [];
      foreach ([$sample->actor, ...$sample->targets] as $battler) {
        $id = spl_object_id($battler);
        if (isset($next[$id])) { continue; }
        $participant = $this->participants[$id] ?? throw new RuntimeException('Captured effect subject is absent from the battlefield.');
        $next[$id] = $this->projectBattler($id, $participant, $sample, $reducedMotion, true, $clock);
      }
      $geometry = $next;
      $geometryFrame = $sample->session->currentFrame;
      return $geometry;
    };
    if (array_any($playback->plan->timeline->cueSchedule, static fn(array $cue): bool =>
      in_array($cue['type'], ['commandCaptureTargetGroup', 'commandCaptureTargetContacts', 'commandCaptureTargetSnapshot'], true))) {
      $playback->bindTargetGroupCapture(function (BattleCommandPlayback $sample) use ($captureGeometry): ?BattleEffectTargetGroup {
        $geometry = $captureGeometry($sample);
        $bounds = $grounds = $sides = [];
        foreach ($sample->targets as $target) {
          $id = spl_object_id($target);
          $participant = $this->participants[$id] ?? throw new RuntimeException('Target-group effect subject is absent from the battlefield.');
          $projected = $geometry[$id];
          $image = $projected['image'];
          $bounds[$id] = $projected['available'] ? $this->locateBody($image, $id) : $image->destination;
          $grounds[$id] = $projected['ground'];
          $sides[$id] = $participant['party'];
        }
        return BattleEffectTargetGroup::collectTargets($sample->targets, $bounds, $grounds, $sides);
      });
    }
    if (!array_any($playback->plan->timeline->cueSchedule, static fn(array $cue): bool =>
      $cue['type'] === 'commandCapturePosePoint')) { return; }
    $playback->bindPosePointCapture(function (BattleCommandPlayback $sample, string $anchor, string $name, ?string $route, ?array $curve,
      ?float $clearance, ?string $contact, ?string $fallback) use ($reducedMotion, $idleSeconds, $captureGeometry): array {
      static $shotFrame = null, $straightShots = [];
      if ($shotFrame !== $sample->session->currentFrame) {
        $shotFrame = $sample->session->currentFrame;
        $straightShots = [];
      }
      $clock = $idleSeconds + $sample->session->currentFrame / BattleCommandTimeline::FPS;
      $geometry = $captureGeometry($sample);
      $obstacles = [];
      if ($clearance !== null) {
        foreach ([...$this->battle->partyRoster->battlers, ...$this->battle->troop->members->toArray()] as $bystander) {
          $id = spl_object_id($bystander);
          if (!$bystander->isFighting || isset($geometry[$id]) || !isset($this->participants[$id])) { continue; }
          $body = $this->projectBattler($id, $this->participants[$id], $sample, $reducedMotion, true, $clock);
          if ($body['available']) { $obstacles[] = $this->locateBody($body['image'], $id); }
        }
      }
      $casterX = $geometry[spl_object_id($sample->actor)]['ground']->x;
      $firstOther = array_find($sample->targets, static fn($target): bool => $target !== $sample->actor);
      $points = [];
      foreach ($anchor === 'caster' ? [$sample->actor] : $sample->targets as $subject) {
        $id = spl_object_id($subject);
        $projected = $geometry[$id];
        $point = $projected['pose']?->projectAttachmentPoints($projected['image'])[$name] ?? null;
        if ($point === null) { throw new RuntimeException('Release pose has no effect attachment point ' . $name . '.'); }
        $recipient = $anchor === 'caster' ? $firstOther : $subject;
        $points[$id] = ['point' => $point, 'casterX' => $casterX,
          'recipientX' => $recipient === null ? null : $geometry[spl_object_id($recipient)]['ground']->x,
          'party' => $this->participants[$id]['party']];
        if ($route !== null) {
          if ($sample->targets === []) { throw new RuntimeException('Battle projectile has no selected recipients.'); }
          if ($route === EffectFlightRoute::STRAIGHT->value) {
            $targets = array_map(static fn($target): CanvasImage => $geometry[spl_object_id($target)]['image'], $sample->targets);
            $contactMode = $contact === null ? EffectFlightContact::EDGE : EffectFlightContact::from($contact);
            $fallbackPolicy = $fallback === null ? null : EffectStraightFlightFallback::from($fallback);
            $key = $id . ':' . $name . ':' . $contactMode->value . ':' . $fallback;
            if (!array_key_exists($key, $straightShots)) {
              $straightShots[$key] = BattleStraightShot::planRoute($point, $targets, $this->assetRoot, $this->layout->width,
                $this->participants[$id]['party'], $contactMode, $fallbackPolicy);
            }
            $shot = $straightShots[$key]
              ?? throw new RuntimeException('No straight battle flight can reach the selected targets in order.');
            $points[$id]['path'] = $shot['path'];
            $points[$id]['fallback'] = $shot['fallback'];
            $points[$id]['targetOrder'] = array_map(
              static fn(int $index): int => spl_object_id($sample->targets[$index]), $shot['targetOrder']);
            continue;
          }
          $waypoints = [[$point->x, $point->y]];
          $contactBodies = [];
          $usesVisibleContact = $route !== EffectFlightRoute::WAYPOINTS->value;
          foreach ($sample->targets as $target) {
            $image = $geometry[spl_object_id($target)]['image'];
            if ($usesVisibleContact) {
              $contactBodies[] = PngAssetBounds::getVisibleBounds($this->assetRoot, $image)['bounds']
                ?? throw new RuntimeException('Battle flight requires visible target artwork.');
            } else {
              $body = $image->destination;
              $waypoints[] = [$body->x + $body->width / 2, $body->y + $body->height / 2];
            }
          }
          $points[$id]['path'] = $usesVisibleContact
            ? $this->planCubicFlight($point, $contactBodies, $curve, $obstacles, $clearance)
            : new BattlePresentationPath($waypoints);
        }
      }
      return $points;
    }, $this->layout->width);
  }

  /**
   * @param list<CanvasRectangle> $bodies
   * @param list<CanvasRectangle> $obstacles
   */
  private function planCubicFlight(Vector2 $launch, array $bodies, ?array $curve, array $obstacles,
    ?float $clearance): BattlePresentationPath
  {
    if (count($bodies) !== 1 || $curve === null) {
      throw new RuntimeException('Cubic battle flight requires one selected recipient and two curve controls.');
    }
    $body = $bodies[0];
    $arrival = new Vector2($body->x + $body->width / 2, $body->y + $body->height / 2);
    $span = $arrival->x - $launch->x;
    $first = new Vector2($launch->x + $span * $curve['launchProgress'],
      $launch->y + $this->layout->height * $curve['launchOffsetY']);
    $second = new Vector2($launch->x + $span * $curve['arrivalProgress'],
      $arrival->y + $this->layout->height * $curve['arrivalOffsetY']);
    if ($clearance === null) { return BattlePresentationPath::fromCubic($launch, $arrival, $first, $second); }
    return BattleCubicFlightClearance::plan($launch, $arrival, $first, $second, $this->layout->height,
      $obstacles, $clearance, width: $this->layout->width)
      ?? throw new RuntimeException('No battle trail route clears the unselected bodies at the captured positions.');
  }

  /**
   * Standing participants from back to front, so a nearer battler is drawn
   * over one behind it; equal depths keep party before troop, then member order.
   *
   * @return array<int, array> Identity => participant.
   */
  private function getDrawOrder(array $frontline, array $enemies, ?BattleCommandPlayback $playback = null, bool $reducedMotion = false): array
  {
    $standing = array_filter($this->participants, static fn(array $participant): bool =>
      in_array($participant['battler'], $participant['party'] ? $frontline : $enemies, true));
    $order = BattleStaging::getDrawOrder(array_map(function (array $participant) use ($playback, $reducedMotion): BattlerSlot {
      $slot = $this->getSlot($participant['battler']);
      if ($reducedMotion || $playback?->actor !== $participant['battler'] || $playback->plan->travel === null) { return $slot; }
      $offset = $playback->getTravelOffset();
      return new BattlerSlot($slot->x + $offset->x, $slot->y + $offset->y, $slot->width, $slot->height, $slot->displayScale);
    }, $standing));
    return array_combine($order, array_map(static fn(int $identity): array => $standing[$identity], $order));
  }

  /** @param list<array{text: string, color: ?PresentationColor}> $lines @param list<CanvasTextLayer> $ui */
  private function labels(string $id, array $lines, CanvasRectangle $bounds, array $ui): CanvasTextLayer
  {
    $cellWidth = $this->layout->uiGrid->cellWidth;
    $cellHeight = $this->layout->uiGrid->cellHeight;
    $runs = [];
    $columns = 1;
    foreach ($lines as $row => $line) {
      $text = mb_substr(TerminalText::stripAnsi($line['text']), 0, min(128, intdiv($this->layout->width, $cellWidth)), 'UTF-8');
      $columns = max($columns, mb_strlen($text, 'UTF-8'));
      $runs[] = new PresentationTextRun($row, 0, $text, $line['color'], PresentationColor::rgb(15, 23, 30));
    }
    $grid = new RendererGridConfig($columns, count($runs), $cellWidth, $cellHeight);
    $placement = BattleFeedbackPlacement::place($bounds, $columns * $cellWidth, count($runs) * $cellHeight,
      $this->layout->width, $this->layout->height, $ui);
    $placement->assertWithin($this->layout->width, $this->layout->height);
    return new CanvasTextLayer($id, 300, $placement->x, $placement->y, $grid, $runs);
  }
}
