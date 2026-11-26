<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicLibrary;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use InvalidArgumentException;

/** Project-backed validation before Game acquires native input or output. */
final class GameLaunchPreflight
{
    /** The ordinary compatibility pipeline owns checkpoint decoding and migrated game state. */
    public static function prepareCheckpoint(Game $game, GameLaunchContext $context): ?GameConfig
    {
        return $context->checkpoint === null ? null
            : $game->getSaveManager()->importIsolatedCheckpoint($context->checkpoint)->config;
    }

    public static function validate(Game $game, GameLaunchContext $context): ?CinematicDefinition
    {
        $assets = realpath($game->getProjectAssetRoot());
        if ($assets === false) {
            throw new InvalidArgumentException('The launch project has no real assets directory.');
        }
        if ($context->start !== null) {
            self::validateAssetDirectory($assets, $assets . '/Maps/' . $context->start->mapId);
            $scene = $game->sceneManager->findScene(GameScene::class);
            if (!$scene instanceof GameScene) {
                throw new InvalidArgumentException('The launch project has no field scene.');
            }
            $prepared = $scene->getMapManager()->prepareMap($context->start->mapId);
            $loader = GameLoader::getInstance($game);
            $initialConfig = $loader->loadNewGame($context->start);
            $context->start->validatePreparedMap($prepared, $initialConfig, $loader->getWorldAbilities());
        }
        if ($context->battleTest !== null) {
            $loader = $game->getGameLoader();
            $setup = $loader->createBattleTestSetup($context->battleTest, $loader->loadSystemData());
            $problems = $setup->getProblems($loader->actorStore, $loader->itemStore, assetRoot: $loader->assetRoot);
            if ($problems !== []) {
                throw new InvalidArgumentException(implode("\n", $problems));
            }
            $troop = $context->battleTest->troop;
            if ($troop !== null) {
                $found = false;
                foreach ($loader->loadTroopData() as $data) {
                    if (is_string($data['name'] ?? null) && strcasecmp($data['name'], $troop) === 0) {
                        $loader->createTroop($data);
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
        return (new CinematicLibrary($assets . '/Cutscenes/Cinematics', $game->getProjectScriptCommands()))->load($context->cinematicId);
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
