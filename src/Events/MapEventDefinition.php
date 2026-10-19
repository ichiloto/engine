<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events;

/**
 * Shared map-event envelope read by MapEventResolver and EventTriggerFactory.
 *
 * Split-map definitions normally use their single-character array key as the
 * marker. Indexed definitions may declare marker explicitly. A subject owns
 * the resolved cells; world objects also contribute visibility conditions.
 * Named map areas supply logical territory without artwork or collision.
 * A subject cannot coexist with painted marker cells or an authored area.
 *
 * Legacy indexed definitions may instead supply an explicit area. Resolved
 * definitions carry area and, when available, marker into the trigger factory.
 * These fields describe the vocabulary, not permission to combine independent
 * area owners: MapEventResolver retains the context-sensitive checks.
 * Trigger-specific payload fields belong to the trigger's data contract.
 */
final class MapEventDefinition
{
  public const array ROOT_FIELDS = [
    'class', 'data', 'area', 'marker', 'subject', 'conditions', 'sets',
    'whenBlocked', 'cue', 'facing', 'engagement',
  ];

  public const array SUBJECT_FIELDS = ['kind', 'id'];
  public const array SUBJECT_KINDS = ['world_object', 'area'];

  private function __construct()
  {
  }
}
