<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Events\MapEventDefinition;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;

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
  public const array SUBJECT_KINDS = ['player', 'party_actor', 'npc', 'world_object', 'staged_actor', 'position', 'screen_position', 'marker'];
  public const array CAMERA_OPERATIONS = ['detach', 'attach', 'reset', 'focus', 'pan', 'route', 'track', 'shake', 'restore'];
  public const array CINEMATIC_DEFINITION_FIELDS = [
    'id', 'name', 'description', 'version', 'authoring', 'startMap',
    'presentation', 'cast', 'skip', 'checkpoints', 'finalizer',
  ];
  public const string CINEMATIC_TRIGGER_CLASS = 'Ichiloto\\Engine\\Events\\Triggers\\CinematicEventTrigger';
  public const array CINEMATIC_TRIGGER_ROOT_FIELDS = MapEventDefinition::ROOT_FIELDS;
  public const array CINEMATIC_TRIGGER_DATA_FIELDS = ['cinematicId', 'mode', 'reusable'];
  public const array CINEMATIC_TRIGGER_MODES = ['action', 'auto'];
  public const array CINEMATIC_TRIGGER_COMPLETION = [
    'success' => 'normal_or_legal_skip',
    'sets' => 'after_success_only',
    'oneShot' => 'mapId:marker',
    'failure' => 'retryable_without_completion_writes',
  ];
  public const array STAGED_ACTOR_FIELDS = [
    'id', 'sprite', 'asset', 'x', 'y', 'facing', 'faceToward', 'visible', 'collision', 'sprites', 'sprites2d',
    'subject', 'suppress', 'replace', 'at', 'offset', 'appearance',
  ];
  /** Map subjects whose authored look a staged actor can wear. */
  public const array STAGED_ACTOR_APPEARANCE_KINDS = ['npc'];
  /**
   * The fields that give a staged actor its graphical look: its own art, the
   * subject it stands for, or the map NPC whose look it wears. An actor
   * staged with none of them is drawn by its terminal glyph in a graphical
   * field, which reports it as a fallback.
   */
  public const array STAGED_ACTOR_GRAPHICAL_FIELDS = ['sprites2d', 'subject', 'appearance'];
  public const array RELATIVE_POSITION_FIELDS = ['at', 'offset'];
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
    'effectTiming', 'targetPresentation', 'authoring', 'availability', 'elementAffinities', 'effectiveAgainst',
  ];
  public const array SUMMON_TIMELINE_FIELDS = [
    'formatVersion', 'fps', 'lengthFrames', 'restFrame', 'presentations', 'stage', 'tracks', 'cues', 'editor',
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
      'textPresentation' => [
        'fields' => DialogueContext::TEXT_FIELDS,
        'emotion' => ['type' => 'non-empty string', 'default' => DialogueContext::DEFAULT_EMOTION,
          'source' => 'case-sensitive expression key on the existing speaker identity in DialoguePresentationCatalog'],
        'speaker' => 'name remains authored display text; existing catalog speaker aliases resolve artwork identity',
        'missingArtwork' => 'existing expression, Neutral and base-role fallback; no required portrait',
      ],
      'cinematicDefinitionFields' => self::CINEMATIC_DEFINITION_FIELDS,
      'cinematicEventTrigger' => [
        'class' => self::CINEMATIC_TRIGGER_CLASS,
        'rootFields' => self::CINEMATIC_TRIGGER_ROOT_FIELDS,
        'dataFields' => self::CINEMATIC_TRIGGER_DATA_FIELDS,
        'modes' => self::CINEMATIC_TRIGGER_MODES,
        'completion' => self::CINEMATIC_TRIGGER_COMPLETION,
      ],
      'stagedActorFields' => self::STAGED_ACTOR_FIELDS,
      'stagedActorGraphicalFields' => self::STAGED_ACTOR_GRAPHICAL_FIELDS,
      'stagedPoseAnimation' => [
        'field' => 'sprites2d.animation',
        'fields' => FieldPoseAnimation::FIELDS,
        'maxGridAxis' => FieldPoseAnimation::MAX_GRID_AXIS,
        'maxFrames' => FieldPoseAnimation::MAX_FRAMES,
        'maxFps' => FieldPoseAnimation::MAX_FPS,
        'defaultFps' => FieldPoseAnimation::DEFAULT_FPS,
        'frameOrder' => 'required non-empty source-cell list; row-major indices, repeats allowed',
        'defaults' => ['columns' => 1, 'rows' => 1, 'loop' => true, 'restFrame' => 0],
        'sourceRect' => 'optional sheet region; current image dimensions define its equal grid cells',
      ],
      'movementRoute' => [
        'modes' => ['steps', 'waypoints', 'retrace'],
        'waypoints' => 'non-empty list: integer x/y (omitted axis retains current coordinate), or at kind/id with optional signed integer offset x/y; either may add an end facing',
        'planning' => 'deterministic cardinal path per authored waypoint, bounded by current map; no replan after a blocked step',
        'remember' => 'unique session-local id; cinematic real player or NPC only',
        'retrace' => 'consume completed recorded path once from the original endpoint, then restore entry facing',
        'ownership' => 'one route per subject; current object, map and presentation generation',
      ],
      'relativePosition' => [
        'fields' => self::RELATIVE_POSITION_FIELDS,
        'subjectKinds' => CinematicRelativePosition::SUBJECT_KINDS,
        'offset' => 'optional signed integer x/y cells within -16384..16384, omitted axes zero',
        'consumers' => ['move_route.waypoints', 'stage_actor'],
        'resolution' => 'current subject position at command or route leg start; no independent x/y or real-subject binding',
        'attachment' => 'stage_actor only: at.attachment names an attachment declared by the world_object\'s resource; '
          . 'the terminal shows the visual on the attachment cell and graphical art is centred on its point of the current artwork; no offset; the visual cannot move',
      ],
      'endFacing' => [
        'fields' => CinematicFacing::FIELDS,
        'cardinals' => CinematicFacing::CARDINALS,
        'subjectKinds' => CinematicRelativePosition::SUBJECT_KINDS,
        'consumers' => ['move_route.waypoints', 'stage_actor'],
        'resolution' => 'facing is fixed; faceToward turns along the longer axis toward the subject (an even split faces vertically) from the staged cell, resolved at command or route leg start; on the subject itself the heading is kept',
        'exclusive' => 'facing and faceToward are never declared together; a bound visual declares neither',
      ],
      'stagedActorBinding' => [
        'subject' => ['kind' => 'player|npc|world_object', 'id' => 'required for npc/world_object'],
        'suppress' => 'additional real subject references sharing this visual',
        'replace' => 'explicitly replace an existing visual without resetting captured transforms',
        'movement' => 'route a real player/NPC; world-object anchors are fixed; bound visuals have no independent transform or collision',
      ],
      'stagedActorAppearance' => [
        'kinds' => self::STAGED_ACTOR_APPEARANCE_KINDS,
        'id' => 'required stable map NPC id on the cinematic\'s current map',
        'look' => 'the NPC\'s terminal glyph, directional glyphs, graphical character sheet and heading, read even while its conditions hide it',
        'ownership' => 'an independent staged actor that owns its own place, facing, visibility and collision; it never binds or suppresses the NPC',
        'exclusive' => 'appearance is the only look: no sprite, sprites, sprites2d, asset or subject beside it',
      ],
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
