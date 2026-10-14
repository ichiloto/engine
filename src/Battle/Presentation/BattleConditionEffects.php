<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use Throwable;

/** Project/theme references use state identity and stat role, not battler names. */
final class BattleConditionEffects
{
  private array $bindings = [];
  private array $diagnostics = [];

  public static function createFromConfig(EffectTimelineLibrary $library): self
  {
    return new self($library, \Ichiloto\Engine\Util\Config\ConfigStore::has(\Ichiloto\Engine\Util\Config\ProjectConfig::class)
      ? config(\Ichiloto\Engine\Util\Config\ProjectConfig::class, 'ui.battle.conditions', []) : []);
  }

  public function __construct(private readonly EffectTimelineLibrary $library, array $bindings = [])
  {
    if (array_diff(array_keys($bindings), ['states', 'statStages']) !== []) {
      throw new InvalidArgumentException('Battle condition effects accept states and statStages.');
    }
    foreach (['states', 'statStages'] as $key) {
      if (array_key_exists($key, $bindings) && !is_array($bindings[$key])) {
        throw new InvalidArgumentException('Condition binding groups must be arrays.');
      }
    }
    foreach ($bindings['states'] ?? [] as $id => $effect) {
      if (trim((string)$id) === '') {
        throw new InvalidArgumentException('State effect bindings require existing non-empty state IDs.');
      }
      $this->bind('state:' . $id, $effect);
    }
    foreach ($bindings['statStages'] ?? [] as $stat => $roles) {
      if (!in_array($stat, Character::buffableStats(), true) || !is_array($roles)
        || array_diff(array_keys($roles), ['positive', 'negative']) !== []) {
        throw new InvalidArgumentException('Stat effects require a buffable stat and positive/negative roles.');
      }
      foreach ($roles as $role => $effect) { $this->bind('stat:' . $stat . ':' . $role, $effect); }
    }
  }

  private function bind(string $key, mixed $effect): void
  {
    if (!is_string($effect)) { throw new InvalidArgumentException('Condition effects reference timeline IDs.'); }
    EffectTimelineLibrary::assertId($effect);
    $this->bindings[$key] = $effect;
  }

  public function createPlayback(CharacterInterface $battler, float $seconds, bool $useBindings = true): ?BattlerConditionEffect
  {
    if (!is_finite($seconds) || $seconds < 0) { throw new InvalidArgumentException('Condition time must be finite and non-negative.'); }
    $entries = $battler->isKnockedOut ? [] : BattlerConditions::getEntries($battler);
    return $entries === [] ? null : new BattlerConditionEffect($battler, $entries, $this, $seconds, $useBindings);
  }

  public function getTimeline(string $key, bool $terminal): ?CompiledEffectTimeline
  {
    $id = $this->bindings[$key] ?? null;
    if ($id === null) { return null; }
    try {
      $timeline = $this->library->load($id, true, $terminal ? EffectPresentation::TERMINAL : EffectPresentation::GRAPHICAL);
      if ($timeline->cadence !== EffectCadence::FIXED || !($timeline->defaults['playback']['loop'] ?? false)
        || $timeline->cueSchedule !== [] || isset($timeline->defaults['stage'])) {
        throw new InvalidArgumentException('Condition effects require fixed looping presentation without cues or a stage.');
      }
      foreach ($timeline->playbackSegments as $segment) {
        if (!in_array($segment['layer'], ['image', 'glyph', 'text'], true)) {
          throw new InvalidArgumentException('Condition effects accept only attached image/glyph/text tracks, not flashes or shake.');
        }
        foreach ($segment['drawCommands'] as $command) {
          if (!in_array($command['payload']['anchor'] ?? 'target', ['target', 'caster'], true)) {
            throw new InvalidArgumentException('Condition effects must attach to their battler, not the screen.');
          }
        }
      }
      $rest = $timeline->defaults['restFrame'] ?? 0;
      if ($timeline->playbackSegments !== [] && !array_any($timeline->playbackSegments, static fn(array $segment): bool =>
        $segment['startFrame'] <= $rest && $rest <= $segment['endFrame']
          && array_any($segment['drawCommands'], static fn(array $command): bool => $command['visible'] ?? true))) {
        throw new InvalidArgumentException('A condition effect needs a visible reduced-motion restFrame.');
      }
      return $timeline;
    } catch (Throwable $error) {
      $this->reportFailure($id . ':' . ($terminal ? 'terminal' : 'graphical'), $error);
      return null;
    }
  }

  public function reportFailure(string $key, Throwable $error): void
  {
    if (isset($this->diagnostics[$key])) { return; }
    $this->diagnostics[$key] = true;
    Debug::warn('Battle condition effect unavailable; retaining semantic badges/terminal text: ' . $key . ': ' . $error->getMessage());
  }
}
