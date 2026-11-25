<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Audio\FieldMusicCatalog;
use Ichiloto\Engine\Battle\Entry\BattleEntryRuleCatalog;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameStartLocation;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\SystemData;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Field\FieldActionRegistry;
use Ichiloto\Engine\Field\DashSettings;
use Ichiloto\Engine\Field\PlayerSpriteSet;
use Ichiloto\Engine\Field\PlayerPresentationConfig;
use Ichiloto\Engine\Progression\ProgressionSettings;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use RuntimeException;

/**
 * Class GameLoader. Loads a game.
 *
 * @package Ichiloto\Engine\Scenes\Game
 */
class GameLoader
{
  public readonly string $assetRoot;
  private ?FieldMusicCatalog $fieldMusicCatalog = null;
  private ?FieldActionRegistry $fieldActions = null;
  private ?BattleEntryRuleCatalog $battleEntryRules = null;

  /** The Game owns this loader and the project stores supplied at its bootstrap. */
  public function __construct(
    public readonly Game $game,
    public readonly ActorStore $actorStore,
    public readonly ItemStore $itemStore,
    string $assetRoot,
    private ?WorldAbilityCatalog $worldAbilities = null,
    private readonly ?EnemyStore $enemyStore = null,
    private ?ScriptCommandCatalog $scriptCommands = null,
    private ?DashSettings $dashSettings = null,
    private ?ProgressionSettings $progressionSettings = null,
  ) {
    $root = realpath($assetRoot);
    if ($root === false || !is_dir($root)) {
      throw new \InvalidArgumentException('Game loading requires an existing project asset root.');
    }
    $this->assetRoot = $root;
  }

  public function getFieldActions(): FieldActionRegistry
  {
    return $this->fieldActions ??= FieldActionRegistry::fromProject($this->assetRoot);
  }

  public function getFieldMusicCatalog(): FieldMusicCatalog
  {
    return $this->fieldMusicCatalog ??= FieldMusicCatalog::fromProject($this->assetRoot . '/Data/field-music.php');
  }

  public function getWorldAbilities(): WorldAbilityCatalog
  {
    return $this->worldAbilities ??= WorldAbilityCatalog::fromProject($this->assetRoot);
  }

  public function getDashSettings(): DashSettings
  {
    return $this->dashSettings ??= DashSettings::fromSystemData($this->loadSystemData());
  }

  public function getProgressionSettings(): ProgressionSettings
  {
    return $this->progressionSettings ??= ProgressionSettings::fromSystemData($this->loadSystemData());
  }

  /** Existing callers resolve the loader owned by their Game. */
  public static function getInstance(Game $game): self
  {
    return $game->getGameLoader();
  }

  /** @return array<string, mixed> */
  public function loadSystemData(): array
  {
    $filename = $this->assetRoot . '/Data/system.php';
    if (!is_file($filename)) { throw new RuntimeException('System data not found: ' . $filename); }
    $systemData = require $filename;
    if (!is_array($systemData)) {
      throw new RuntimeException('System data is not an array.');
    }
    return $systemData;
  }

  public function createBattleTestSetup(ProjectBattleTest $preset, array $systemData): BattleTestSetup
  {
    $battleSize = SystemData::getBattlePartySize($systemData['battle'] ?? null);
    return $preset->createSetup($this->actorStore, $systemData['startingParty'] ?? [], $battleSize,
      assetRoot: $this->assetRoot, items: $this->itemStore);
  }

  /** @return list<array<string, mixed>> */
  public function loadTroopData(): array
  {
    $source = $this->assetRoot . '/Data/troops.php';
    if (!is_file($source)) {
      throw new RuntimeException('Asset file not found: ' . $source);
    }
    return array_values(array_filter((array) (require $source), is_array(...)));
  }

  public function getEnemyStore(): EnemyStore
  {
    return $this->enemyStore ?? throw new \LogicException('Battle Test requires its project enemy store.');
  }

  public function getScriptCommands(): ScriptCommandCatalog
  {
    return $this->scriptCommands ??= ScriptCommandRegistry::readProjectCatalog($this->assetRoot);
  }

  public function getBattleEntryRules(): BattleEntryRuleCatalog
  {
    return $this->battleEntryRules ??= BattleEntryRuleCatalog::fromProject(
      $this->assetRoot . '/Data/battle-entry-rules.php', $this->actorStore);
  }

  public function createTroop(array $data): Troop
  {
    return Troop::fromArray($data, enemiesStore: $this->getEnemyStore(), scriptCommands: $this->getScriptCommands());
  }

  /**
   * Loads a new game.
   *
   * @return GameConfig The game configuration.
   * @throws RequiredFieldException Thrown when a required field is missing.
   */
  public function loadNewGame(?GameStartLocation $start = null): GameConfig
  {
    $systemData = SystemData::fromArray($this->loadSystemData());
    $party = new Party();
    $party->inventory->bindItemStore($this->itemStore);
    $party->setBattleSize($systemData->battle->partySize);

    foreach ($systemData->startingParty as $member) {
      try {
        $actor = $this->actorStore->requireStartingPartyActor(strval($member));
      } catch (RuntimeException $failure) {
        throw new RuntimeException(sprintf(
          'assets/Data/system.php: startingParty actor "%s" cannot be resolved. Run ichiloto validate --migrate-actor-ids to repair actor IDs and references.',
          strval($member),
        ), previous: $failure);
      }
      $party->addMember($actor->createCharacter(assetRoot: $this->assetRoot, items: $this->itemStore));
    }
    if ($systemData->currency->amount) {
      $party->accountBalance = $systemData->currency->amount;
    }
    $party->inventory->addItems(...$this->itemStore->load($systemData->startingInventory));

    $playerPosition = $start === null
      ? new Vector2($systemData->startingPositions->player->spawnPoint->x, $systemData->startingPositions->player->spawnPoint->y)
      : new Vector2($start->x, $start->y);
    $playerSprites = $this->loadPlayerSprites();
    $configuredSpawnSprite = $systemData->startingPositions->player->spawnSprite
      ?? throw new RequiredFieldException('startingPositions.player.spawnSprite');
    $spawnSprite = $playerSprites->resolveSprite($configuredSpawnSprite);

    return new GameConfig(
      mapId: $start?->mapId ?? $systemData->startingPositions->player->destinationMap,
      party: $party,
      playerPosition: $playerPosition,
      playerShape: new Rect(0, 0, 1, 1),
      playerHeading: $playerSprites->resolveHeading($configuredSpawnSprite),
      playerStats: [],
      events: [],
      playerSprite: $spawnSprite,
      playerSprites: $playerSprites->toArray(),
      playTimeSeconds: 0,
    );
  }

  /**
   * Loads a saved game.
   *
   * @param string $saveFilePath The path to the saved game file.
   * @return GameConfig The game configuration.
   */
  public function loadSavedGame(string $saveFilePath): GameConfig
  {
    $savedGame = $this->game->getSaveManager()->loadSaveFile($saveFilePath);

    $savedGame->config->party->inventory->bindItemStore($this->itemStore);
    return $savedGame->config;
  }

  /**
   * Loads the configured player directional sprite set.
   *
   * @return PlayerSpriteSet The normalized sprite set.
   */
  protected function loadPlayerSprites(): PlayerSpriteSet
  {
    return PlayerPresentationConfig::loadFromProject($this->assetRoot)->terminal;
  }
}
