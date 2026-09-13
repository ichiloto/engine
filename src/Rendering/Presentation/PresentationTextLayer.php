<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

final readonly class PresentationTextLayer
{
  /** @var list<PresentationTextRun> */
  public array $runs;

  /** @param list<PresentationTextRun> $runs */
  public function __construct(public string $id, public int $layer, array $runs)
  {
    if ($id === '' || strlen($id) > 256 || preg_match('//u', $id) !== 1 || !array_is_list($runs)) {
      throw new InvalidArgumentException('Text layer requires a nonempty UTF-8 ID of at most 256 bytes and a run list.');
    }
    SpriteValidation::validateSigned32BitRange($layer);
    $copy = [];
    foreach ($runs as $run) {
      if (!$run instanceof PresentationTextRun) { throw new InvalidArgumentException('Text layers require typed runs.'); }
      $copy[] = $run;
    }
    $this->runs = $copy;
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['id' => $this->id, 'layer' => $this->layer,
      'runs' => array_map(static fn(PresentationTextRun $run) => $run->toArray(), $this->runs)];
  }
}
