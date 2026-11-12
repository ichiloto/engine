<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicLibrary;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use InvalidArgumentException;

/** Project-backed validation before Game acquires native input or output. */
final class GameLaunchPreflight
{
    /** The ordinary compatibility pipeline owns checkpoint decoding and migrated game state. */
    public static function prepareCheckpoint(Game $game, GameLaunchContext $context): ?GameConfig
    {
        return $context->checkpoint === null ? null
            : SaveManager::getInstance($game)->importIsolatedCheckpoint($context->checkpoint)->config;
    }

    public static function validate(Game $game, GameLaunchContext $context): ?CinematicDefinition
    {
        $assets = realpath(getcwd() . '/assets');
        if ($assets === false) {
            throw new InvalidArgumentException('The launch project has no real assets directory.');
        }
        if ($context->start !== null) {
            self::validateAssetDirectory($assets, $assets . '/Maps/' . $context->start->mapId);
            $scene = $game->sceneManager->findScene(GameScene::class);
            if (!$scene instanceof GameScene) {
                throw new InvalidArgumentException('The launch project has no field scene.');
            }
            $prepared = MapManager::getInstance($game, $scene)->prepareMap($context->start->mapId);
            $initialConfig = GameLoader::getInstance($game)->loadNewGame($context->start);
            $context->start->validatePreparedMap($prepared, $initialConfig);
        }
        if ($context->battleTest !== null) {
            $actors = ConfigStore::get(ActorStore::class);
            $items = ConfigStore::get(ItemStore::class);
            if (!$actors instanceof ActorStore || !$items instanceof ItemStore) {
                throw new InvalidArgumentException('Battle launch requires the project actor and item stores.');
            }
            $setup = $context->battleTest->createSetup($actors);
            $problems = $setup->getProblems($actors, $items);
            if ($problems !== []) {
                throw new InvalidArgumentException(implode("\n", $problems));
            }
            $troop = $context->battleTest->troop;
            if ($troop !== null) {
                $found = false;
                foreach ((array) asset('Data/troops.php', true) as $data) {
                    if (is_array($data) && is_string($data['name'] ?? null) && strcasecmp($data['name'], $troop) === 0) {
                        Troop::fromArray($data);
                        $found = true;
                        break;
                    }
                }
                if (!$found) { throw new InvalidArgumentException('The launch battle troop does not exist.'); }
            }
            if ($setup->arena !== null) {
                $choices = BattlePresentationCatalog::load($assets)?->getArenaChoices() ?? [];
                if (!array_key_exists($setup->arena, $choices)) {
                    throw new InvalidArgumentException('The launch battle arena does not exist.');
                }
            }
        }
        if ($context->cinematicId === null) { return null; }
        self::validateAssetDirectory($assets, $assets . '/Cutscenes/Cinematics/' . $context->cinematicId);
        return (new CinematicLibrary())->load($context->cinematicId);
    }

    /** Launch IDs must never select source files outside the project's assets. */
    private static function validateAssetDirectory(string $assets, string $directory): void
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real) || !str_starts_with($real, $assets . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('The launch asset directory is missing or outside project assets.');
        }
        // Follow contained directory links once; cycles cannot hide escaping source files or loop validation.
        $pending = [$real];
        $visited = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (isset($visited[$current])) { continue; }
            $visited[$current] = true;
            foreach (new \FilesystemIterator($current, \FilesystemIterator::SKIP_DOTS) as $file) {
                $path = $file->getRealPath();
                if ($path === false || !str_starts_with($path, $assets . DIRECTORY_SEPARATOR)) {
                    throw new InvalidArgumentException('A launch source resolves outside project assets.');
                }
                if ($file->isDir()) { $pending[] = $path; }
            }
        }
    }
}
