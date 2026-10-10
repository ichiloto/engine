<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use InvalidArgumentException;

/** Project-owned stage choices; current party identity decides which guests may be shown. */
final readonly class PartyStageSelection
{
  public const string LEADER = 'leader';
  public const string PARTY = 'party';
  /** @var array<string, string> Stable actor ID => single-guest stage timeline ID. */
  public array $leaders;
  /** @var list<array{actors: list<string>, timeline: string}> Exact guest compositions. */
  public array $parties;

  /**
   * @param array<string, string> $leaders
   * @param list<array{actors: list<string>, timeline: string}> $parties
   */
  public function __construct(public string $treatment, array $leaders = [], array $parties = [])
  {
    if (!in_array($treatment, [self::LEADER, self::PARTY], true)
      || ($treatment === self::LEADER && $parties !== []) || ($treatment === self::PARTY && $leaders !== [])) {
      throw new InvalidArgumentException('Party stages require an explicit leader or party treatment and only its corresponding bindings.');
    }
    if (count($leaders) > 1024 || !array_is_list($parties) || count($parties) > 1024) {
      throw new InvalidArgumentException('Party stage choices require bounded leader bindings and a list of party compositions.');
    }
    $leaderCopy = $partyCopy = $compositions = [];
    foreach ($leaders as $actor => $timeline) {
      // PHP stores numeric-string array keys as integers; actor identity remains a string.
      $leaderCopy[self::requireActorId((string) $actor)] = self::requireTimelineId($timeline);
    }
    foreach ($parties as $party) {
      if (!is_array($party) || array_diff(array_keys($party), ['actors', 'timeline']) !== []
        || !is_array($party['actors'] ?? null) || !array_is_list($party['actors'])
        || $party['actors'] === [] || count($party['actors']) > 1024) {
        throw new InvalidArgumentException('Party stage compositions require a nonempty actor list and a timeline identity.');
      }
      $actors = array_map(self::requireActorId(...), $party['actors']);
      if (count(array_unique($actors)) !== count($actors)) {
        throw new InvalidArgumentException('Party stage compositions cannot repeat an actor identity.');
      }
      $sorted = $actors;
      sort($sorted, SORT_STRING);
      $key = serialize($sorted);
      if (isset($compositions[$key])) {
        throw new InvalidArgumentException('Party stage compositions must be unique regardless of actor order.');
      }
      $compositions[$key] = true;
      $partyCopy[] = ['actors' => $actors, 'timeline' => self::requireTimelineId($party['timeline'] ?? null)];
    }
    $this->leaders = $leaderCopy;
    $this->parties = $partyCopy;
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data): self
  {
    if (array_diff(array_keys($data), ['treatment', 'leaders', 'parties']) !== []
      || !is_string($data['treatment'] ?? null)
      || !is_array($data['leaders'] ?? []) || !is_array($data['parties'] ?? [])
      || (array_key_exists('leaders', $data) && $data['leaders'] === null)
      || (array_key_exists('parties', $data) && $data['parties'] === null)) {
      throw new InvalidArgumentException('Party stage selection requires treatment and accepts leaders or parties descriptors.');
    }
    return new self($data['treatment'], $data['leaders'] ?? [], $data['parties'] ?? []);
  }

  /** Exact membership includes reserves; battle slots and display names are not guest identities. */
  public function selectTimeline(Party $party): ?string
  {
    $actors = [];
    foreach ($party->members as $member) {
      if (!$member instanceof Character) {
        throw new InvalidArgumentException('Party stage selection requires stable actor identities for every guest.');
      }
      $actors[] = self::requireActorId($member->actorId);
    }
    if ($actors === []) { return null; }
    if (count(array_unique($actors)) !== count($actors)) {
      throw new InvalidArgumentException('Party stage selection cannot resolve duplicate guest identities.');
    }
    if ($this->treatment === self::LEADER) {
      return $this->leaders[$party->leader?->actorId ?? ''] ?? null;
    }
    sort($actors, SORT_STRING);
    foreach ($this->parties as $composition) {
      $expected = $composition['actors'];
      sort($expected, SORT_STRING);
      if ($actors === $expected) { return $composition['timeline']; }
    }
    return null;
  }

  /** @return array{treatment: string, leaders: array<string, string>, parties: list<array{actors: list<string>, timeline: string}>} */
  public function toArray(): array
  {
    return ['treatment' => $this->treatment, 'leaders' => $this->leaders, 'parties' => $this->parties];
  }

  /** @return list<string> Every reachable stage identity, independent of the current party. */
  public function getTimelineIds(): array
  {
    return array_values(array_unique([
      ...array_values($this->leaders),
      ...array_column($this->parties, 'timeline'),
    ]));
  }

  private static function requireActorId(mixed $id): string
  {
    if (!is_string($id) || trim($id) === '' || trim($id) !== $id) {
      throw new InvalidArgumentException('Party stage bindings require explicit nonempty stable actor identities.');
    }
    return $id;
  }

  private static function requireTimelineId(mixed $id): string
  {
    if (!is_string($id)) { throw new InvalidArgumentException('Party stage bindings require stable timeline identities.'); }
    EffectTimelineLibrary::assertId($id);
    return $id;
  }
}
