<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;

/** A battle resolves commands and presentation against one project's assets. */
final class BattleContent
{
  private ?SummonCutsceneLibrary $summons = null;
  private ?AnimationLibrary $animations = null;
  /** @var array<int, true> */
  private array $missingAnimationIds = [];

  public function __construct(public readonly string $assetRoot) {}

  public function getSummons(): SummonCutsceneLibrary
  {
    return $this->summons ??= new SummonCutsceneLibrary($this->assetRoot . '/Cutscenes/Summons',
      cacheForBattle: true, assetRoot: $this->assetRoot);
  }

  public function getAnimations(): AnimationLibrary
  {
    return $this->animations ??= new AnimationLibrary(cacheForBattle: true, assetRoot: $this->assetRoot);
  }

  public function getSkillCatalog(): SkillCatalog
  {
    return SkillCatalog::getProjectCatalog($this->assetRoot);
  }

  public function recordMissingAnimation(int $id): bool
  {
    if (isset($this->missingAnimationIds[$id])) { return false; }
    $this->missingAnimationIds[$id] = true;
    return true;
  }
}
