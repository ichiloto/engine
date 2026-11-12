<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Audio\CinematicSoundOperation;
use RuntimeException;

/** Plays a lane-owned cue without making scene timing depend on an audio backend. */
final class CinematicSoundCommand implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    if ($context->battle !== null) { throw new RuntimeException('cinematic_sound runs only in field scripts.'); }

    $sound = $command['sound'] ?? null;
    $seconds = $command['seconds'] ?? null;
    $volume = $command['volume'] ?? 1.0;
    if (!is_string($sound) || trim($sound) === ''
      || (!is_int($seconds) && !is_float($seconds)) || !is_finite((float) $seconds) || $seconds < 0
      || (!is_int($volume) && !is_float($volume)) || !is_finite((float) $volume) || $volume < 0 || $volume > 1) {
      throw new RuntimeException('cinematic_sound requires a sound, finite nonnegative seconds and volume from zero to one.');
    }

    if ($seconds == 0) { return ScriptCommandOutcome::complete(); }

    $playback = $context->scene->getGame()->audioManager->startSoundEffect($sound, $volume);

    return ScriptCommandOutcome::waitFor(new CinematicSoundOperation((float) $seconds, $playback));
  }
}
