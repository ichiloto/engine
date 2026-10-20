<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;

/**
 * Where an event script runs, which decides the commands it may use.
 *
 * Field scripts own the map, the player and the camera. Battle event pages
 * run over a paused battle, so they keep to commands that change the world
 * state, talk, wait or play audio, plus registered commands that declare
 * the battle scope.
 *
 * @package Ichiloto\Engine\Events\Interpreter
 */
enum EventExecutionScope: string
{
  case FIELD = 'field';
  case BATTLE = 'battle';

  /** The built-in commands a battle event page may use. */
  public const array BATTLE_COMMAND_TYPES = [
    'text', 'choice', 'wait', 'set_switch', 'set_variable', 'record_event',
    'give_item', 'give_gold', 'play_sound', 'play_music', 'branch', 'sequence', 'common_event',
  ];

  /** Whether a built-in command type may run in this scope. */
  public function allowsBuiltInCommand(string $type): bool
  {
    return in_array($type, match ($this) {
      self::FIELD => CinematicCommandSchema::COMMAND_TYPES,
      self::BATTLE => self::BATTLE_COMMAND_TYPES,
    }, true);
  }

  /** The scope's name in author-facing messages. */
  public function getLabel(): string
  {
    return match ($this) {
      self::FIELD => 'field scripts',
      self::BATTLE => 'battle events',
    };
  }
}
