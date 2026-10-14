<?php

namespace Ichiloto\Engine\IO\Saves;

use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\Exceptions\CorruptSaveException;
use Ichiloto\Engine\Exceptions\UnsupportedContentVersionException;
use Ichiloto\Engine\Exceptions\UnsupportedSaveSchemaException;
use Ichiloto\Engine\Exceptions\WrongProjectSaveException;
use Throwable;

/** Player-facing save feedback; exception details belong only in diagnostics. */
final class SaveFeedback
{
  public static function describeLoadFailure(Throwable $error): string
  {
    return match (true) {
      $error instanceof WrongProjectSaveException => get_message('save.wrong_game', 'This save belongs to a different game.'),
      $error instanceof UnsupportedSaveSchemaException, $error instanceof UnsupportedContentVersionException
        => get_message('save.newer_version', 'This save needs a newer version of the game.'),
      $error instanceof CorruptSaveException => get_message('save.unreadable', 'This save could not be read. Please choose another save.'),
      default => get_message('save.load_failed', 'This save could not be loaded. Please choose another save.'),
    };
  }

  public static function describeWriteFailure(Throwable $error): string
  {
    return $error instanceof ActiveEventSaveException
      ? get_message('save.event_in_progress', 'Please wait until the current event finishes before saving.')
      : get_message('save.write_failed', 'Your progress could not be saved. Please try again.');
  }
}
