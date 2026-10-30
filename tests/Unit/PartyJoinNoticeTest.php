<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\RecruitmentLevelPolicy;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\NoticeContent;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\ActorStore;

final class PartyJoinNoticeGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

beforeEach(function () {
  $this->savedModals = new ReflectionProperty(ModalManager::class, 'instance')->getValue();
  $this->savedConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
  $this->game = new PartyJoinNoticeGame();
  $this->party = new Party();
  $this->scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(GameScene::class, 'party')->setValue($this->scene, $this->party);
  $sceneManager = makeBareScene(\Ichiloto\Engine\Scenes\SceneManager::class);
  new ReflectionProperty($sceneManager, 'game')->setValue($sceneManager, $this->game);
  new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($this->scene, $sceneManager);
  $this->modals = ModalManager::getInstance($this->game);
  $this->begin = fn() => new ReflectionMethod(GameScene::class, 'beginRecruitmentNotices')->invoke($this->scene);
  $this->flush = fn() => new ReflectionMethod(GameScene::class, 'queueRecruitmentNotices')->invoke($this->scene);
  $this->release = fn() => new ReflectionMethod(GameScene::class, 'releaseRecruitmentNotices')->invoke($this->scene);
  $this->queued = fn(): array => new ReflectionProperty(ModalManager::class, 'pendingAlerts')->getValue($this->modals);
});

afterEach(function () {
  ($this->release)();
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->savedModals);
  $this->savedConfig === null ? ConfigStore::remove(ProjectConfig::class) : ConfigStore::put(ProjectConfig::class, $this->savedConfig);
});

it('announces every direct real join in producer order without saving notice state', function () {
  $starter = new Character('Starter', 1, new Stats(), actorId: 'starter');
  $this->party->addMember($starter);
  ($this->begin)(); ($this->flush)();
  expect(($this->queued)())->toBe([]);
  $names = [];
  for ($i = 1; $i <= 8; $i++) {
    $member = new Character('Companion ' . $i, 1, new Stats(), actorId: 'actor.' . $i);
    $member->stats->currentMp = 3;
    $this->party->addMember($member); $names[] = $member->name;
  }
  $saved = serialize($this->party);
  expect(unserialize($saved)->getPendingJoin())->toBeNull();
  ($this->flush)(); ($this->flush)();
  $entries = ($this->queued)();
  expect(count($entries))->toBe(8)->and($this->party->getPendingJoin())->toBeNull()
    ->and(serialize($this->party))->toBe($saved);
  foreach ($entries as $i => $entry) {
    expect($entry['notice']->message)->toBe($names[$i] . ' joined the party!')
      ->and($entry['notice']->title)->toBe('')->and($entry['notice']->actorId)->toBe('actor.' . ($i + 1))
      ->and($entry['notice']->sound)->toBe(SystemSound::PARTY_JOIN)
      ->and($this->party->members->toArray()[$i + 1]->stats->currentMp)->toBe(3);
  }
});

it('announces average-level actor recruitment once and retains the actual actor portrait identity', function () {
  $this->party->addMember(new Character('Starter', 1, new Stats(), actorId: 'starter'));
  ($this->begin)();
  $definition = (new ActorStore(__DIR__ . '/../Fixtures/Actors'))->require('actor.hero', 'recruitment notice fixture');
  expect($this->party->recruitActor($definition, RecruitmentLevelPolicy::PARTY_AVERAGE))->toBeTrue();
  ($this->flush)();
  expect($this->party->recruitActor($definition, RecruitmentLevelPolicy::PARTY_AVERAGE))->toBeFalse();
  ($this->flush)();
  expect(count(($this->queued)()))->toBe(1)
    ->and(($this->queued)()[0]['notice']->actorId)->toBe('actor.hero');
});

