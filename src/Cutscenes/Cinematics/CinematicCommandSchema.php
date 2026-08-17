<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

/**
 * Authoritative cinematic vocabulary shared by runtime and authoring tools.
 *
 * Editor validation consumes these values from Engine code; it must not keep
 * a copied command or subject list.
 *
 * @package Ichiloto\Engine\Cutscenes\Cinematics
 */
final class CinematicCommandSchema
{
  public const array BASE_COMMAND_TYPES = [
    'text', 'choice', 'wait', 'set_switch', 'set_variable', 'record_event',
    'give_item', 'give_gold', 'recover_party', 'play_sound', 'play_music',
    'accept_quest', 'knowledge', 'move_player', 'move_route', 'transfer',
    'start_battle', 'branch',
  ];

  public const array CINEMATIC_COMMAND_TYPES = [
    'sequence', 'parallel', 'common_event', 'checkpoint',
    'camera', 'stage_actor', 'show_actor', 'hide_actor', 'remove_actor',
    'field_animation', 'title_card', 'narration', 'transition',
    'clear_presentation', 'cinematic_music',
  ];

  public const array COMMAND_TYPES = [
    ...self::BASE_COMMAND_TYPES,
    ...self::CINEMATIC_COMMAND_TYPES,
  ];

  public const array BLOCK_TYPES = ['sequence', 'parallel'];
  public const array NESTED_BLOCK_SHAPES = [
    'sequence' => ['commands'],
    'parallel' => ['lanes', 'lane' => ['id', 'commands']],
    'branch' => ['then', 'else'],
    'choice' => ['options', 'option' => ['text', 'then'], 'cancel'],
  ];
  public const array SUBJECT_KINDS = ['player', 'party_actor', 'npc', 'staged_actor', 'position', 'screen_position', 'marker'];
  public const array CAMERA_OPERATIONS = ['detach', 'attach', 'reset', 'focus', 'pan', 'route', 'track', 'shake', 'restore'];
  public const array CINEMATIC_DEFINITION_FIELDS = [
    'id', 'name', 'description', 'version', 'authoring', 'startMap',
    'presentation', 'cast', 'skip', 'checkpoints', 'finalizer',
  ];
  public const array STAGED_ACTOR_FIELDS = [
    'id', 'sprite', 'asset', 'x', 'y', 'facing', 'visible', 'collision', 'sprites',
  ];
  public const array SKIP_POLICIES = ['forbidden', 'authored'];
  public const array FINALIZER_COMMAND_TYPES = [
    'set_switch', 'set_variable', 'record_event', 'move_player', 'transfer',
    'camera', 'remove_actor', 'clear_presentation', 'cinematic_music',
  ];
  public const array UNSAFE_AUTHORED_SKIP_COMMAND_TYPES = [
    'start_battle', 'give_item', 'give_gold', 'accept_quest', 'recover_party', 'knowledge',
  ];
  public const string AUTHORED_SKIP_COMMON_EVENT_POLICY = 'reject';
  public const array FINALIZER_COMMAND_SHAPES = [
    'set_switch' => ['required' => ['name' => 'non-empty string', 'value' => 'bool']],
    'set_variable' => ['required' => ['name' => 'non-empty string', 'value' => 'int|float|string'], 'optional' => ['op' => 'set']],
    'record_event' => ['required' => ['name' => 'stable event identity']],
    'move_player' => ['required' => ['x' => 'int', 'y' => 'int']],
    'transfer' => ['required' => ['map' => 'non-empty string', 'x' => 'int', 'y' => 'int'], 'optional' => ['sprite' => 'string|string[]']],
    'camera' => ['required' => ['operation' => 'attach|reset']],
    'remove_actor' => ['required' => ['actorId|id' => 'non-empty string']],
    'clear_presentation' => ['required' => []],
    'cinematic_music' => [
      'required' => ['track' => 'non-empty string', 'loop' => 'bool', 'completionBehavior|restorePreviousMusic' => 'explicit completion policy'],
      'optional' => ['fadeIn' => 'non-negative number', 'fadeOut' => 'non-negative number'],
    ],
  ];
  public const array MUSIC_COMPLETION_BEHAVIORS = ['continue', 'stop', 'restore_previous'];
  public const array CINEMATIC_MUSIC_FIELDS = [
    'track', 'loop', 'fadeIn', 'fadeOut', 'completionBehavior', 'restorePreviousMusic',
  ];
  public const array SUMMON_DEFINITION_FIELDS = [
    'id', 'name', 'description', 'moveName', 'wielders', 'lore', 'element',
    'strengths', 'weaknesses', 'attributes', 'version', 'linkedSummonId',
    'linkedActionId', 'tags', 'playback', 'transitionIn', 'transitionOut',
    'effectTiming', 'targetPresentation', 'authoring', 'availability',
  ];
  public const array SUMMON_TIMELINE_FIELDS = [
    'formatVersion', 'fps', 'lengthFrames', 'tracks', 'cues', 'editor',
  ];
  public const array SUMMON_PLAYBACK_CONFIG_FIELDS = [
    'defaultSpeed', 'allowSkip', 'loopPreview',
  ];
  public const array SUMMON_PLAYBACK_FIELDS = [
    'totalFrames', 'fps', 'effectiveSpeed', 'secondsPerFrame', 'currentFrame',
    'isPaused', 'isCompleted', 'isLooping', 'traversal',
  ];

  /** @return array<string, mixed> */
  public static function export(): array
  {
    return [
      'commandTypes' => self::COMMAND_TYPES,
      'blockTypes' => self::BLOCK_TYPES,
      'nestedBlockShapes' => self::NESTED_BLOCK_SHAPES,
      'subjectKinds' => self::SUBJECT_KINDS,
      'cameraOperations' => self::CAMERA_OPERATIONS,
      'cinematicDefinitionFields' => self::CINEMATIC_DEFINITION_FIELDS,
      'stagedActorFields' => self::STAGED_ACTOR_FIELDS,
      'skipPolicies' => self::SKIP_POLICIES,
      'finalizerCommandTypes' => self::FINALIZER_COMMAND_TYPES,
      'finalizerCommandShapes' => self::FINALIZER_COMMAND_SHAPES,
      'unsafeAuthoredSkipCommandTypes' => self::UNSAFE_AUTHORED_SKIP_COMMAND_TYPES,
      'authoredSkipCommonEventPolicy' => self::AUTHORED_SKIP_COMMON_EVENT_POLICY,
      'musicCompletionBehaviors' => self::MUSIC_COMPLETION_BEHAVIORS,
      'cinematicMusicFields' => self::CINEMATIC_MUSIC_FIELDS,
      'summonDefinitionFields' => self::SUMMON_DEFINITION_FIELDS,
      'summonTimelineFields' => self::SUMMON_TIMELINE_FIELDS,
      'summonPlaybackConfigFields' => self::SUMMON_PLAYBACK_CONFIG_FIELDS,
      'summonPlaybackFields' => self::SUMMON_PLAYBACK_FIELDS,
    ];
  }
}
