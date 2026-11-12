<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests;

/**
 * The stable thing on a map that an objective waymark points at.
 *
 * Each case names an identity the map already owns, so moving the subject
 * in the Editor moves the waymark with it. A waymark without a subject
 * points at the map as a whole.
 */
enum QuestWaymarkSubject: string
{
  /** An event-layer marker letter. */
  case EVENT = 'event';
  /** An NPC's stable authored id. */
  case NPC = 'npc';
  /** A world object's stable id. */
  case OBJECT = 'object';
  /** A minimap landmark id; shown only while the landmark is visible. */
  case LANDMARK = 'landmark';
}