it('clears only its scene notices on release and excludes hydration joins', function () {
  $foreignOwner = new stdClass();
  $foreignNotice = new NoticeContent('Other owner', 'Preserve this.');
  $this->modals->queueAlert('Alert', 'Preserved');
  $this->modals->queueNotice($foreignNotice, $foreignOwner);
  ($this->begin)();
  $this->party->addMember(new Character('First', 1, new Stats()));
  ($this->flush)();
  $this->party->addMember(new Character('Interrupted', 1, new Stats()));
  ($this->release)();
  expect(count(($this->queued)()))->toBe(2)->and(($this->queued)()[1]['notice'])->toBe($foreignNotice)
    ->and($this->party->getPendingJoin())->toBeNull();
  ($this->begin)(); ($this->flush)();
  expect(count(($this->queued)()))->toBe(2);
});

it('localizes the shared notice without changing character or resource identity', function () {
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['messages' => ['party' => ['member_joined' => '%1 joined us.']]]));
  ($this->begin)(); $this->party->addMember(new Character('Same Name', 1, new Stats(), actorId: 'distinct-id'));
  ($this->flush)();
  expect(($this->queued)()[0]['notice']->message)->toBe('Same Name joined us.')
    ->and(($this->queued)()[0]['notice']->actorId)->toBe('distinct-id');
});

it('retains unacknowledged joins and refuses acknowledgement in a different order', function () {
  $first = new Character('First', 1, new Stats()); $second = new Character('Second', 1, new Stats());
  $this->party->addMember($first); $this->party->addMember($second);
  expect(fn() => $this->party->acknowledgeJoin($second))->toThrow(LogicException::class)
    ->and($this->party->getPendingJoin())->toBe($first);
  $this->party->acknowledgeJoin($first);
  expect($this->party->getPendingJoin())->toBe($second);
});

final class PartyJoinNoticeDeliveryProbe extends ModalManager
{
  public array $delivered = [];
  public function __construct(Game $game)
  {
    $this->game = $game;
    $this->modals = new \Assegai\Collections\Stack(\Ichiloto\Engine\UI\Interfaces\ModalInterface::class);
  }
  protected function showPendingNotice(NoticeContent $notice): void { $this->delivered[] = $notice; }
}

it('holds following field commands and completion until all real joins are acknowledged', function () {
  $previousActors = ConfigStore::has(ActorStore::class) ? ConfigStore::get(ActorStore::class) : null;
  ConfigStore::put(ActorStore::class, new ActorStore(__DIR__ . '/../Fixtures/Actors'));
  try {
    $state = new \Ichiloto\Engine\Core\GameState();
    new ReflectionProperty(GameScene::class, 'gameState')->setValue($this->scene, $state);
    $this->modals = new PartyJoinNoticeDeliveryProbe($this->game);
    new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->modals);
    ($this->begin)();
    $interpreter = new \Ichiloto\Engine\Events\Interpreter\EventInterpreter($this->scene,
      scriptCommands: \Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog::createEngineCatalog());
    $session = $interpreter->run([
      ['type' => 'recruit_actor', 'actor' => 'actor.hero'],
      ['type' => 'set_switch', 'name' => 'after_join', 'value' => true],
    ]);
    expect($state->getSwitch('after_join'))->toBeFalse()
      ->and($session->status)->not->toBe(\Ichiloto\Engine\Events\Interpreter\EventExecutionStatus::COMPLETED);
    $this->party->addMember(new Character('Another', 1, new Stats()));
    $interpreter->update(0);
    $this->modals->processPendingAlerts(); $interpreter->update(0);
    expect($state->getSwitch('after_join'))->toBeFalse()->and(count($this->modals->delivered))->toBe(1);
    $this->modals->processPendingAlerts(); $interpreter->update(0);
    expect($state->getSwitch('after_join'))->toBeTrue()->and(count($this->modals->delivered))->toBe(2)
      ->and($session->status)->toBe(\Ichiloto\Engine\Events\Interpreter\EventExecutionStatus::COMPLETED);
  } finally {
    $previousActors === null ? ConfigStore::remove(ActorStore::class) : ConfigStore::put(ActorStore::class, $previousActors);
  }
});
