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
    public bool $overlapEffectTail = false, public bool $placeTransitOnPoseKeys = false, public ?FlashStepWake $wake = null)
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
    $flags = ['overlapEffectTail', 'placeTransitOnPoseKeys'];
    if (!is_array($data) || array_diff(array_keys($data), [...$fields, ...$flags, 'wake']) !== []
      || !array_all($fields, static fn(string $field): bool => is_int($data[$field] ?? null))
      || !array_all($flags, static fn(string $flag): bool => !array_key_exists($flag, $data) || is_bool($data[$flag]))) {
      throw new InvalidArgumentException('Flash step requires integer fps, guard, advance, hold, return, settle and echo fields.');
    }
    return new self(...array_map(static fn(string $field): int => $data[$field], $fields),
      overlapEffectTail: $data['overlapEffectTail'] ?? false,
      placeTransitOnPoseKeys: $data['placeTransitOnPoseKeys'] ?? false,
      wake: array_key_exists('wake', $data) ? FlashStepWake::fromArray($data['wake']) : null);
  }

  public function toArray(): array
  {
    $data = array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== false && $value !== null);
    if (isset($data['wake'])) { $data['wake'] = $data['wake']->toArray(); }
    return $data;
  }

  public function supportsPose(?BattlerPose $pose): bool
  {
    return $pose?->impactFrame === 0 && $pose->entryFrames !== [] && $pose->exitFrames !== [];
  }
}
