<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Battle\BattleConclusion;
use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Battle\Events\BattleOutcome;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\PlayStatistic;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleSceneState;
use Ichiloto\Engine\Scenes\Battle\States\BattleVictoryState;
use Ichiloto\Engine\Scenes\Battle\States\BattleDefeatState;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;

final class StatisticsConclusionGame extends Game
{
  public function __construct()
  {
    $this->sceneManager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->sceneManager, $this);
    new ReflectionProperty(SceneManager::class, 'scenes')->setValue($this->sceneManager, new ItemList(SceneInterface::class));
  }
  public function __destruct() {}
}

/** Only results-window entry is suppressed; the conclusion and progress writes are production code. */
final class StatisticsConclusionScene extends BattleScene
{
  public int $transitions = 0;
  public function __construct(StatisticsConclusionGame $game)
  {
    $this->sceneManager = $game->sceneManager;
    $this->victoryState = new ReflectionClass(BattleVictoryState::class)->newInstanceWithoutConstructor();
    $this->defeatState = new ReflectionClass(BattleDefeatState::class)->newInstanceWithoutConstructor();
  }
  public function setState(BattleSceneState $state): void { $this->transitions++; }
}

beforeEach(function () {
  $this->audioInstance = new ReflectionProperty(\Ichiloto\Engine\Audio\AudioManager::class, 'instance')->getValue();
  new ReflectionProperty(\Ichiloto\Engine\Audio\AudioManager::class, 'instance')->setValue(null, null);
  $this->game = new StatisticsConclusionGame();
  $this->world = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($this->world, new GameState());
  new ReflectionProperty(GameScene::class, 'knowledge')->setValue($this->world,
    new KnowledgeProgressService(KnowledgeCatalog::empty()));
  new ReflectionProperty(AbstractScene::class, 'started')->setValue($this->world, true);
  $this->game->sceneManager->addScenes($this->world);
  $this->party = new Party();
  $this->party->addMember(new Character('Synthetic hero', 0, new Stats()));
  new ReflectionProperty(GameScene::class, 'party')->setValue($this->world, $this->party);
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => 'Synthetic foe', 'level' => 0, 'stats' => new Stats(currentHp: 0), 'imagePath' => '',
    'image' => ['o'], 'position' => new Vector2(), 'rewards' => new BattleRewards(0, 7, [])] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
  }
  $this->troop = new Troop('Synthetic troop', [$enemy]);
  $this->scene = new StatisticsConclusionScene($this->game);
  $this->conclusion = new BattleConclusion($this->game, $this->party, $this->troop);
});

afterEach(function () {
  new ReflectionProperty(\Ichiloto\Engine\Audio\AudioManager::class, 'instance')->setValue(null, $this->audioInstance);
});

it('counts a victory once even when enemies have no knowledge record and refuses repeated rewards or outcome replacement', function () {
  $gold = $this->party->accountBalance;
  $this->conclusion->concludeVictory($this->scene);
  $result = $this->scene->result;
  $this->conclusion->concludeVictory($this->scene);
  $this->conclusion->concludeDefeat($this->scene);
  $this->conclusion->concludeEscape($this->scene);
  expect($this->world->gameState->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(1)
    ->and($this->party->accountBalance - $gold)->toBe(7)
    ->and($this->scene->result)->toBe($result)->and($result->outcome())->toBe('victory')
    ->and($this->scene->transitions)->toBe(1)
    ->and($this->world->knowledge->progress->toArray())->toBe(['subjects' => []]);
  $next = new StatisticsConclusionScene($this->game);
  $this->conclusion->conclude($next, BattleOutcome::VICTORY);
  expect($this->world->gameState->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(2);
});

it('keeps escape defeat and detached battle tests out of campaign victories', function () {
  $this->conclusion->conclude($this->scene, BattleOutcome::DEFEAT);
  $this->conclusion->concludeVictory($this->scene);
  expect($this->world->gameState->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(0)
    ->and($this->scene->result->outcome())->toBe('defeat');
  $escape = new StatisticsConclusionScene($this->game);
  $this->conclusion->concludeEscape($escape);
  $this->conclusion->concludeVictory($escape);
  expect($escape->result->outcome())->toBe('escape')
    ->and($this->world->gameState->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(0);
  new ReflectionProperty(AbstractScene::class, 'started')->setValue($this->world, false);
  $this->conclusion->concludeVictory(new StatisticsConclusionScene($this->game));
  expect($this->world->gameState->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(0);
  new ReflectionProperty(AbstractScene::class, 'started')->setValue($this->world, true);
  $testParty = new Party();
  $testParty->addMember(new Character('Test-only hero', 0, new Stats()));
  (new BattleConclusion($this->game, $testParty, $this->troop))->concludeVictory(new StatisticsConclusionScene($this->game));
  expect($this->world->gameState->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(0);
});

it('records quest and knowledge outcomes only for the campaign that owns the battle party', function () {
  $knowledge = new KnowledgeProgressService(new KnowledgeCatalog([
    'recordTypes' => ['creature'],
    'subjects' => [['id' => 'creature.foe', 'recordType' => 'creature', 'displayName' => 'Synthetic foe',
      'quickCard' => 'A synthetic record.']],
    'enemyMappings' => ['Synthetic foe' => 'creature.foe'],
  ]));
  new ReflectionProperty(GameScene::class, 'knowledge')->setValue($this->world, $knowledge);
  $quests = new class extends \Ichiloto\Engine\Quests\QuestManager {
    public int $defeats = 0;
    public function __construct() {}
    public function recordDefeat(string $enemyName, int $count = 1): void { $this->defeats += $count; }
  };
  new ReflectionProperty(GameScene::class, 'questManager')->setValue($this->world, $quests);
  $this->conclusion->concludeVictory($this->scene);
  expect($quests->defeats)->toBe(1)
    ->and($knowledge->progress->outcomeCount('creature.foe', 'defeated'))->toBe(1);
  $testParty = new Party();
  $testParty->addMember(new Character('Test-only hero', 0, new Stats()));
  (new BattleConclusion($this->game, $testParty, $this->troop))->concludeVictory(new StatisticsConclusionScene($this->game));
  expect($quests->defeats)->toBe(1)
    ->and($knowledge->progress->outcomeCount('creature.foe', 'defeated'))->toBe(1)
    ->and($this->world->gameState->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(1);
});
