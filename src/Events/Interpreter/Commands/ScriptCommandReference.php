<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * The project resources a script command field can name.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
enum ScriptCommandReference: string
{
  /** An entry in the item store: an item, weapon or armour. */
  case ITEM = 'item';
  /** A background music track. */
  case MUSIC = 'music';
  /** A sound effect. */
  case SOUND = 'sound';
  /** A map. */
  case MAP = 'map';
  /** A troop. */
  case TROOP = 'troop';
  /** A quest. */
  case QUEST = 'quest';
  /** An actor. */
  case ACTOR = 'actor';
  /** A stable NPC identity from the map containing the script. */
  case NPC = 'npc';
  /** A named destination declared by the selected NPC, including its default placement. */
  case NPC_PLACEMENT = 'npc_placement';
  /** A group declared by the active cinematic's conversation. */
  case CONVERSATION_GROUP = 'conversation_group';
  /** A standalone, cue-free stage timeline admitted by EffectTimelineLibrary::loadStage(). */
  case STAGE_TIMELINE = 'stage_timeline';
  /** An income schedule declared in the project's income data. */
  case INCOME_SCHEDULE = 'income_schedule';
  /** A member of the troop whose battle event runs, by its index in the troop's enemies. */
  case TROOP_MEMBER = 'troop_member';
}
