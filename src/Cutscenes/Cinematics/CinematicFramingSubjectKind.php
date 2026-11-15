<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

/** Field identities supported by character framing on both presentation paths. */
enum CinematicFramingSubjectKind: string
{
    case PLAYER = 'player';
    case NPC = 'npc';
    case STAGED_ACTOR = 'staged_actor';
}
