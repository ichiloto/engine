<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;
use Ichiloto\Engine\Animations\Timelines\{BattleBattlerReactionTrack, EffectMotionScope, EffectTrackType};
use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** Graphical contact timing for captured recipients. Combat resolves on its own clock. */
final readonly class BattleTargetContactSequence
{
  public const string FIELD = 'targetContactSequence';
  public const string RESULT_FIELD = 'presentResults';
  public const string PREVIEW_REACTION_FIELD = 'previewReaction';

  /** @param list<string> $tracks */
  public function __construct(
    public int $firstFrame,
    public int $lastFrame,
    public ?BattleContactDirection $direction,
    public array $tracks,
    public ?int $intervalFrames = null,
    public bool $presentsResults = false,
    public ?BattlePoseRole $previewReaction = null,
  ) {
    if ($firstFrame < 0 || $lastFrame < $firstFrame || $tracks === [] || !array_is_list($tracks)
      || ($direction === null) === ($intervalFrames === null) || ($intervalFrames !== null && $intervalFrames < 1)
      || array_any($tracks, static fn(mixed $track): bool => !is_string($track) || $track === '')
      || count(array_unique($tracks)) !== count($tracks)) {
      throw new InvalidArgumentException('Target contact frames and track references must be valid.');
    }
    if ($previewReaction !== null && (!$presentsResults
      || !in_array($previewReaction->value, BattleBattlerReactionTrack::ROLES, true))) {
      throw new InvalidArgumentException('A contact preview reaction requires contact results and a damage or heal pose.');
    }
  }

  /** The track's presentation and anchors are valid for replay at each captured body. */
  public static function canUseTrack(mixed $track): bool
  {
    $type = is_array($track) && is_string($track['type'] ?? null) ? EffectTrackType::tryFrom($track['type']) : null;
    $motion = $track['motion'] ?? 'all';
    $motion = is_string($motion) ? EffectMotionScope::tryFrom($motion) : null;
    return in_array($type, [EffectTrackType::IMAGE, EffectTrackType::FLASH, EffectTrackType::SHAKE, EffectTrackType::BATTLER_REACTION], true)
      && $motion !== null && is_string($track['id'] ?? null) && $track['id'] !== ''
      && ($track['anchor'] ?? 'target') === 'target' && ($track['scope'] ?? null) !== 'screen'
      && ($track['presentation'] ?? $type->getDefaultPresentation($motion)) === 'graphical' && !isset($track['detachFrame'])
      && is_array($track['keyframes'] ?? null) && $track['keyframes'] !== []
      && array_all($track['keyframes'], static fn(mixed $key): bool =>
        is_array($key) && is_int($key['frame'] ?? null) && is_int($key['duration'] ?? 1)
        && ($key['duration'] ?? 1) > 0
        && ($key['payload']['anchor'] ?? 'target') === 'target' && ($key['payload']['scope'] ?? null) !== 'screen');
  }

  /** @param list<array> $tracks */
  public static function fromArray(mixed $data, int $length, array $tracks): self
  {
    if (!is_array($data)) { throw new InvalidArgumentException('Target contacts require an object.'); }
    $direction = is_string($data['direction'] ?? null) ? BattleContactDirection::tryFrom($data['direction']) : null;
    $hasInterval = array_key_exists('intervalFrames', $data);
    $interval = $data['intervalFrames'] ?? null;
    if (array_diff(array_keys($data), ['firstFrame', 'lastFrame', 'direction', 'tracks', 'intervalFrames', self::RESULT_FIELD, self::PREVIEW_REACTION_FIELD]) !== []
      || !is_int($data['firstFrame'] ?? null) || !is_int($data['lastFrame'] ?? null)
      || $data['firstFrame'] < 0 || $data['lastFrame'] < $data['firstFrame'] || $data['lastFrame'] >= $length
      || ($hasInterval ? (!is_int($interval) || $interval < 1 || array_key_exists('direction', $data)) : $direction === null)
      || !is_array($data['tracks'] ?? null) || !array_is_list($data['tracks'])
      || $data['tracks'] === [] || array_any($data['tracks'], static fn(mixed $id): bool => !is_string($id))
      || count(array_unique($data['tracks'])) !== count($data['tracks'])) {
      throw new InvalidArgumentException('Target contacts require an east/west front or a positive stagger interval, first/last frames and distinct track references.');
    }
    if (array_key_exists(self::RESULT_FIELD, $data) && !is_bool($data[self::RESULT_FIELD])) {
      throw new InvalidArgumentException('Target contact result presentation must be true or false.');
    }
    $preview = $data[self::PREVIEW_REACTION_FIELD] ?? null;
    $previewRole = is_string($preview) ? BattlePoseRole::tryFrom($preview) : null;
    if (array_key_exists(self::PREVIEW_REACTION_FIELD, $data) && $previewRole === null) {
      throw new InvalidArgumentException('A contact preview reaction must name a battle pose.');
    }
    $delay = $data['lastFrame'] - $data['firstFrame'];
    foreach ($data['tracks'] as $id) {
      $track = array_find($tracks, static fn(array $track): bool => ($track['id'] ?? null) === $id);
      if (!self::canUseTrack($track)) {
        throw new InvalidArgumentException('Target contact tracks must reference graphical target images, flashes, shakes or battler reactions.');
      }
      foreach ($track['keyframes'] as $key) {
        if ($key['frame'] < $data['firstFrame']
          || $key['frame'] + ($key['duration'] ?? 1) + $delay > $length) {
          throw new InvalidArgumentException('Target contact tracks must fit after the first contact including the final target delay.');
        }
      }
    }
    return new self($data['firstFrame'], $data['lastFrame'], $direction, $data['tracks'], $interval,
      $data[self::RESULT_FIELD] ?? false, $previewRole);
  }

  public function toArray(): array
  {
    return ['firstFrame' => $this->firstFrame, 'lastFrame' => $this->lastFrame,
      ...($this->direction === null ? ['intervalFrames' => $this->intervalFrames] : ['direction' => $this->direction->value]),
      'tracks' => $this->tracks,
      ...($this->presentsResults ? [self::RESULT_FIELD => true] : []),
      ...($this->previewReaction === null ? [] : [self::PREVIEW_REACTION_FIELD => $this->previewReaction->value])];
  }

  /**
   * @param ?Closure(int): int $projectFrame Projection from authored frames to the caller's playback clock.
   * @return array<int, int> Captured identity => contact frame, in contact order.
   */
  public function getContactFrames(BattleEffectTargetGroup $group, ?Vector2 $casterGround = null, ?Closure $projectFrame = null): array
  {
    $projectFrame ??= static fn(int $frame): int => $frame;
    if ($this->intervalFrames !== null) {
      return $this->getStaggeredFrames($group, $casterGround, $this->intervalFrames, $projectFrame);
    }
    $firstFrame = $projectFrame($this->firstFrame);
    $lastFrame = $projectFrame($this->lastFrame);
    $positions = [];
    foreach ($group->memberBounds as $id => $box) {
      $positions[$id] = $this->direction === BattleContactDirection::WEST ? -($box->x + $box->width) : $box->x;
    }
    asort($positions, SORT_NUMERIC);
    $near = reset($positions);
    $span = end($positions) - $near;
    $frames = [];
    foreach ($positions as $id => $position) {
      $frames[$id] = $firstFrame + ($span > 0 ? (int)round(($position - $near) / $span * ($lastFrame - $firstFrame)) : 0);
    }
    return $frames;
  }

  /** Use the captured ground plane, preserving selection order at equal distances. */
  private function getStaggeredFrames(BattleEffectTargetGroup $group, ?Vector2 $casterGround, int $interval, Closure $projectFrame): array
  {
    if ($casterGround === null) {
      throw new InvalidArgumentException('Staggered graphical contacts require a captured caster ground point.');
    }
    if (count($group->grounds) - 1 > intdiv($this->lastFrame - $this->firstFrame, $interval)) {
      throw new InvalidArgumentException('The staggered graphical contacts exceed the authored contact window.');
    }
    $distances = [];
    foreach ($group->grounds as $id => $point) {
      $distances[$id] = hypot($point->x - $casterGround->x, $point->y - $casterGround->y);
    }
    asort($distances, SORT_NUMERIC);
    $frames = [];
    foreach (array_keys($distances) as $rank => $id) {
      $frames[$id] = $projectFrame($this->firstFrame + $rank * $interval);
    }
    return $frames;
  }
}
