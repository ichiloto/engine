<?php
declare(strict_types=1);
namespace Ichiloto\Engine\Battle\Presentation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasColorTransform, CanvasTextLayer, CanvasTextBatchScopeInterface};
use LogicException;
use SplObjectStorage;

/** Producer-reported ownership; IDs and array placement never infer a drawing's source. */
final class BattleCanvasOwners implements CanvasTextBatchScopeInterface
{
  /** @var SplObjectStorage<object, array{transform:?CanvasColorTransform, origins:list<array{role:BattleFieldColorRole, track:?string}>}> */
  private SplObjectStorage $drawings;
  public function __construct(private readonly BattleFieldColorState $state) { $this->drawings = new SplObjectStorage(); }

  public function recordDrawing(object $drawing, BattleFieldColorRole $role, ?string $track = null): void
  {
    if ($track !== null && $role !== BattleFieldColorRole::EFFECT) { throw new LogicException('Only authored effects have a field-colour track source.'); }
    $entry = ['transform' => match ($role) {
      BattleFieldColorRole::FIELD => $this->state->field,
      BattleFieldColorRole::BATTLERS => $this->state->battlers,
      BattleFieldColorRole::EFFECT => $track === null ? null : ($this->state->effects[$track] ?? null),
      BattleFieldColorRole::FEEDBACK, BattleFieldColorRole::SCREEN_UI => null,
    }, 'origins' => [['role' => $role, 'track' => $track]]];
    if ($this->drawings->offsetExists($drawing) && $this->drawings[$drawing] != $entry) {
      throw new LogicException('A battle drawing already has a different source owner.');
    }
    $this->drawings[$drawing] = $entry;
  }

  public function getTransform(object $drawing): ?CanvasColorTransform { return $this->getEntry($drawing)['transform']; }
  public function getOrigins(object $drawing): array { return $this->getEntry($drawing)['origins']; }
  public function isScreenDrawing(object $drawing): bool
  { return $this->getOrigins($drawing)[0]['role'] === BattleFieldColorRole::SCREEN_UI; }
  public function getDrawingKey(object $drawing): string
  { return ($this->isScreenDrawing($drawing) ? 'screen:' : 'field:') . self::getTransformKey($this->getTransform($drawing)); }
  public function getBatchKey(CanvasTextLayer $layer): string { return $this->getDrawingKey($layer); }
  public static function getTransformKey(?CanvasColorTransform $transform): string
  { return json_encode($transform?->toArray(), JSON_THROW_ON_ERROR); }

  public function recordBatch(CanvasTextLayer $result, array $sources): void
  {
    if ($sources === []) { throw new LogicException('A text batch needs its original source layers.'); }
    $transform = $this->getTransform($sources[0]); $key = $this->getBatchKey($sources[0]); $origins = [];
    foreach ($sources as $source) {
      if ($this->getBatchKey($source) !== $key) { throw new LogicException('A text batch cannot mix field-colour treatments.'); }
      foreach ($this->getOrigins($source) as $origin) {
        if (!in_array($origin, $origins, true)) { $origins[] = $origin; }
      }
    }
    $this->drawings[$result] = ['transform' => $transform, 'origins' => $origins];
  }

  private function getEntry(object $drawing): array
  {
    if (!$this->drawings->offsetExists($drawing)) { throw new LogicException('Battle field colour requires an explicit source owner for every drawing.'); }
    return $this->drawings[$drawing];
  }
}
