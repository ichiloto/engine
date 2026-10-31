<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use InvalidArgumentException;

/** A process-local field destination, not an authored spawn or saved position. */
final readonly class GameStartLocation
{
    public function __construct(public string $mapId, public int $x, public int $y)
    {
        if (preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/', $mapId) !== 1 || $x < 0 || $y < 0) {
            throw new InvalidArgumentException('A launch start needs a logical map ID and nonnegative whole-number cells.');
        }
    }

    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['mapId', 'x', 'y']) !== []
            || !is_string($data['mapId'] ?? null) || !is_int($data['x'] ?? null) || !is_int($data['y'] ?? null)) {
            throw new InvalidArgumentException('A launch start contains exactly mapId, x and y.');
        }
        return new self($data['mapId'], $data['x'], $data['y']);
    }

    /** Uses the same prepared physical map that MapManager uses for transfers. */
    public function validatePreparedMap(PreparedMap $map, GameConfig $initialConfig): void
    {
        $type = isset($map->collisions[$this->y][$this->x])
            ? CollisionType::tryFrom($map->collisions[$this->y][$this->x]) : null;
        if ($type === null || $type->blocksMovement()) {
            throw new InvalidArgumentException('The launch cell is outside the map or blocks movement.');
        }
        $state = GameState::fromArray($initialConfig->gameState);
        $quests = QuestLog::fromArray($initialConfig->questLog);
        foreach ($map->data['npcs'] ?? [] as $entry) {
            $npc = NpcPlacement::fromArray($entry, $state, $this->mapId);
            if ($npc !== null && $npc->x === $this->x && $npc->y === $this->y
                && WorldConditionEvaluator::allHold($npc->conditions, $state, $initialConfig->party, $quests)) {
                throw new InvalidArgumentException('The launch cell is occupied by an NPC.');
            }
        }
    }
}
