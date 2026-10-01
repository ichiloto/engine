<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ichiloto\Engine\Audio\AudioMutePreflight;
use Ichiloto\Engine\Battle\Entry\BattleEntryRuleCatalog;
use Ichiloto\Engine\Battle\Entry\BattleEntryRuleRunner;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\ActorStore;

// Only a throwaway project is used; verify silence before constructing the real Game.
ConfigStore::put(PlayerSettings::class, new PlayerSettings(getcwd()));
AudioMutePreflight::assertMuted(new ProjectConfig());
$game = new Game('Battle-entry startup fixture');
$catalog = ConfigStore::get(BattleEntryRuleCatalog::class);
assert($catalog instanceof BattleEntryRuleCatalog);
$store = ConfigStore::get(ActorStore::class);
assert($store instanceof ActorStore);
$hero = $store->require('actor.hero', 'startup fixture')->createCharacter();
$party = new Party();
$party->addMember($hero);
(new BattleEntryRuleRunner($catalog))->apply(new BattleConfig($party, new Troop('Encounter')), new GameState());
file_put_contents('result.json', json_encode([
  'rules' => array_map(static fn($rule) => $rule->id, $catalog->rules()),
  'diagnostics' => $catalog->getDiagnostics(),
  'speed' => $hero->getStatStage('speed'),
  'grace' => $hero->getStatStage('grace'),
], JSON_THROW_ON_ERROR));
// Do not start a game loop, renderer or audio process.
$game->quit();
