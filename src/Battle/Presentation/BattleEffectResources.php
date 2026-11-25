<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectSegmentComposition;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\InherentAttack;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Rendering\Assets\AssetHoldResource;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

/** Scene attacks and one selected action use the same authored animation and summon resolvers as execution. */
final class BattleEffectResources
{
  public function __construct(private readonly AnimationLibrary $animations,
    private readonly EffectTimelineLibrary $effects, private ?SummonCutsceneLibrary $summons = null) {}

  /** Only first normal/reduced ordinary attack cohorts are scene-hot. Complete films belong to command windows.
   * Future books, items, crisis variants and enemy patterns are selected later.
   * @return array<string, AssetHoldResource>
   */
  public function collectBattleResources(BattleConfig $battle): array
  {
    $resources = [];
    foreach ([...$battle->partyRoster->getPotentialBattlers(), ...$battle->troop->members->toArray()] as $actor) {
      $action = InherentAttack::createAction($actor);
      $resources = array_replace($resources, $this->collectActionPrefetchResources($actor, $action, false),
        $this->collectActionPrefetchResources($actor, $action, true));
    }
    return $resources;
  }

  /** Runtime selection identity contains domain bindings, never authored artwork hashes or dimensions. */
  public function getActionSelectionKey(CharacterInterface $actor, BattleAction $action, bool $reducedMotion = false): string
  {
    return hash('sha256', serialize([spl_object_id($actor), $action::class, $action->name,
      ActionAnimationResolver::getExplicitAnimationId($action), $this->getActionAnimationId($actor, $action),
      $action instanceof SkillBattleAction ? $action->skill->name : null, $reducedMotion]));
  }

  public function getActionAnimationId(CharacterInterface $actor, BattleAction $action): ?int
  {
    return ActionAnimationResolver::resolveForAction($action, $actor, $this->animations)?->id;
  }

  /** Resolves one actual action without selecting targets, firing cues or executing combat.
   * @return array<string, AssetHoldResource>
   */
  public function collectActionResources(CharacterInterface $actor, BattleAction $action): array
  {
    $resources = [];
    foreach ($this->resolveActionTimelines($actor, $action) as $timeline) {
      $resources = array_replace($resources, self::collectTimelineResources($timeline, $this->effects->assetRoot));
    }
    return $resources;
  }

  /** Focus prepares the first drawn cohort of each selected phase, never its whole future film.
   * @return array<string, AssetHoldResource>
   */
  public function collectActionPrefetchResources(CharacterInterface $actor, BattleAction $action, bool $reducedMotion): array
  {
    $resources = [];
    foreach ($this->resolveActionTimelines($actor, $action) as $timeline) {
      $starts = array_values(array_unique(array_map(static fn(array $segment): int => $segment['startFrame'], $timeline->playbackSegments)));
      if ($reducedMotion) {
        foreach ($timeline->defaults['reducedMotionSamples'] ?? [] as $hold) { $starts[] = $hold['frame']; }
      }
      sort($starts);
      foreach (array_unique($starts) as $frame) {
        $sample = $reducedMotion ? $timeline->getReducedMotionSampleFrame($frame) : $frame;
        if ($sample === null) { continue; }
        $active = array_values(array_filter($timeline->playbackSegments, static fn(array $segment): bool =>
          $segment['startFrame'] <= $sample && $sample <= $segment['endFrame']));
        $cohort = self::collectSegments(EffectSegmentComposition::compose($active, EffectPresentation::GRAPHICAL, $reducedMotion),
          $this->effects->assetRoot);
        if ($cohort !== []) { $resources = array_replace($resources, $cohort); break; }
      }
    }
    return $resources;
  }

