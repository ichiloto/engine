<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Scenes\Arena\ArenaScene;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Narrow runtime launch instructions; never merged into project configuration. */
final readonly class GameLaunchContext
{
    public const string ENVIRONMENT_VARIABLE = 'ICHILOTO_LAUNCH_CONTEXT';
    public const int MAX_TRANSPORT_BYTES = 65536;

    public function __construct(
        public ?GameStartupMode $mode = null,
        public ?string $dataRoot = null,
        public ?GameStartLocation $start = null,
        public ?string $cinematicId = null,
        public ?ProjectBattleTest $battleTest = null,
    ) {
        if ($start !== null && $mode !== GameStartupMode::NEW_GAME) {
            throw new InvalidArgumentException('A launch start requires new-game mode.');
        }
        if ($cinematicId !== null && ($start === null
            || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $cinematicId) !== 1)) {
            throw new InvalidArgumentException('A cinematic launch requires a start and a stable cinematic ID.');
        }
        if ($battleTest !== null && ($mode !== null || $start !== null || $cinematicId !== null)) {
            throw new InvalidArgumentException('A battle launch cannot be combined with field or title intent.');
        }
    }

    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['mode', 'dataRoot', 'start', 'cinematicId', 'battleTest']) !== []) {
            throw new InvalidArgumentException('Unknown launch context option.');
        }
        foreach (['mode', 'dataRoot', 'cinematicId'] as $key) {
            if (array_key_exists($key, $data) && !is_string($data[$key])) {
                throw new InvalidArgumentException("Launch $key must be a string when supplied.");
            }
        }
        foreach (['start', 'battleTest'] as $key) {
            if (array_key_exists($key, $data) && !is_array($data[$key])) {
                throw new InvalidArgumentException("Launch $key must be an object when supplied.");
            }
        }
        $mode = isset($data['mode']) ? (GameStartupMode::tryFrom($data['mode'])
            ?? throw new InvalidArgumentException('Launch mode must be title or new-game.')) : null;
        return new self($mode, $data['dataRoot'] ?? null,
            isset($data['start']) ? GameStartLocation::fromArray($data['start']) : null,
            $data['cinematicId'] ?? null,
            isset($data['battleTest']) ? ProjectBattleTest::fromArray($data['battleTest']) : null);
    }

    /** Clear transport even on rejection, so it cannot leak to a child game. */
    public static function consumeEnvironment(): ?self
    {
        $json = getenv(self::ENVIRONMENT_VARIABLE);
        putenv(self::ENVIRONMENT_VARIABLE);
        unset($_ENV[self::ENVIRONMENT_VARIABLE], $_SERVER[self::ENVIRONMENT_VARIABLE]);
        if ($json === false) {
            return null;
        }
        if (strlen($json) > self::MAX_TRANSPORT_BYTES) {
            throw new InvalidArgumentException('Launch context exceeds 64 KiB.');
        }
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Launch context must be a JSON object.', previous: $error);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidArgumentException('Launch context must be a JSON object.');
        }
        $data = (array) $object;
        foreach (['start', 'battleTest'] as $key) {
            if (array_key_exists($key, $data) && !$data[$key] instanceof stdClass) {
                throw new InvalidArgumentException("Launch $key must be a JSON object.");
            }
        }
        return self::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /** Only the existing ArenaScene command may accompany battle intent. */
    public function validateOptions(array $options, ?GameStartupMode $otherMode): void
    {
        if ($otherMode !== null && ($this->mode !== null || $this->battleTest !== null)) {
            throw new InvalidArgumentException('Launch context cannot compete with another startup mode.');
        }
        if ($this->battleTest !== null && (isset($options[ArenaScene::SETUP_OPTION])
            || isset($options['arena_troop']) || (array_key_exists('starting_scene', $options)
                && $options['starting_scene'] !== ArenaScene::class))) {
            throw new InvalidArgumentException('Battle launch conflicts with custom scene or arena options.');
        }
        if ($this->mode !== null && array_key_exists('starting_scene', $options)) {
            throw new InvalidArgumentException('An explicit start mode cannot be combined with starting_scene.');
        }
    }
}
