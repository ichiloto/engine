<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

/** Story-event namespaces with automatic Engine writers, shared by runtime and authoring analysis. */
enum EngineStoryEvent: string
{
  case SKIT_SEEN = 'skit_seen:';
  case QUEST_COMPLETED = 'quest_completed:';
  case QUEST_DECLINED = 'quest_declined:';
  case CINEMATIC_COMPLETED = 'cinematic:';

  /** Preserve the persistent string contract; the owning content validates its subject id. */
  public function getName(string $subjectId): string
  {
    return $this->value . $subjectId . ($this === self::CINEMATIC_COMPLETED ? ':completed' : '');
  }

  /** Conservative namespace classification, including unknown names within an Engine-written prefix. */
  public static function usesNamespace(string $eventName): bool
  {
    return array_any(self::cases(), static fn(self $kind): bool => str_starts_with($eventName, $kind->value));
  }
}