  /** The same domain resolver supplies inventory, focus preparation and command execution.
   * @return list<CompiledEffectTimeline>
   */
  private function resolveActionTimelines(CharacterInterface $actor, BattleAction $action): array
  {
    if ($action instanceof SkillBattleAction) {
      $this->summons ??= new SummonCutsceneLibrary(
        $this->effects->assetRoot . '/Cutscenes/Summons', cacheForBattle: true, assetRoot: $this->effects->assetRoot);
      $summon = $this->summons->loadCompiledOrCompileByLinkedActionId($action->skill->name, EffectPresentation::GRAPHICAL);
      if ($summon !== null) { return [$summon]; }
    }
    $animation = ActionAnimationResolver::resolveForAction($action, $actor, $this->animations);
    return array_map(fn(string $id): CompiledEffectTimeline => $this->effects->load($id, forBattle: true),
      array_values(array_unique(array_filter([$animation?->sourceEffect, $animation?->targetEffect],
        static fn($id): bool => $id !== null))));
  }

  /** The production command sampler owns phase clocks, reduced motion, target contacts and clearing.
   * Off-canvas commands are retained conservatively; this collector never guesses their anchor geometry.
   * @return array<string, AssetHoldResource>
   */
  public static function collectCommandFrameResources(BattleCommandPlayback $playback, string $assetRoot,
    int $frame, bool $reducedMotion): array
  {
    return self::collectSampleResources($playback->createPresentationSample($frame), $assetRoot, $reducedMotion);
  }

  /** @return array<string, AssetHoldResource> */
  public static function collectCommandResourcesAt(BattleCommandPlayback $playback, string $assetRoot,
    float $seconds, bool $reducedMotion): array
  {
    return self::collectSampleResources($playback->createPresentationSampleAt($seconds), $assetRoot, $reducedMotion);
  }

  /** @return array<string, AssetHoldResource> */
  private static function collectSampleResources(BattleCommandPlayback $sample, string $assetRoot, bool $reducedMotion): array
  {
    if ($sample->isCompleted) { return []; }
    $frame = $sample->session->currentFrame;
    $resources = self::collectSegments($sample->getActiveSegments($reducedMotion), $assetRoot, false);
    $stage = $sample->plan->getCinematicStageFrame($frame, $reducedMotion);
    if ($stage?->drawsContent) {
      $segments = array_values(array_filter($sample->plan->getCinematicStageSegments($frame, $reducedMotion),
        static fn(array $segment): bool => $segment['startFrame'] <= $stage->contentFrame && $stage->contentFrame <= $segment['endFrame']));
      $resources = array_replace($resources, self::collectSegments(
        EffectSegmentComposition::compose($segments, EffectPresentation::GRAPHICAL, $reducedMotion), $assetRoot, true));
    }
    return $resources;
  }

  /** @param list<array<string, mixed>> $segments @return array<string, AssetHoldResource> */
  private static function collectSegments(array $segments, string $assetRoot, ?bool $stageOnly = null): array
  {
    $resources = [];
    foreach ($segments as $segment) {
      if (($segment['layer'] ?? null) !== 'image') { continue; }
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true) || ($command['payload']['opacity'] ?? 1) <= 0) { continue; }
        $isStage = ($command['payload']['anchor'] ?? null) === 'stage';
        if ($stageOnly !== null && $isStage !== $stageOnly) { continue; }
        $source = \Ichiloto\Engine\Animations\Timelines\EffectImageSource::fromCommand($command);
        $resource = new AssetHoldResource($source->asset, $source->getSourceRect($assetRoot));
        $resources[$resource->getKey()] = $resource;
      }
    }
    return $resources;
  }

  /** Variable target, strip and stage placements retain their authored cells at full size.
   * @return array<string, AssetHoldResource>
   */
  public static function collectTimelineResources(CompiledEffectTimeline $timeline, string $assetRoot): array
  {
    $resources = [];
    foreach ($timeline->getImageSources() as $source) {
      $resource = new AssetHoldResource($source->asset, $source->getSourceRect($assetRoot));
      $resources[$resource->getKey()] = $resource;
    }
    return $resources;
  }

}
