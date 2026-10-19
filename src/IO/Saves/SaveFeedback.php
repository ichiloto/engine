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
      $error instanceof WrongProjectSaveException => get_engine_message('save.wrong_game'),
      $error instanceof UnsupportedSaveSchemaException, $error instanceof UnsupportedContentVersionException
        => get_engine_message('save.newer_version'),
      $error instanceof CorruptSaveException => get_engine_message('save.unreadable'),
      default => get_engine_message('save.load_failed'),
    };
  }

  public static function describeWriteFailure(Throwable $error): string
  {
    return $error instanceof ActiveEventSaveException
      ? get_engine_message('save.event_in_progress')
      : get_engine_message('save.write_failed');
  }
}
