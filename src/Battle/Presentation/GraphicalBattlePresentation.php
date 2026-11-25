<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\ActionMovement;
use Ichiloto\Engine\Animations\Timelines\EffectFlightContact;
use Ichiloto\Engine\Animations\Timelines\EffectHorizontalFacing;
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
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationFallbackReport;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use RuntimeException;
use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;
use Ichiloto\Engine\Rendering\Assets\{AssetHoldResource, AssetHoldValidation, AssetHoldRejection, AssetHoldCanvas, AssetHoldDrawSize, AssetHoldBase};

/** Holds identity/layout, not combat state. Generating a frame has no gameplay effects. */
final class GraphicalBattlePresentation
{
  /** @var array<int, array{battler: CharacterInterface, party: bool, image: CanvasImage, art: BattlerArtwork, available: bool, artBound: bool, poses: ?BattlePoseSet, scale: ?BattlerScale, grounding: ?BattlerGrounding, habitat: ?EnemyHabitat, strikePoint: ?BattlerStrikePoint}> */
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
    private readonly string $assetRoot, private readonly ?BattleScale $scale, ?ConfigInterface $configuration)
  {
    $this->headAttachments = new BattleHeadAttachmentLayout();
    $this->fallbacks = new PresentationFallbackReport();
    $this->defeatStyle = EnemyDefeatStyle::createFromConfig($configuration);
    $this->conditionEffects = BattleConditionEffects::createFromConfig(
      new \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary($assetRoot), $configuration);
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

  /** Collect the source crops before staging assigns any canvas geometry. */
  private function addScenePoseFacts(BattleArtworkFacts $facts): void
  {
    foreach ($this->participants as $participant) {
      if ($participant['available']) { $facts->addArtwork($participant['art']); }
      foreach ($participant['poses']?->roles ?? [] as $pose) {
        try {
          $facts->addPose($pose);
        } catch (\InvalidArgumentException|RuntimeException $failure) {
          Debug::warn('Battle pose facts unavailable: ' . $pose->asset . ': ' . $failure->getMessage());
        }
      }
    }
    foreach ($this->layout->contactEffects->getDeclaredPoses() as $pose) {
      $facts->addPose($pose);
    }
  }

  /** The selected sheet includes any guard or travel cell needed before the command clock starts. */
  public function prepareActionArtwork(BattlerPose $pose): BattleArtworkPreparation
  {
    return new BattleArtworkPreparation(fn(): BattleArtworkFacts =>
      PngAssetPreflight::runWithSnapshot(function () use ($pose): BattleArtworkFacts {
        $facts = new BattleArtworkFacts($this->assetRoot);
        $facts->addPose($pose);
        return $facts;
      }));
  }

  /** Read the current participant cohort before staging or any command clock can begin. */
  private function preparePoseFacts(): void
  {
    PngAssetPreflight::runWithSnapshot(function (): void {
      $span = LatencyTrace::beginSpan();
      $facts = new BattleArtworkFacts($this->assetRoot);
      try {
        $this->addScenePoseFacts($facts);
        $facts->prepare();
      }
      finally { LatencyTrace::endSpan('battle.pose_facts', $span, ['crops' => $facts->countCrops()]); }
    });
  }

  /**
   * Declare roster-reachable bodies and shared role poses before a command clock starts.
   * Party resources cover each initial frontline place so swaps do not discover an unprepared body size.
   * @param list<AssetHoldResource>|array<string, AssetHoldResource> $additional Owned UI/control resources.
   * @return array<string, AssetHoldResource>
   */
  public function collectBattleResources(array $additional = [], ?BattleEffectResources $effects = null): array
  {
    return PngAssetPreflight::runWithSnapshot(function () use ($additional, $effects): array {
      $resources = [];
      $canvas = AssetHoldCanvas::fromCanvas(new PresentationCanvas($this->layout->width, $this->layout->height));
      foreach ($additional as $resource) { self::addAssetResource($resources, $resource); }
      foreach ($effects?->collectBattleResources($this->battle) ?? [] as $resource) { self::addAssetResource($resources, $resource); }
      foreach ($this->conditionEffects->getDeclaredTimelines() as $timeline) {
        foreach (BattleEffectResources::collectTimelineResources($timeline, $this->assetRoot) as $resource) {
          self::addAssetResource($resources, $resource);
        }
      }
      self::addAssetResource($resources, AssetHoldResource::fromCanvasImage($this->arena->background, $canvas));
      foreach ($this->participants as $participant) {
        $art = $this->findBoundBaseArtwork($participant);
        if ($participant['artBound'] && $art === null) {
          // Preserve the real missing binding for renderer admission; an unbound name fallback has no asset.
          self::addAssetResource($resources, new AssetHoldResource($participant['art']->asset));
        }
        $slots = $participant['party'] ? $this->getStaging()->party : [$this->getSlot($participant['battler'])];
        foreach ($slots as $slot) {
          $base = BattleFormationLayout::getBasePlacement($slot, $art, $this->layout,
            $participant['poses'], $participant['scale'], $this->scale)['bounds'];
          $basePixelScale = $art === null ? null : $base->width / $art->width / $slot->displayScale;
          if ($art !== null) {
            self::addAssetResource($resources, AssetHoldResource::fromCanvasImage(new CanvasImage('battle-preparation',
              $art->asset, $base, sourceRect: $art->sourceRect), $canvas));
          }
          foreach ($participant['poses']?->roles ?? [] as $pose) {
            $this->addPoseResources($resources, $pose, $pose->getDeclaredSourceCells(), $slot, $basePixelScale,
              $participant, $canvas);
          }
        }
      }
      // Surface width depends on the current submerged silhouette. Keep these small cells at master resolution.
      foreach ($this->layout->contactEffects->getDeclaredPoses() as $pose) {
        foreach ($pose->getDeclaredSourceCells() as $cell) {
          $art = $pose->getArtworkForCell($this->assetRoot, $cell);
          self::addAssetResource($resources, new AssetHoldResource($pose->asset, $art?->sourceRect));
        }
      }
      return $resources;
    });
  }

  private function findBoundBaseArtwork(array $participant): ?BattlerArtwork
  {
    $art = $participant['art'];
    $size = $participant['artBound'] ? PngAssetPreflight::getAvailableSize($this->assetRoot, $art->asset) : null;
    return $size === null ? null : $art->clampedTo($size['width'], $size['height']);
  }

  /** Prepare only the focused or committed actor's bound animation pose.
   * @return array<string, AssetHoldResource>
   */
  public function collectActionPoseResources(CharacterInterface $actor, BattlerPose $pose, bool $reducedMotion,
    ?BattleCommandPlayback $playback = null): array
  {
    return PngAssetPreflight::runWithSnapshot(function () use ($actor, $pose, $reducedMotion, $playback): array {
      $identity = spl_object_id($actor);
      $participant = $this->participants[$identity] ?? throw new RuntimeException('Action pose owner is not in this battle.');
      $slot = $this->getSlot($actor);
      $art = $this->findBoundBaseArtwork($participant);
      $base = BattleFormationLayout::getBasePlacement($slot, $art, $this->layout,
        $participant['poses'], $participant['scale'], $this->scale);
      $pixelScale = $art === null ? null : $base['bounds']->width / $art->width / $slot->displayScale;
      $canvas = AssetHoldCanvas::fromCanvas(new PresentationCanvas($this->layout->width, $this->layout->height));
      [$grounds, $sides] = $this->collectFacingGrounds();
      $opponents = BattleEffectDirection::getOpponentsFacing($identity, $grounds, $sides)
        ?? throw new RuntimeException('Action pose owner lost its facing ground.');
      $facing = $playback === null ? $opponents : $this->resolveCommandFacing($playback, $actor, $slot->x, $opponents);
      $mirror = $facing !== BattleEffectDirection::getAuthoredFacing($participant['party']);
      $cells = $reducedMotion ? [$pose->restFrame] : $pose->getDeclaredSourceCells();
      $resources = [];
      $this->addPoseResources($resources, $pose, $cells, $slot, $pixelScale, $participant, $canvas, $mirror);
      return $resources;
    });
  }

  /**
   * @param array<string, AssetHoldResource> $resources
   * @param list<int> $cells
   */
  private function addPoseResources(array &$resources, BattlerPose $pose, array $cells, BattlerSlot $slot,
    ?float $basePixelScale, array $participant, AssetHoldCanvas $canvas, bool $mirror = false): void
  {
    foreach ($cells as $cell) {
      $posed = $pose->getArtworkForCell($this->assetRoot, $cell);
      if ($posed === null) {
        self::addAssetResource($resources, new AssetHoldResource($pose->asset));
        return;
      }
      $size = BattleFormationLayout::getPoseDrawSize($slot, $posed, $basePixelScale,
        $this->layout, $participant['scale'], $this->scale, $pose->scaleSpan, $participant['poses']);
      self::addAssetResource($resources, new AssetHoldResource($posed->asset, $posed->sourceRect,
        new AssetHoldBase(flipX: $mirror), new AssetHoldDrawSize($size['width'], $size['height']), $canvas));
    }
  }

  /** @param array<string, AssetHoldResource> $resources */
  private static function addAssetResource(array &$resources, AssetHoldResource $resource): void
  {
    // This is a runtime descriptor key; it never hashes artwork or becomes authored character identity.
    $resources[$resource->getKey()] = $resource;
    if (count($resources) > AssetHoldValidation::MAX_LEASES) {
      AssetHoldValidation::reject(AssetHoldRejection::LEASE_LIMIT, 'Battle resources exceed 1024 unique preparations.');
    }
  }

  /** Persist the arena and known battler preparations through an exclusive cinematic.
   * Uses the same projection and tone/ground treatments as drawing, without submitting hidden drawings or firing cues.
   * Selected poses declare their authored cells; calling again after resolution includes actual outcome poses.
   * @return array<string, AssetHoldResource>
   */
  public function collectReturnResources(BattleCommandPlayback $playback, float $idleSeconds,
    bool $reducedMotion, bool $imageFlips, bool $bottomFade): array
  {
    $samples = [null, $playback];
    foreach ($playback->plan->getCinematicReturnFrames() as $frame) { $samples[] = $playback->createPresentationSample($frame); }
    return $this->collectFieldResources($samples, $idleSeconds, $reducedMotion, $imageFlips, $bottomFade);
  }

  /** Declare one scale sufficient for every sampled field frame, including idle cells that may cycle during a wait.
   * @param array<int, float> $scales
   * @return array<string, AssetHoldResource>
   */
  public function collectScaledFieldResources(BattleCommandPlayback $playback, array $scales, float $idleSeconds,
    bool $imageFlips, bool $bottomFade): array
  {
    if ($scales === []) { return []; }
    $samples = array_map($playback->createPresentationSample(...), array_keys($scales));
    return $this->collectFieldResources($samples, $idleSeconds, false, $imageFlips, $bottomFade, max($scales));
  }

  /** @param list<?BattleCommandPlayback> $samples @return array<string, AssetHoldResource> */
  private function collectFieldResources(array $samples, float $idleSeconds, bool $reducedMotion,
    bool $imageFlips, bool $bottomFade, ?float $sampleScale = null): array
  {
    return PngAssetPreflight::runWithSnapshot(function () use ($samples, $idleSeconds, $reducedMotion, $imageFlips, $bottomFade, $sampleScale): array {
      $resources = [];
      $canvas = AssetHoldCanvas::fromCanvas(new PresentationCanvas($this->layout->width, $this->layout->height));
      $describe = static function (CanvasImage $image) use ($canvas, $sampleScale): AssetHoldResource {
        $resource = AssetHoldResource::fromCanvasImage($image, $canvas);
        if ($sampleScale === null || ($image->rotation ?? 0) !== 0) { return $resource; }
        // Round the required extent up in root units before the renderer
        // applies its fit and device scale. Never undersample the envelope.
        return new AssetHoldResource($resource->asset, $resource->sourceRect, $resource->base,
          new AssetHoldDrawSize(ceil($image->destination->width * $sampleScale),
            ceil($image->destination->height * $sampleScale)), $canvas);
      };
      if (PngAssetPreflight::getAvailableSize($this->assetRoot, $this->arena->background->asset) !== null) {
        self::addAssetResource($resources, $describe($this->arena->background));
      }
      $frontline = $this->battle->partyRoster->battlers;
      $enemies = $this->battle->troop->members->toArray();
      foreach ($samples as $sample) {
        $tone = $sample === null ? [] : BattleEffectTone::getMultipliers($sample, $reducedMotion);
        foreach ($this->getDrawOrder($frontline, $enemies, $sample, $reducedMotion) as $identity => $participant) {
          $projected = $this->projectBattler($identity, $participant, $sample, $reducedMotion, true,
            $idleSeconds, imageFlips: $imageFlips);
          if (!$projected['available'] || !$projected['isOnCanvas']) { continue; }
          $condition = $sampleScale === null ? null : $this->conditionEffects->createPlayback($participant['battler'], $idleSeconds, true);
          $conditionTone = $condition === null ? [] : BattleEffectTone::getMultipliers($condition, $reducedMotion);
          $image = BattleEffectTone::applyToImage($projected['image'], ($tone[$identity] ?? 1.0) * ($conditionTone[$identity] ?? 1.0));
          $paint = $bottomFade && $projected['elevation'] < 0
            ? BattlerGroundContact::getAquaticBody($image, $projected['contact'], $projected['ground']->y) : $image;
          if ($paint === null) { continue; }
          $resource = $describe($paint);
          self::addAssetResource($resources, $resource);
          $pose = $projected['pose'];
          if ($pose === null) { continue; }
          $usesCommandPose = $sample?->actor === $participant['battler'];
          $cells = $reducedMotion ? [$pose->restFrame] : array_unique([
            ...$pose->getSequenceFrames(BattlePoseSequence::MAIN,
              $usesCommandPose && ($sample?->plan->travel?->usesFarPose ?? false),
              $usesCommandPose ? $sample?->plan->travel?->arrivalPose : null),
            ...$pose->entryFrames, ...$pose->exitFrames,
            ...($pose->guardFrame === null ? [] : [$pose->guardFrame]),
          ]);
          foreach ($cells as $cell) {
            $art = $pose->getArtworkForCell($this->assetRoot, $cell);
            if ($art !== null) { self::addAssetResource($resources, new AssetHoldResource($art->asset, $art->sourceRect, $resource->base,
              $resource->drawSize, $resource->canvas)); }
          }
        }
      }
      return $resources;
    });
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
    // Routing consumes current body geometry, independent of arena/HUD composition and native allocations.
    $images = PngAssetPreflight::runWithSnapshot(function () use ($poseElapsedSeconds): array {
      $drawn = [];
      foreach ($this->getDrawOrder($this->battle->partyRoster->battlers, $this->battle->troop->members->toArray()) as $id => $participant) {
        $projected = $this->projectBattler($id, $participant, null, false, true, $poseElapsedSeconds);
        if ($projected['available'] && $projected['isOnCanvas']) { $drawn[$id] = $projected['image']; }
      }
      $bounds = PngAssetBounds::getVisibleBoundsForImages($this->assetRoot, array_values($drawn));
      $images = [];
      foreach (array_keys($drawn) as $index => $id) {
        $body = $bounds[$index]['bounds'];
        if ($body === null) { continue; }
        $slot = $this->getSlot($this->participants[$id]['battler']);
        $images[$id] = ['image' => $drawn[$id], 'body' => $body, 'foot' => new Vector2($slot->x, $slot->y)];
      }
      return $images;
    });
    if (!isset($images[$sourceId], $images[$targetId])) { throw new RuntimeException('Travel needs current visible source and target artwork.'); }
    $from = $images[$sourceId]; $to = $images[$targetId];
    [$grounds, $sides] = $this->collectFacingGrounds();
    $retained = BattleEffectDirection::getOpponentsFacing($sourceId, $grounds, $sides)
      ?? throw new RuntimeException('Travel source lost its facing ground.');
    $facing = BattleEffectDirection::getFacingToward($from['foot']->x, [$to['foot']->x], $retained);
    $sign = $facing->getSign();
    $mirror = $facing !== BattleEffectDirection::getAuthoredFacing($source['party']);
    if ($mirror && !$imageFlips) { throw new RuntimeException('This renderer cannot keep the travelling battler facing its target.'); }
    $body = $from['body'];
    if ($mirror !== $from['image']->flipX) {
      $body = new CanvasRectangle(2 * $from['foot']->x - $body->x - $body->width, $body->y, $body->width, $body->height);
    }
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
        if ($mirror !== $guard['image']->flipX) {
          $approach = new CanvasRectangle(2 * $from['foot']->x - $approach->x - $approach->width,
            $approach->y, $approach->width, $approach->height);
        }
      }
      // The visible aim selects a pose. Only an authored strike point overrides ground-relative contact.
      $aim = $strike ?? new Vector2($sign < 0 ? $to['body']->x + $to['body']->width : $to['body']->x,
        $to['body']->y + $to['body']->height / 2);
      $angle = rad2deg(atan2($aim->y - ($approach->y + $approach->height / 2),
        $sign * ($aim->x - ($approach->x + $approach->width / 2))));
      $arrivalPose = $boundPose->selectArrivalPose($angle);
    }
    $poseReach = $arrivalPose?->reach ?? ($usesFarPose ? $farPose->reach : $pose?->reach);
    $reach = $poseReach?->getCanvasOffset($unit) ?? new Vector2(
      max(0, $sign < 0 ? $from['foot']->x - $body->x : $body->x + $body->width - $from['foot']->x), 0);
    // Ground routing keeps the standing body's existing clearance. Movement artwork is checked on its own phase clock.
    // Flash-step transits belong to the bound action pose, rather than the independent Move/Return roles.
    $envelope = $movement === \Ichiloto\Engine\Animations\ActionMovement::FLASH_STEP
      ? $this->getTravelEnvelope($actor, $body, $mirror,
        [[$boundPose, BattlePoseSequence::ENTRY], [$boundPose, BattlePoseSequence::EXIT]]) : $body;
    $plan = BattleTravelPlanner::plan(new BattleTravelSubject($from['foot'], $envelope, $footing),
      new BattleTravelSubject($to['foot'], $to['body'], BattleFormationClearance::getFooting($to['body'])),
      $this->layout->battlerArea ?? new CanvasRectangle(0, 0, $this->layout->width, $this->layout->getHudTop()),
      $reach, array_values(array_map(static fn(array $entry): CanvasRectangle => BattleFormationClearance::getFooting($entry['body']),
        array_diff_key($images, [$sourceId => true, $targetId => true]))), $strike,
      $this->layout->advanceSpeed, $this->layout->returnSpeed, $sign, $usesFarPose, $arrivalPose);
    if (!$plan->isPossible || $movement === \Ichiloto\Engine\Animations\ActionMovement::FLASH_STEP) { return $plan; }
    $area = $this->layout->battlerArea ?? new CanvasRectangle(0, 0, $this->layout->width, $this->layout->getHudTop());
    $move = $source['poses']?->getPose(BattlePoseRole::MOVE);
    $return = $source['poses']?->getPose(BattlePoseRole::RETURNING);
    foreach ([[$move, false], [$return ?? $move, true]] as [$pose, $returning]) {
      if ($pose === null) { continue; }
      $bounds = new BattleTravelPoseBounds($this->getTravelPoseBounds($actor, $mirror, $pose, BattlePoseSequence::MAIN),
        $pose->fps, $pose->loop, $returning && $return !== null && !$pose->loop && $pose->impactFrame === null);
      $fits = $bounds->isWithinArena($plan, $area, $returning);
      // A missing independent Return pose reuses Move MAIN but has no Return entry/exit transition windows.
      foreach ((!$returning || $return !== null) ? [BattlePoseSequence::ENTRY, BattlePoseSequence::EXIT] : [] as $sequence) {
        if ($pose->getSequenceFrames($sequence) === []) { continue; }
        $stationary = new BattleTravelPoseBounds($this->getTravelPoseBounds($actor, $mirror, $pose, $sequence), $pose->fps);
        $at = $plan->getPositionAtProgress(($sequence === BattlePoseSequence::ENTRY) === $returning ? 1 : 0);
        $fits = $stationary->isAtAnchorWithinArena($from['foot'], $at, $area) && $fits;
      }
      if (!$fits) {
        return new BattleTravelPlan([], $plan->contactX, $plan->contactY, $plan->facingSign,
          $plan->advanceSpeed, $plan->returnSpeed, BattleTravelFailure::OUTSIDE_ARENA,
          $plan->usesFarPose, $plan->arrivalPose);
      }
    }
    return $plan;
  }

  /** Actual visible rectangles at the original anchor, preserving playback order and repeated holds. @return non-empty-list<CanvasRectangle> */
  private function getTravelPoseBounds(CharacterInterface $actor, bool $mirror, BattlerPose $pose,
    BattlePoseSequence $sequence): array
  {
    $participant = $this->participants[spl_object_id($actor)];
    $slot = $this->getSlot($actor);
    $base = $this->placeBaseArtwork($slot, $participant['art'], $participant['poses'], spl_object_id($actor), $participant['scale']);
    $pixelScale = $base->width / $participant['art']->width / $slot->displayScale;
    $images = [];
    $cells = $pose->getSequenceFrames($sequence);
    foreach ($cells as $entry => $cell) {
      if (!isset($images[$cell])) {
        $art = $pose->getArtwork($this->assetRoot, $entry / $pose->fps, sequence: $sequence);
        if ($art === null) { throw new RuntimeException('Travel pose artwork is unavailable: ' . $pose->asset); }
        $bounds = BattleFormationLayout::getPosePlacement($slot, $art, $pixelScale, $this->layout,
          $participant['scale'], $this->scale, $pose->scaleSpan, $participant['poses']);
        $elevation = BattlerGroundContact::getElevation($participant['grounding'], $participant['habitat'],
          $this->layout, $actor->isKnockedOut, $pose);
        [$bounds, $clip] = BattlerGroundContact::place($bounds, BattlerGroundContact::getOffset($elevation, $slot, $this->scale),
          $slot->y, $this->layout);
        if ($mirror) { $bounds = new CanvasRectangle(2 * $slot->x - $bounds->x - $bounds->width,
          $bounds->y, $bounds->width, $bounds->height, allowOutsideCanvas: true); }
        $images[$cell] = new CanvasImage('travel-envelope', $art->asset,
          $bounds, sourceRect: $art->sourceRect, flipX: $mirror, clipRect: $clip);
      }
    }
    $facts = PngAssetBounds::getVisibleBoundsForImages($this->assetRoot, array_values($images));
    $cache = [];
    foreach (array_keys($images) as $index => $cell) {
      $visible = $facts[$index]['bounds'];
      if ($visible === null) { throw new RuntimeException('Travel pose has no visible body.'); }
      $cache[$cell] = $visible;
    }
    $frames = [];
    foreach ($cells as $cell) { $frames[] = $cache[$cell]; }
    return $frames;
  }

  /** @param list<array{?BattlerPose, BattlePoseSequence}> $sequences */
  private function getTravelEnvelope(CharacterInterface $actor, CanvasRectangle $body, bool $mirror, array $sequences): CanvasRectangle
  {
    $participant = $this->participants[spl_object_id($actor)];
    $slot = $this->getSlot($actor);
    $base = $this->placeBaseArtwork($slot, $participant['art'], $participant['poses'], spl_object_id($actor), $participant['scale']);
    $pixelScale = $base->width / $participant['art']->width / $slot->displayScale;
    $left = $body->x; $top = $body->y; $right = $left + $body->width; $bottom = $top + $body->height;
    $images = [];
    foreach ($sequences as [$pose, $sequence]) {
      if ($pose === null) { continue; }
      foreach (array_keys(array_unique($pose->getSequenceFrames($sequence))) as $entry) {
        $art = $pose->getArtwork($this->assetRoot, $entry / $pose->fps, sequence: $sequence);
        if ($art === null) { throw new RuntimeException('Travel pose artwork is unavailable: ' . $pose->asset); }
        $bounds = BattleFormationLayout::getPosePlacement($slot, $art, $pixelScale, $this->layout,
          $participant['scale'], $this->scale, $pose->scaleSpan, $participant['poses']);
        if ($mirror) { $bounds = new CanvasRectangle(2 * $slot->x - $bounds->x - $bounds->width, $bounds->y, $bounds->width, $bounds->height); }
        $images[] = new CanvasImage('travel-envelope', $art->asset,
          $bounds, sourceRect: $art->sourceRect, flipX: $mirror);
      }
    }
    foreach (PngAssetBounds::getVisibleBoundsForImages($this->assetRoot, $images) as $result) {
      $visible = $result['bounds'];
      if ($visible === null) { throw new RuntimeException('Travel pose has no visible body.'); }
      $left = min($left, $visible->x); $top = min($top, $visible->y);
      $right = max($right, $visible->x + $visible->width); $bottom = max($bottom, $visible->y + $visible->height);
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

  private static function createForBattle(BattleConfig $battle, BattlePresentationCatalog $catalog, string $assetRoot,
    ?ConfigInterface $configuration): ?self
  {
    $arena = $catalog->getArenaFor($battle);
    if ($arena === null) { return null; }
    $layout = ($catalog->ui ?? throw new RuntimeException('A graphical arena requires an explicit shared battle canvas layout.'))->getForArena($arena);
    $arena->background->destination->assertWithin($layout->width, $layout->height);
    $presentation = new self($arena, $layout, $battle, $assetRoot, $catalog->scale, $configuration);
    foreach ([[$battle->partyRoster->getPotentialBattlers(), true],
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
          'art' => $art, 'available' => $available, 'artBound' => (($party ? $catalog->actors : $catalog->enemies)[$key] ?? null) !== null, 'poses' => $poses, 'scale' => $profile,
          'grounding' => ($party ? $catalog->actorGrounding : $catalog->enemyGrounding)[$key] ?? null,
          'habitat' => $member instanceof Enemy ? $member->habitat : null,
          'strikePoint' => ($party ? $catalog->actorStrikePoints : $catalog->enemyStrikePoints)[$key] ?? null];
      }
    }
    return $presentation;
  }

  /** Resolve the same entry crop plan as graphical staging, without decoding or projecting artwork. */
  public static function collectEntryArtworkFacts(BattleConfig $battle, BattlePresentationCatalog $catalog,
    string $assetRoot, ?ConfigInterface $configuration = null): ?BattleArtworkFacts
  {
    return PngAssetPreflight::runWithSnapshot(static function () use ($battle, $catalog, $assetRoot, $configuration): ?BattleArtworkFacts {
      $presentation = self::createForBattle($battle, $catalog, $assetRoot, $configuration);
      if ($presentation === null) { return null; }
      $facts = new BattleArtworkFacts($assetRoot);
      $presentation->addScenePoseFacts($facts);
      return $facts;
    });
  }

  public static function prepare(BattleConfig $battle, BattlePresentationCatalog $catalog, string $assetRoot,
    ?ConfigInterface $configuration = null): ?self
  {
    $presentation = self::createForBattle($battle, $catalog, $assetRoot, $configuration);
    if ($presentation === null) { return null; }
    $arena = $presentation->arena;
    $layout = $presentation->layout;
    $images = PngAssetPreflight::getAvailableSize($assetRoot, $arena->background->asset) === null ? [] : [$arena->background];
    $presentation->preparePoseFacts();
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
      // Clearance owns resting body geometry, independent of a renderer's allocation or effect composition.
      $partyImages = $enemyImages = [];
      $enemies = $battle->troop->members->toArray();
      $enemyIndices = array_flip(array_map(spl_object_id(...), $enemies));
      $labels = [];
      foreach ($presentation->getDrawOrder($battle->partyRoster->battlers, $enemies) as $identity => $participant) {
        $projected = $presentation->projectBattler($identity, $participant, null,
          Accessibility::prefersReducedMotion(), true, 0);
        if (!$projected['available'] || !$projected['isOnCanvas']) { continue; }
        $image = $projected['image'];
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
    return PngAssetPreflight::runWithSnapshot(function () use ($field, $ui, $hud, $focus, $now, $imageFlips,
      $compositing, $reducedMotion, $maskFlips, $imageTones, $imageRotation, $imageClips, $coverageDabs,
      $textureStrip, $bottomFade): PresentationCanvas {
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
    });
  }

  /** @param list<CanvasTextLayer> $ui */
  private function composeFrame(?BattlePresentationState $field, array $ui, ?BattleHudSnapshot $hud,
    ?string $focus, float $now, bool $includeOptionalArtwork, bool $imageFlips, bool $compositing,
    bool $reducedMotion, bool $maskFlips, bool $imageTones, bool $imageRotation, bool $imageClips, bool $coverageDabs, bool $textureStrip, bool $bottomFade): PresentationCanvas
  {
    $playback = $field?->getCommandPlayback();
    $color = $includeOptionalArtwork ? BattleFieldColor::getState($playback, $reducedMotion) : null;
    $owners = $color === null ? null : new BattleCanvasOwners($color);
    $own = static function (object $drawing, BattleFieldColorRole $role) use ($owners): object {
      $owners?->recordDrawing($drawing, $role); return $drawing;
    };
    $ownMany = static function (array $drawings, BattleFieldColorRole $role) use ($owners): array {
      foreach ($drawings as $drawing) { $owners?->recordDrawing($drawing, $role); } return $drawings;
    };
    $recordEffect = $owners === null ? null : static fn(object $drawing, ?string $track) =>
      $owners->recordDrawing($drawing, BattleFieldColorRole::EFFECT, $track);
    $recordStageEffect = $owners === null ? null : static function (object $drawing, ?string $track) use ($owners, $playback): void {
      $owners->recordDrawing($drawing, BattleFieldColorRole::EFFECT, $track === null ? null
        : $playback->plan->getCinematicTrackIdAtFrame($playback->session->currentFrame, $track));
    };
    $stageFrame = $includeOptionalArtwork && $playback !== null && !$playback->isCompleted
      ? $playback->plan->getCinematicStageFrame($playback->session->currentFrame, $reducedMotion) : null;
    $stageCanvas = $stageFrame === null ? null : CinematicStagePresentation::compose($stageFrame,
      $playback->plan->getCinematicStageSegments($playback->session->currentFrame, $reducedMotion), $this->assetRoot, $this->layout->width, $this->layout->height,
      $imageFlips, $compositing, $recordStageEffect, retainSourceAtViewport: $imageRotation && $imageClips);
    if ($stageCanvas !== null) { $stageCanvas = $this->applyFieldColor($stageCanvas, $owners); }
    if ($stageFrame?->active) {
      // The cutscene owns the battlefield, while the action callout remains screen-space UI.
      // Reuse the same HUD/message composition and stack it above even a cinematic cover.
      $message = $hud === null ? null : new BattleHudSnapshot(message: $hud->message, messageRows: $hud->messageRows);
      $overlay = $this->layout->skin !== null && $message !== null
        ? GraphicalBattleHud::compose($this->layout, $message, null, $now, $this->assetRoot,
          $this->findControlsTheme(), $this->fallbacks)
        : new PresentationCanvas($this->layout->width, $this->layout->height, textLayers: $ui);
      $canvas = PresentationCanvas::composeOverlay($stageCanvas, $overlay);
      CanvasImagePreflight::inspectCanvases([$canvas], $this->assetRoot);
      return $canvas;
    }
    $images = PngAssetPreflight::getAvailableSize($this->assetRoot, $this->arena->background->asset) === null
      ? [] : $ownMany([$this->arena->background], BattleFieldColorRole::FIELD);
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
      $projection = $this->projectBattler($identity, $participant, $playback,
        $reducedMotion, $includeOptionalArtwork, $field?->getPoseElapsedSeconds() ?? 0, imageFlips: $imageFlips);
      ['image' => $image, 'paint' => $paint, 'available' => $participant['available'], 'slot' => $slot, 'ground' => $groundAnchors[$identity],
        'pose' => $shownPose, 'usesKnockoutPose' => $usesKnockoutPose, 'usesConcedePose' => $usesConcedePose,
        'elevation' => $elevation, 'contact' => $contact, 'isOnCanvas' => $isOnCanvas] = $projection;
      $partySides[$identity] = $participant['party'];
      $bounds = $image->destination;
      // Overlays attach to the body as drawn this frame, following steps, recoil, shake and pose changes.
      $bodies[$identity] = $participant['available'] && $paint !== null ? $this->locateBody($paint, $identity) : $image->destination;
      $popups = array_filter($feedback, static fn(array $popup) => $popup['battler'] === $battler);
      $defeat = $playback?->getEnemyDefeatTreatment($battler, $reducedMotion);
      if (!$participant['party'] && ($playback?->isPresentedKnockedOut($battler) ?? $battler->isKnockedOut) && !$playback?->isAwaitingFallbackHit($battler)
        && ($defeat !== null ? !$defeat['visible'] : $popups === [])) { continue; }
      $bodyOpacity = $defeat['opacity'] ?? match (true) {
        $participant['party'] && ($playback?->isPresentedKnockedOut($battler) ?? $battler->isKnockedOut) && !$usesKnockoutPose => 0.4,
        !$participant['party'] && ($battler->hasConceded ?? false) && !$usesConcedePose => $this->defeatStyle->concededOpacity,
        default => 1,
      };
      // Ground contact owns its own viewport visibility; an offscreen body cannot hide its ground shadow or ripple.
      $surface = $participant['available'] ? $this->composeGroundContact($identity, $participant, $projection, $playback,
        $reducedMotion, $includeOptionalArtwork, $field?->getPoseElapsedSeconds() ?? 0, $bodyOpacity)
        : ['images' => [], 'composites' => []];
      if (!$isOnCanvas) {
        array_push($images, ...$ownMany($surface['images'], BattleFieldColorRole::BATTLERS));
        if ($compositing) { array_push($composites, ...$ownMany($surface['composites'], BattleFieldColorRole::BATTLERS)); }
        continue;
      }
      $bodyOffsets[$identity] = count($images);
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
            $images[] = $own($wake, BattleFieldColorRole::EFFECT);
            $bodyOffsets[$identity]++;
          }
          foreach ($playback->getFlashStepEchoes() as $echoIndex => $echo) {
            $pose = $this->projectBattler($identity, $participant, $echo['sample'], false, true, $field?->getPoseElapsedSeconds() ?? 0, imageFlips: $imageFlips);
            $ghost = $pose['paint'];
            if ($pose['available'] && $pose['isOnCanvas']) {
              $images[] = $own(new CanvasImage('command-echo-' . $identity . '-' . $echoIndex, $ghost->asset,
                $ghost->destination, $ghost->layer, $ghost->sourceRect, $echo['opacity'], $ghost->clipRect,
                $ghost->brightness, $ghost->flipX, $ghost->flipY, $ghost->rotation, $ghost->pivot, $ghost->bottomFade), BattleFieldColorRole::BATTLERS);
              $bodyOffsets[$identity]++;
            }
          }
        }
        $drawn = new CanvasImage($paint->id, $paint->asset, $paint->destination, $paint->layer,
          $paint->sourceRect, clipRect: $paint->clipRect, opacity: $bodyOpacity, brightness: $paint->brightness, flipX: $paint->flipX, flipY: $paint->flipY,
          rotation: $paint->rotation, pivot: $paint->pivot, bottomFade: $paint->bottomFade);
        $combatantImages[$identity] = BattleEffectTone::applyToImage($drawn,
          ($commandTone[$identity] ?? 1.0) * ($conditionTone[$identity] ?? 1.0));
        $paint = $bottomFade && $elevation < 0
          ? BattlerGroundContact::getAquaticBody($combatantImages[$identity], $contact, $groundAnchors[$identity]->y)
          : $combatantImages[$identity];
        // Replacing a waterline hard clip with a fade still retains the parent viewport for moved artwork.
        if ($paint->rotation !== null) {
          $paint = $paint->translateClipped(0, 0, $this->layout->width, $this->layout->height);
        }
        if ($paint !== null) { $images[] = $own($paint, BattleFieldColorRole::BATTLERS); }
        $bodyOffsets[$identity]++;
        if ($shownPose !== null) {
          $attachmentPoints[$identity] = $shownPose->projectAttachmentPoints($image);
        }
        array_push($images, ...$ownMany($surface['images'], BattleFieldColorRole::BATTLERS));
        if ($compositing) { array_push($composites, ...$ownMany($surface['composites'], BattleFieldColorRole::BATTLERS)); }
        if (($defeat['pulse'] ?? false) && $compositing && $includeOptionalArtwork) {
          try {
            if (!$maskFlips && ($combatantImages[$identity]->flipX || $combatantImages[$identity]->flipY)) {
              throw new RuntimeException('Renderer cannot mirror the defeat pulse image-alpha mask; fade remains.');
            }
            $composites[] = \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageTint::compose(
              'enemy-defeat-' . $identity, $combatantImages[$identity], $defeat['color'], .6, $this->assetRoot);
            $owners?->recordDrawing($composites[array_key_last($composites)], BattleFieldColorRole::BATTLERS);
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
              array_push($images, ...$ownMany($cursor['texture']->images('target-cursor-' . $identity, $cursor['bounds'], 202), BattleFieldColorRole::BATTLERS));
              $cursorBounds[] = $cursor['envelope'];
              $hasSelectionArt = true;
            }
          } elseif (CanvasTextureFallback::isAvailable($skin->textures['target'], $this->assetRoot)) {
            array_push($images, ...$ownMany($skin->textures['target']->images('selected-' . $identity, $bounds, 200), BattleFieldColorRole::BATTLERS));
            $hasSelectionArt = true;
          }
          if (!$hasSelectionArt && $participant['available']) {
            $indicators[] = new CanvasIndicator('selected-' . $identity, $image->id, CanvasIndicatorKind::OUTLINE,
              $bounds, (int)min(2, $bounds->width, $bounds->height), $skin->colors['focus'], 200);
          }
          if (in_array($battler, $queued, true)) {
            if (CanvasTextureFallback::isAvailable($skin->textures['queued'], $this->assetRoot)) {
              array_push($images, ...$ownMany($skin->textures['queued']->images('queued-' . $identity,
                new CanvasRectangle(max(0, $bounds->x + $bounds->width - 32), $bounds->y, 32, 32), 201), BattleFieldColorRole::FEEDBACK));
            } else { $lines[] = ['text' => 'Queued', 'color' => $skin->colors['selected']]; }
          }
          if ($skin->targetCursor === null && $focus === 'target' && $battler === ($focused[0] ?? null)
            && CanvasTextureFallback::isAvailable($skin->textures['selector'], $this->assetRoot)) {
            $offset = GraphicalBattleHud::cursorOffset($now, \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
            array_push($images, ...$ownMany($skin->textures['selector']->images('field-cursor',
              new CanvasRectangle(max(0, min($this->layout->width - 20, $bounds->x - 24)) + $offset,
                max(0, $bounds->y + ($bounds->height - 16) / 2), 16, 16), 202), BattleFieldColorRole::BATTLERS));
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
    $ownMany($text, BattleFieldColorRole::FEEDBACK);
    $ownMany($indicators, BattleFieldColorRole::BATTLERS);
    $effects = null;
    $conditionAreas = [];
    foreach ($conditionPlaybacks as $condition) {
      $ambient = GraphicalBattleEffects::compose($condition, $this->layout, $bodies, $this->assetRoot,
        $reducedMotion, $combatantImages, $imageFlips, $compositing, $groundAnchors, $partySides, $maskFlips,
        $attachmentPoints, subjectFrontImages: $subjectFrontImages);
      array_push($images, ...$ownMany($ambient->images, BattleFieldColorRole::EFFECT));
      array_push($text, ...$ownMany($ambient->textLayers, BattleFieldColorRole::EFFECT));
      array_push($composites, ...$ownMany($ambient->composites, BattleFieldColorRole::EFFECT));
      array_push($conditionAreas, ...$ambient->getOverlayProtection());
    }
    if ($playback !== null && $includeOptionalArtwork) {
      $effects = GraphicalBattleEffects::compose($playback, $this->layout, $bodies, $this->assetRoot,
        $reducedMotion, $combatantImages, $imageFlips, $compositing, $groundAnchors, $partySides, $maskFlips,
        $attachmentPoints, $imageRotation, $imageClips, $coverageDabs, $textureStrip, $subjectFrontImages, $recordEffect);
      array_push($images, ...$effects->images);
      array_push($text, ...$effects->textLayers);
      array_push($composites, ...$effects->composites);
    }
    $fieldMotion = $includeOptionalArtwork ? BattleFieldMotion::getTransform($playback, $reducedMotion) : null;
    if ($fieldMotion !== null && !\Ichiloto\Engine\Rendering\Presentation\PresentationSession::supportsCapability(
      \Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::CANVAS_GROUPS)) {
      $playback->recordPresentationFailure(new RuntimeException('Renderer cannot apply the authored whole-field motion.'));
      $fieldMotion = null;
    }
    if ($fieldMotion !== null && $fieldMotion->scale !== 1.0
      && (!\Ichiloto\Engine\Rendering\Presentation\PresentationSession::supportsCapability(RendererSessionConfig::CANVAS_GROUP_SCALE)
        || !\Ichiloto\Engine\Rendering\Presentation\PresentationSession::supportsCapability(RendererSessionConfig::CANVAS_REGION_BUDGET))) {
      $playback->recordPresentationFailure(new RuntimeException('Renderer cannot scale the authored battlefield camera.'));
      $fieldMotion = null;
    }
    if ($composition !== null && $fieldMotion === null) {
      array_push($images, ...$ownMany($composition->images, BattleFieldColorRole::SCREEN_UI));
      array_push($text, ...$ownMany($composition->textLayers, BattleFieldColorRole::SCREEN_UI));
      array_push($composites, ...$ownMany($composition->composites, BattleFieldColorRole::SCREEN_UI));
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
    array_push($images, ...$ownMany($badges->images, BattleFieldColorRole::FEEDBACK));
    array_push($text, ...$ownMany($badges->textLayers, BattleFieldColorRole::FEEDBACK));
    array_push($conditionAreas, ...$badges->getOverlayProtection());
    if ($skin !== null) {
      array_push($text, ...$ownMany(GraphicalBattleFeedback::compose($this->layout, $feedbackParticipants, $ui, $now,
        [...$hudBounds, ...$cursorBounds, ...$badges->getOverlayProtection()]), BattleFieldColorRole::FEEDBACK));
    }
    // Protect participants, effects and HUD content, not the decorative arena behind them.
    $areas = [...$protectedCombatants, ...$cursorBounds, ...$hudBounds, ...$conditionAreas,
      ...array_map(static fn(CanvasTextLayer $layer) => $layer->clipRect ?? $layer->paintBounds, [...$text, ...$ui]),
      ...array_map(static fn(CanvasIndicator $indicator) => $indicator->bounds, $indicators),
      ...($effects?->getOverlayProtection() ?? [])];
    if ($subjectFrontImages !== []) {
      $images = self::orderSubjectEffects($images, $bodyOffsets, $subjectFrontImages);
    }
    if ($fieldMotion !== null) {
      $fieldAreas = [...$protectedCombatants, ...$cursorBounds, ...$conditionAreas,
        ...array_map(static fn(CanvasTextLayer $layer) => $layer->clipRect ?? $layer->paintBounds, $text),
        ...array_map(static fn(CanvasIndicator $indicator) => $indicator->bounds, $indicators),
        ...($effects?->getOverlayProtection() ?? [])];
      $local = new PresentationCanvas($this->layout->width, $this->layout->height,
        $images, $indicators, CanvasTextBatch::compact($text, $owners), composites: $composites, protectedAreas: $fieldAreas);
      $local = $this->applyFieldColor($local, $owners);
      try {
        $fieldMotion->assertCoverage($this->arena->background, $this->layout->width, $this->layout->height);
        $group = CanvasGroup::wrapRoot('battlefield',
          $fieldMotion->x, $fieldMotion->y, $local, scale: $fieldMotion->createGroupScale());
      } catch (\InvalidArgumentException $error) {
        $playback->recordPresentationFailure($error);
        $group = CanvasGroup::wrapRoot('battlefield', 0, 0, $local);
      }
      $canvas = new PresentationCanvas($this->layout->width, $this->layout->height, groups: [$group]);
      if ($stageCanvas !== null) { $canvas = PresentationCanvas::composeOverlay($canvas, $stageCanvas); }
      if ($composition !== null) { $canvas = PresentationCanvas::composeOverlay($canvas, $composition); }
      if ($ui !== []) {
        $canvas = PresentationCanvas::composeOverlay($canvas,
          new PresentationCanvas($this->layout->width, $this->layout->height, textLayers: CanvasTextBatch::compact($ui)));
      }
      CanvasImagePreflight::inspectCanvases([$canvas], $this->assetRoot);
      return $canvas;
    }
    $ownMany($ui, BattleFieldColorRole::SCREEN_UI);
    $canvas = new PresentationCanvas($this->layout->width, $this->layout->height,
      [...$images, ...($stageCanvas?->images ?? [])], $indicators, CanvasTextBatch::compact([...$text, ...$ui], $owners),
      composites: [...$composites, ...($stageCanvas?->composites ?? [])],
      protectedAreas: [...$areas, ...($stageCanvas?->getOverlayProtection() ?? [])],
      groups: $stageCanvas?->groups ?? []);
    if ($stageCanvas !== null) { $ownMany($stageCanvas->groups, BattleFieldColorRole::EFFECT); }
    $canvas = $this->applyFieldColor($canvas, $owners);
    CanvasImagePreflight::inspectCanvases([$canvas], $this->assetRoot);
    return $canvas;
  }

  private function applyFieldColor(PresentationCanvas $canvas, ?BattleCanvasOwners $owners): PresentationCanvas
  {
    if ($owners === null) { return $canvas; }
    $result = BattleFieldColorComposition::compose($canvas, $owners);
    if ($result !== $canvas) {
      foreach ([RendererSessionConfig::CANVAS_GROUPS, RendererSessionConfig::CANVAS_GROUP_COLOR,
        RendererSessionConfig::CANVAS_REGION_BUDGET] as $capability) {
        if (!\Ichiloto\Engine\Rendering\Presentation\PresentationSession::supportsCapability($capability)) {
          throw new RuntimeException('Renderer cannot apply authored field colour: ' . $capability);
        }
      }
      $paths = array_any(\Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasFrameBudget::getCanvases([$result]),
        static fn(PresentationCanvas $local): bool => array_any($local->indicators,
          static fn(CanvasIndicator $indicator): bool => $indicator->imagePath !== null));
      if ($paths && !\Ichiloto\Engine\Rendering\Presentation\PresentationSession::supportsCapability(
        RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH)) {
        throw new RuntimeException('Renderer cannot associate field-colour markers: ' . RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH);
      }
    }
    return $result;
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

  /** @return array{array<int, Vector2>, array<int, bool>} Current physical formation, before visual recoil/travel. */
  private function collectFacingGrounds(): array
  {
    $grounds = $sides = [];
    foreach ([$this->battle->partyRoster->battlers, $this->battle->troop->members->toArray()] as $members) {
      foreach ($members as $battler) {
        $id = spl_object_id($battler); $slot = $this->getSlot($battler);
        $grounds[$id] = new Vector2($slot->x, $slot->y);
        $sides[$id] = $this->participants[$id]['party'];
      }
    }
    return [$grounds, $sides];
  }


  /** Ground contact is projected from logical artwork, independently of whether the body paints in the viewport.
   * @return array{images: list<CanvasImage>, composites: list<CanvasComposite>}
   */
  private function composeGroundContact(int $identity, array $participant, array $projection, ?BattleCommandPlayback $playback,
    bool $reducedMotion, bool $includeOptionalArtwork, float $idleSeconds, float $opacity): array
  {
    $image = $projection['image'];
    $ground = $projection['ground'];
    $contact = $projection['contact'];
    $elevation = $projection['elevation'];
    $contactArtwork = $image;
    $contactFootprint = $image->destination->height;
    // Travel owns the moving ground anchor; a low weapon/cape cannot become another footing.
    if ($contact === BattlerContact::SHADOW && $elevation <= 0 && !$reducedMotion
      && $playback?->actor === $participant['battler'] && ($playback->plan->travel?->isPossible ?? false)
      && !$projection['usesKnockoutPose'] && !$projection['usesConcedePose']) {
      $resting = $this->projectBattler($identity, $participant, null, false, $includeOptionalArtwork, $idleSeconds);
      $contactArtwork = $resting['available'] ? self::projectTravelContactArtwork($resting['image'],
        $resting['ground'], $ground, $image->flipX) : null;
      $contactFootprint = $resting['image']->destination->height;
    }
    $profile = $participant['scale'];
    return BattlerGroundContact::compose('contact-' . $identity, $ground->x, $ground->y,
      $profile !== null && $this->scale !== null ? $this->scale->referenceHeight * $profile->relativeSize * $projection['slot']->displayScale
        : $contactFootprint, ($profile?->horizontal ?? false) || $projection['usesKnockoutPose'], $image->destination->width,
      $elevation, $contact, $this->layout, $this->arena, $participant['grounding'], $this->assetRoot, $idleSeconds,
      $reducedMotion, $opacity, $contactArtwork);
  }

  private function projectBattler(int $identity, array $participant, ?BattleCommandPlayback $playback,
    bool $reducedMotion, bool $includeOptionalArtwork, float $idleSeconds, ?BattlerPose $guardPose = null,
    bool $imageFlips = true): array
  {
    $battler = $participant['battler'];
    $image = $participant['image'];
    $art = $this->findBoundBaseArtwork($participant);
    $available = $art !== null;
    $art ??= $participant['art'];
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
    if ($includeOptionalArtwork && !in_array($role, [BattlePoseRole::KNOCKOUT, BattlePoseRole::CONCEDE], true)
      && ($reaction = $playback?->getAuthoredReaction($battler, $reducedMotion)) !== null) {
      $reactionPose = $participant['poses']?->getPose($reaction['role']);
      if ($reactionPose !== null) { $candidates[] = [$reaction['role']->value, $reactionPose, $reaction['seconds']]; }
    }
    if ($includeOptionalArtwork) {
      foreach (array_unique([$role->value, BattlePoseRole::getRestingRole($battler)->value, BattlePoseRole::IDLE->value]) as $candidateRole) {
        $candidate = $participant['poses']?->getPose(BattlePoseRole::from($candidateRole));
        if ($candidate !== null && $candidate !== $actionPose) { $candidates[] = [$candidateRole, $candidate]; }
      }
    }
    foreach ($candidates as $candidate) {
      [$candidateRole, $pose] = $candidate;
      $reactionSeconds = $candidate[2] ?? null;
      $useCommandPose = $reactionSeconds === null && $playback !== null && $candidateRole === $role->value;
      try {
        $posed = $pose->getArtwork($this->assetRoot, $reactionSeconds
          ?? ($useCommandPose && $role !== BattlePoseRole::IDLE ? $playback->getPoseElapsedSeconds($battler, $role) : $idleSeconds),
          $reducedMotion,
          impactAtSeconds: $useCommandPose && $role === $playback->pose ? $playback->getPoseImpactSeconds($battler) : null,
          durationSeconds: $useCommandPose ? $playback->getPoseDurationSeconds($battler, $role) : null,
          sequence: match (true) {
            $pose === $guardPose => BattlePoseSequence::GUARD,
            $useCommandPose => $playback->getPoseSequence($battler, $role),
            default => BattlePoseSequence::MAIN,
          },
          useFarPose: $pose === $actionPose && ($playback?->plan->travel?->usesFarPose ?? false),
          arrivalPose: $pose === $actionPose ? $playback?->plan->travel?->arrivalPose : null,
          elapsedFromImpactSeconds: $playback !== null && $candidateRole === $role->value && $role === $playback->pose
            ? $playback->getPoseElapsedFromImpactSeconds($battler) : null);
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
    [$bounds, $clip, $surfaceVisible] = BattlerGroundContact::place($bounds, BattlerGroundContact::getOffset($elevation, $slot, $this->scale),
      $slot->y, $this->layout);
    [$facingGrounds, $facingSides] = $this->collectFacingGrounds();
    $opponentsFacing = BattleEffectDirection::getOpponentsFacing($identity, $facingGrounds, $facingSides)
      ?? throw new RuntimeException('A battle participant lost its facing ground.');
    $activeActor = $playback?->actor === $battler && !$playback->isCompleted && !$playback->plan->resultsOnly;
    $facing = $activeActor ? $this->resolveCommandFacing($playback, $battler, $slot->x, $opponentsFacing) : $opponentsFacing;
    $travel = !$reducedMotion && $activeActor ? $playback->plan->travel : null;
    $mirror = $facing !== BattleEffectDirection::getAuthoredFacing($participant['party']);
    if ($mirror && !$imageFlips) { throw new RuntimeException('Renderer cannot mirror a battler toward its current ground-facing direction.'); }
    if ($mirror) { $bounds = new CanvasRectangle(2 * $slot->x - $bounds->x - $bounds->width, $bounds->y,
      $bounds->width, $bounds->height, allowOutsideCanvas: true); }
    $offset = $travel !== null ? $playback->getTravelOffset() : new Vector2(0, 0);
    $advance = $travel === null && !$reducedMotion && $activeActor && $playback->plan->movement !== ActionMovement::STATIONARY
      ? $playback->getAdvanceFraction() : 0;
    $motion = $includeOptionalArtwork ? BattleBattlerMotion::getOffset($playback, $battler, $opponentsFacing, $reducedMotion) : null;
    if ($motion !== null && (!\Ichiloto\Engine\Rendering\Presentation\PresentationSession::supportsCapability(
        \Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::CANVAS_IMAGE_ROTATION)
      || !\Ichiloto\Engine\Rendering\Presentation\PresentationSession::supportsCapability(
        \Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::CANVAS_CLIP_OPACITY))) {
      $playback->recordPresentationFailure(new RuntimeException('Renderer cannot retain full-source battler motion behind a viewport clip.'));
      $motion = null;
    }
    // An explicit held motion sample owns recoil, including a neutral sample. Other authored shake remains additive.
    $recoil = ($motion === null ? ($playback?->getRecoilFraction($battler, $reducedMotion) ?? 0) : 0)
      + ($playback?->getShakeFraction($battler, $reducedMotion) ?? 0);
    $unmovedX = $bounds->x;
    $unmovedY = $bounds->y;
    $bounds = new CanvasRectangle($bounds->x + $offset->x + $slot->width * $recoil
      + $facing->getSign() * $slot->width * .25 * $advance, $bounds->y + $offset->y,
      $bounds->width, $bounds->height, allowOutsideCanvas: true);
    $ground = new Vector2($slot->x + ($bounds->x - $unmovedX), $slot->y + ($bounds->y - $unmovedY));
    $image = new CanvasImage($image->id, $art->asset, $bounds, $image->layer, $art->sourceRect,
      $image->opacity, $clip === null ? null : new CanvasRectangle($bounds->x, $clip->y + $offset->y,
        $bounds->width, $clip->height, allowOutsideCanvas: true),
      $image->brightness, $mirror ? !$image->flipX : $image->flipX, $image->flipY);
    if ($motion !== null) {
      // Geometry remains whole; only the painted view receives the parent viewport clip.
      $clip = $image->clipRect;
      $image = new CanvasImage($image->id, $image->asset,
        new CanvasRectangle($bounds->x + $motion->x, $bounds->y + $motion->y, $bounds->width, $bounds->height,
          allowOutsideCanvas: true), $image->layer, $image->sourceRect, $image->opacity,
        $clip === null ? null : new CanvasRectangle($clip->x + $motion->x, $clip->y + $motion->y,
          $clip->width, $clip->height, allowOutsideCanvas: true),
        brightness: $image->brightness, flipX: $image->flipX, flipY: $image->flipY,
        rotation: 0, pivot: new \Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot(.5, .5));
      $ground = new Vector2($ground->x + $motion->x, $ground->y + $motion->y);
    }
    $paint = $surfaceVisible ? $image->clipToViewport($this->layout->width, $this->layout->height) : null;
    $isOnCanvas = $paint !== null;
    return ['image' => $image, 'paint' => $paint, 'available' => $available, 'slot' => $slot, 'ground' => $ground,
      'pose' => $shownPose, 'usesKnockoutPose' => $usesKnockoutPose, 'usesConcedePose' => $usesConcedePose,
      'elevation' => $elevation, 'contact' => $contact, 'isOnCanvas' => $isOnCanvas];
  }

  private function resolveCommandFacing(BattleCommandPlayback $playback, CharacterInterface $actor, float $x,
    EffectHorizontalFacing $opponents): EffectHorizontalFacing
  {
    $route = $playback->plan->travel;
    return $route?->isPossible
      ? ($route->facingSign < 0 ? EffectHorizontalFacing::WEST : EffectHorizontalFacing::EAST)
      : BattleEffectDirection::getFacingToward($x, array_map(fn(CharacterInterface $target): float => $this->getSlot($target)->x,
        array_filter($playback->targets, static fn(CharacterInterface $target): bool => $target !== $actor)), $opponents);
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
      $playback->bindAnchorSnapshotCapture(function (BattleCommandPlayback $sample) use ($captureGeometry): ?BattleEffectAnchorSnapshot {
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
        $targets = BattleEffectTargetGroup::collectTargets($sample->targets, $bounds, $grounds, $sides);
        if ($targets === null) { return null; }
        $caster = $geometry[spl_object_id($sample->actor)];
        return new BattleEffectAnchorSnapshot($targets, $caster['ground'],
          $caster['pose']?->projectAttachmentPoints($caster['image']) ?? []);
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
        [$facingGrounds, $facingSides] = $this->collectFacingGrounds();
        $points[$id] = ['point' => $point, 'casterX' => $casterX,
          'recipientX' => $recipient === null ? null : $geometry[spl_object_id($recipient)]['ground']->x,
          'party' => $this->participants[$id]['party'],
          'opponentsFacing' => BattleEffectDirection::getOpponentsFacing($id, $facingGrounds, $facingSides)];
        if ($route !== null) {
          if ($sample->targets === []) { throw new RuntimeException('Battle projectile has no selected recipients.'); }
          if ($route === EffectFlightRoute::STRAIGHT->value) {
            $targets = array_map(static fn($target): CanvasImage => $geometry[spl_object_id($target)]['image'], $sample->targets);
            $contactMode = $contact === null ? EffectFlightContact::EDGE : EffectFlightContact::from($contact);
            $fallbackPolicy = $fallback === null ? null : EffectStraightFlightFallback::from($fallback);
            $key = $id . ':' . $name . ':' . $contactMode->value . ':' . $fallback;
            if (!array_key_exists($key, $straightShots)) {
              // The selected physical ground extent owns the exit heading. Neither
              // party identity nor an overhanging release image defines that direction.
              $exitFacing = BattleEffectDirection::getFacingToward($projected['ground']->x,
                array_map(static fn($target): float => $geometry[spl_object_id($target)]['ground']->x,
                  array_filter($sample->targets, static fn($target): bool => $target !== $subject)),
                $points[$id]['opponentsFacing'] ?? throw new RuntimeException('Captured effect subject lost its facing ground.'));
              $straightShots[$key] = BattleStraightShot::planRoute($point, $targets, $this->assetRoot, $this->layout->width,
                $exitFacing, $contactMode, $fallbackPolicy);
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
