<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations;

use InvalidArgumentException;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;

/** Action-owned travel timing in authored frames, independent of route length. */
final readonly class FlashStepTiming
{
  public function __construct(public int $fps, public int $guardFrames, public int $advanceFrames,
    public int $holdFrames, public int $returnFrames, public int $settleFrames, public int $echoes,
    public bool $overlapEffectTail = false)
  {
    if ($fps < 1 || $fps > 120 || $echoes < 0 || $echoes > 3
      || array_any([$guardFrames, $advanceFrames, $holdFrames, $returnFrames, $settleFrames],
        static fn(int $frames): bool => $frames < 1 || $frames > $fps * 5)) {
      throw new InvalidArgumentException('Flash step requires 1–120 fps, positive phases of at most five seconds and 0–3 echoes.');
    }
  }

  public static function fromArray(mixed $data): self
  {
    $fields = ['fps', 'guardFrames', 'advanceFrames', 'holdFrames', 'returnFrames', 'settleFrames', 'echoes'];
    if (!is_array($data) || array_diff(array_keys($data), [...$fields, 'overlapEffectTail']) !== []
      || !array_all($fields, static fn(string $field): bool => is_int($data[$field] ?? null))
      || (array_key_exists('overlapEffectTail', $data) && !is_bool($data['overlapEffectTail']))) {
      throw new InvalidArgumentException('Flash step requires integer fps, guard, advance, hold, return, settle and echo fields.');
    }
    return new self(...array_map(static fn(string $field): int => $data[$field], $fields),
      overlapEffectTail: $data['overlapEffectTail'] ?? false);
  }

  public function toArray(): array
  {
    $data = get_object_vars($this);
    if (!($data['overlapEffectTail'] ?? false)) { unset($data['overlapEffectTail']); }
    return $data;
  }

  public function supportsPose(?BattlerPose $pose): bool
  {
    return $pose?->impactFrame === 0 && $pose->entryFrames !== [] && $pose->exitFrames !== [];
  }
}
