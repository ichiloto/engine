<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

/**
 * Saved content identities that may need an explicit project migration.
 *
 * This enum is shared with project tooling so runtime and editor validation
 * cannot silently grow different compatibility vocabularies.
 */
enum ContentReferenceCategory: string
{
  case MAP = 'maps';
  case ONE_SHOT_EVENT = 'one_shot_events';
  case QUEST = 'quests';
  case ACTOR = 'actors';
  case ITEM = 'items';
  case EQUIPMENT = 'equipment';
  case ABILITY = 'abilities';
  case SPELL = 'spells';
  case SUMMON = 'summons';
  case STATE = 'states';
  case STORY_EVENT = 'story_events';
  case ENEMY = 'enemies';
  case ACHIEVEMENT = 'achievements';
}
