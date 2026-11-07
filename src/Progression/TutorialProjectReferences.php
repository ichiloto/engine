<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryBoard;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Util\Stores\ActorStore;
use InvalidArgumentException;

/** Project identities for tutorial authoring and campaign admission. */
final class TutorialProjectReferences
{
  private readonly string $assetRoot;
  private ?MasteryBoard $mastery = null;
  /** @var array<string, list<string>> */
  private array $ids = [];

  public function __construct(string $assetRoot)
  {
    $this->assetRoot = rtrim(realpath($assetRoot) ?: $assetRoot, '/\\');
  }

  /** @return list<string> Raw project ids, before applying a view's target prefix. */
  public function getIds(TutorialReferenceSource|string $source): array
  {
    $source = is_string($source) ? TutorialReferenceSource::from($source) : $source;
    return $this->ids[$source->value] ??= match ($source) {
      TutorialReferenceSource::ACTOR => (new ActorStore($this->assetRoot . '/Data/Actors'))->getActorIds(),
      TutorialReferenceSource::SUMMON => array_column(
        (new SummonCutsceneLibrary($this->assetRoot . '/Cutscenes/Summons', assetRoot: $this->assetRoot))->load(), 'id',
      ),
      TutorialReferenceSource::MASTERY_REGION => array_column($this->getMasteryBoard()->tree->regions, 'id'),
      TutorialReferenceSource::MASTERY_FAMILY => array_column(array_merge(...array_values($this->getMasteryBoard()->families)), 'id'),
      TutorialReferenceSource::MASTERY_NODE => array_column($this->getMasteryBoard()->tree->nodes, 'id'),
    };
  }

  /** @return list<string> Fixed and dynamic ids the selected view understands. */
  public function getTargetIds(string $screen, string $view): array
  {
    $declaration = TutorialScreenCatalog::getViews()[$screen][$view] ?? null;
    if ($declaration === null) {
      return [];
    }
    $prefix = $declaration['targetPrefix'] ?? '';
    return [
      ...$declaration['targets'],
      ...array_map(static fn(string $id): string => $prefix . $id,
        isset($declaration['targetSource']) ? $this->getIds($declaration['targetSource']) : []),
    ];
  }

  public function validateCatalog(TutorialCatalog $catalog): void
  {
    $views = TutorialScreenCatalog::getViews();
    $cinematics = new CinematicLibrary($this->assetRoot . '/Cutscenes/Cinematics');
    $effects = new EffectTimelineLibrary($this->assetRoot);
    foreach ($catalog->definitions as $tutorial) {
      foreach ($tutorial->steps as $index => $step) {
        $where = 'Tutorial ' . $tutorial->id . ' step ' . ($index + 1);
        ['screen' => $screen, 'view' => $view, 'id' => $id] = $step->target;
        $declaration = $views[$screen][$view] ?? null;
        if ($declaration === null || !in_array($id, $this->getTargetIds($screen, $view), true)) {
          throw new InvalidArgumentException($where . ' names an unknown target ' . $screen . '/' . $view . '/' . $id . '.');
        }
        if (!in_array($step->action, $declaration['actions'], true)) {
          throw new InvalidArgumentException($where . ' names an unsupported action ' . $step->action . '.');
        }
        if ($step->speaker !== null && !in_array($step->speaker, $this->getIds(TutorialReferenceSource::ACTOR), true)) {
          throw new InvalidArgumentException($where . ' names an unknown speaker ' . $step->speaker . '.');
        }
        if (isset($step->until['outcome'])) {
          $outcome = $step->until['outcome'];
          $subjects = $declaration['outcomes'][$outcome] ?? null;
          if ($subjects === null) {
            throw new InvalidArgumentException($where . ' names an unsupported outcome ' . $outcome . '.');
          }
          if (isset($step->until['subject']) && !in_array($step->until['subject'],
            is_string($subjects) ? $this->getIds($subjects) : $subjects, true)) {
            throw new InvalidArgumentException($where . ' names an unknown subject for ' . $outcome . '.');
          }
        }
      }
      if ($tutorial->onFinish !== null && !$cinematics->hasSource($tutorial->onFinish)) {
        throw new InvalidArgumentException('Tutorial ' . $tutorial->id . ' names an unknown follow-on cinematic.');
      }
      if ($tutorial->demo !== null) {
        $effects->loadStage($tutorial->demo);
      }
    }
  }

  private function getMasteryBoard(): MasteryBoard
  {
    return $this->mastery ??= new MasteryBoard(MasteryCatalog::load($this->assetRoot, SkillCatalog::load($this->assetRoot))->tree);
  }
}
