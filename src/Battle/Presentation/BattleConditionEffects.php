<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use Throwable;

/** Project/theme references use state identity and stat role, not battler names. */
final class BattleConditionEffects
{
  private array $bindings = [];
  private array $diagnostics = [];
  private readonly BattleConditionSlots $slots;

  public static function createFromConfig(EffectTimelineLibrary $library): self
  {
    return new self($library, \Ichiloto\Engine\Util\Config\ConfigStore::has(\Ichiloto\Engine\Util\Config\ProjectConfig::class)
      ? config(\Ichiloto\Engine\Util\Config\ProjectConfig::class, 'ui.battle.conditions', []) : []);
  }

  public function __construct(private readonly EffectTimelineLibrary $library, array $bindings = [])
  {
    $this->slots = new BattleConditionSlots();
    if (array_diff(array_keys($bindings), ['states', 'statStages', 'interceptions']) !== []) {
      throw new InvalidArgumentException('Battle condition effects accept states, statStages and interceptions.');
    }
    foreach (['states', 'statStages', 'interceptions'] as $key) {
      if (array_key_exists($key, $bindings) && !is_array($bindings[$key])) {
        throw new InvalidArgumentException('Condition binding groups must be arrays.');
      }
    }
    foreach (['states' => 'state:', 'interceptions' => 'interception:'] as $group => $prefix) {
      foreach ($bindings[$group] ?? [] as $id => $effect) {
        if (trim((string)$id) === '') {
          throw new InvalidArgumentException('State effect bindings require existing non-empty state IDs.');
        }
        $this->bind($prefix . $id, $effect);
      }
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
    $entries = $this->slots->assign($battler, BattlerConditions::getEntries($battler),
      static fn(array $entry): int => TerminalText::displayWidth($entry['glyph']) + 1);
    return !$battler->isFighting || $entries === [] ? null : new BattlerConditionEffect($battler, $entries, $this, $seconds, $useBindings);
  }

  public function getTimeline(string $key, bool $terminal): ?CompiledEffectTimeline
  {
    return $this->loadTimeline($key, $terminal ? EffectPresentation::TERMINAL : EffectPresentation::GRAPHICAL, loop: true);
  }

  public function getInterceptionTimeline(string $stateId, EffectPresentation $presentation): ?CompiledEffectTimeline
  {
    return $this->loadTimeline('interception:' . $stateId, $presentation, loop: false);
  }

  /** Loads an authored timeline without the runtime fallback, for authoring validation. */
  public function loadInterceptionTimeline(string $id, EffectPresentation $presentation): CompiledEffectTimeline
  {
    return $this->loadValidatedTimeline($id, $presentation, loop: false);
  }

  private function loadTimeline(string $key, EffectPresentation $presentation, bool $loop): ?CompiledEffectTimeline
  {
    $id = $this->bindings[$key] ?? null;
    if ($id === null) { return null; }
    try {
      return $loop ? $this->loadValidatedTimeline($id, $presentation, loop: true)
        : $this->loadInterceptionTimeline($id, $presentation);
    } catch (Throwable $error) {
      $this->reportFailure($id . ':' . $presentation->value, $error);
      return null;
    }
  }

  private function loadValidatedTimeline(string $id, EffectPresentation $presentation, bool $loop): CompiledEffectTimeline
  {
    $timeline = $this->library->load($id, true, $presentation);
    if ($timeline->cadence !== EffectCadence::FIXED || ($timeline->defaults['playback']['loop'] ?? false) !== $loop
      || $timeline->cueSchedule !== [] || isset($timeline->defaults['stage'])) {
      $kind = $loop ? 'Looping condition' : 'One-shot interception';
      throw new InvalidArgumentException($kind . ' effects require fixed presentation without cues or a stage.');
    }
    $anchors = $loop ? ['target', 'caster'] : ['target'];
    foreach ($timeline->playbackSegments as $segment) {
      if (!in_array($segment['layer'], ['image', 'glyph', 'text', 'tone'], true)) {
        throw new InvalidArgumentException('Condition effects accept attached image, glyph, text and tone tracks.');
      }
      foreach ($segment['drawCommands'] as $command) {
        if (!in_array($command['payload']['anchor'] ?? 'target', $anchors, true)) {
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
  }

  public function reportFailure(string $key, Throwable $error): void
  {
    if (isset($this->diagnostics[$key])) { return; }
    $this->diagnostics[$key] = true;
    Debug::warn('Battle condition effect unavailable; retaining semantic badges/terminal text: ' . $key . ': ' . $error->getMessage());
  }
}
