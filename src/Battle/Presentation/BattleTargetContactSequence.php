<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** A graphical front crossing the selected bodies; it never schedules combat. */
final readonly class BattleTargetContactSequence
{
  /** @param list<string> $tracks */
  public function __construct(
    public int $firstFrame,
    public int $lastFrame,
    public BattleContactDirection $direction,
    public array $tracks,
  ) {
    if ($firstFrame < 0 || $lastFrame < $firstFrame || $tracks === [] || !array_is_list($tracks)
      || array_any($tracks, static fn(mixed $track): bool => !is_string($track) || $track === '')
      || count(array_unique($tracks)) !== count($tracks)) {
      throw new InvalidArgumentException('Target contact frames and track references must be valid.');
    }
  }

  /** @param list<array> $tracks */
  public static function fromArray(mixed $data, int $length, array $tracks): self
  {
    if (!is_array($data)) { throw new InvalidArgumentException('Target contacts require an object.'); }
    $direction = is_string($data['direction'] ?? null) ? BattleContactDirection::tryFrom($data['direction']) : null;
    if (!is_array($data) || array_diff(array_keys($data), ['firstFrame', 'lastFrame', 'direction', 'tracks']) !== []
      || !is_int($data['firstFrame'] ?? null) || !is_int($data['lastFrame'] ?? null)
      || $data['firstFrame'] < 0 || $data['lastFrame'] < $data['firstFrame'] || $data['lastFrame'] >= $length
      || $direction === null || !is_array($data['tracks'] ?? null) || !array_is_list($data['tracks'])
      || $data['tracks'] === [] || array_any($data['tracks'], static fn(mixed $id): bool => !is_string($id))
      || count(array_unique($data['tracks'])) !== count($data['tracks'])) {
      throw new InvalidArgumentException('Target contacts require an east/west front, first/last frames and distinct track references.');
    }
    $delay = $data['lastFrame'] - $data['firstFrame'];
    foreach ($data['tracks'] as $id) {
      $track = array_find($tracks, static fn(array $track): bool => ($track['id'] ?? null) === $id);
      if ($track === null || !in_array($track['type'] ?? null, ['image', 'flash', 'shake'], true)
        || ($track['anchor'] ?? 'target') !== 'target' || ($track['scope'] ?? null) === 'screen'
        || ($track['presentation'] ?? 'all') !== 'graphical' || isset($track['detachFrame'])
        || ($track['keyframes'] ?? []) === []) {
        throw new InvalidArgumentException('Target contact tracks must reference graphical target images, flashes or shakes.');
      }
      foreach ($track['keyframes'] as $key) {
        if (!is_array($key) || !is_int($key['frame'] ?? null) || !is_int($key['duration'] ?? 1)
          || ($key['payload']['anchor'] ?? 'target') !== 'target' || ($key['payload']['scope'] ?? null) === 'screen'
          || $key['frame'] < $data['firstFrame'] || ($key['duration'] ?? 1) < 1
          || $key['frame'] + ($key['duration'] ?? 1) + $delay > $length) {
          throw new InvalidArgumentException('Target contact tracks must fit after the first contact including the final target delay.');
        }
      }
    }
    return new self($data['firstFrame'], $data['lastFrame'], $direction, $data['tracks']);
  }

  public function toArray(): array
  {
    return ['firstFrame' => $this->firstFrame, 'lastFrame' => $this->lastFrame,
      'direction' => $this->direction->value, 'tracks' => $this->tracks];
  }

  /** @return array<int, int> Captured identity => contact frame, in front-crossing order. */
  public function getContactFrames(BattleEffectTargetGroup $group): array
  {
    $positions = [];
    foreach ($group->memberBounds as $id => $box) {
      $positions[$id] = $this->direction === BattleContactDirection::WEST ? -($box->x + $box->width) : $box->x;
    }
    asort($positions, SORT_NUMERIC);
    $near = reset($positions);
    $span = end($positions) - $near;
    $frames = [];
    foreach ($positions as $id => $position) {
      $frames[$id] = $this->firstFrame + ($span > 0 ? (int)round(($position - $near) / $span * ($this->lastFrame - $this->firstFrame)) : 0);
    }
    return $frames;
  }
}
