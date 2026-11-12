<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Campaign\CampaignSession;
use Ichiloto\Engine\Core\Campaign\CampaignNotices;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\ProjectState\ProjectStateParticipantInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Tests\Support\PassiveGame;

function createCampaignSessionConfig(Party $party, array $state = [], array $project = []): GameConfig
{
    return new GameConfig('synthetic.map', $party, new Vector2(1, 1), new Rect(0, 0, 1, 1),
        MovementHeading::SOUTH, gameState: $state, projectState: $project);
}

function registerCampaignSessionState(PassiveGame $game): void
{
    $game->registerProjectStateParticipant(new class implements ProjectStateParticipantInterface {
        public string $namespace { get => 'fixture/campaign'; }
        public int $version { get => 1; }
        public function migrateState(array $data, int $fromVersion): array { return $data; }
        public function validateState(array $data): void {}
    });
}

it('restores independent campaign facts from each project without field or native bootstrap', function () {
    $oldDirectory = getcwd();
    $sessions = [];
    $priorQuest = QuestManager::current();
    $priorAchievement = AchievementManager::current();
    try {
        chdir(createTestDirectory('unrelated-campaign-project-'));
        foreach (['one', 'two'] as $id) {
            $root = createTestDirectory('owned-campaign-' . $id . '-');
            mkdir($root . '/Data');
            file_put_contents($root . '/Data/quests.php', '<?php return ' . var_export([
                ['id' => 'quest.' . $id, 'name' => $id, 'objectives' => [['type' => 'flag', 'target' => 'later']]],
            ], true) . ';');
            file_put_contents($root . '/Data/achievements.php', '<?php return ' . var_export([
                ['id' => 'achievement.' . $id, 'name' => $id, 'points' => 9],
            ], true) . ';');
            file_put_contents($root . '/Data/knowledge.php', '<?php return ' . var_export([
                'recordTypes' => ['creature'],
                'subjects' => [['id' => 'creature.' . $id, 'recordType' => 'creature', 'displayName' => $id]],
                'enemyMappings' => ['Synthetic enemy ' . $id => 'creature.' . $id],
            ], true) . ';');
            file_put_contents($root . '/Data/income.php', '<?php return ' . var_export([
                'schedules' => [['id' => 'synthetic.pay', 'name' => 'Synthetic pay', 'cadence' => 'event', 'amount' => 17]],
            ], true) . ';');
            $target = array_values(\Ichiloto\Engine\Scenes\Game\States\MainMenuState::TUTORIAL_COMMAND_TARGETS)[0];
            file_put_contents($root . '/Data/tutorials.php', '<?php return ' . var_export([[
                'id' => 'guide.' . $id, 'title' => $id, 'category' => 'Field', 'start' => 'script', 'replay' => 'read',
                'steps' => [['target' => ['screen' => 'main-menu', 'view' => 'commands', 'id' => $target],
                    'say' => 'Open the selected menu.', 'action' => 'confirm', 'until' => ['outcome' => 'menu.command-opened', 'subject' => $target]]],
            ]], true) . ';');
            $game = new PassiveGame();
            registerCampaignSessionState($game);
            $party = new Party();
            $party->addMember(new Character('Hydrated', 1, new Stats()));
            $config = createCampaignSessionConfig($party, ['variables' => ['project' => $id]],
                ['fixture/campaign' => ['version' => 1, 'data' => ['value' => $id]]]);
            $data = $config->getSaveCompatibilityData();
            $data['gameState']['tutorials'] = ['guide.' . $id => 'pending'];
            $data['questLog'] = ['active' => ['quest.' . $id => [0]]];
            $data['achievements'] = ['achievement.' . $id => 42];
            $data['bestiary'] = ['seen' => ['Synthetic enemy ' . $id => 3], 'defeated' => ['Synthetic enemy ' . $id => 2]];
            $data['income'] = ['synthetic.pay' => ['progress' => 2.5, 'held' => 11, 'adjustment' => -3]];
            $config->applySaveCompatibilityData($data);
            // Deserialization follows the same restoration entry point as new-game configs.
            $config = unserialize(serialize($config));
            $session = CampaignSession::restore($game, $config, $root);
            $sessions[] = $session;
            expect($session->gameState->getVariable('project'))->toBe($id)
                ->and($session->party)->toBe($config->party)
                ->and($session->party->getPendingJoin())->toBeNull()
                ->and(array_keys($session->questManager->quests))->toBe(['quest.' . $id])
                ->and($session->questManager->log->isActive('quest.' . $id))->toBeTrue()
                ->and($session->achievementManager->isUnlocked('achievement.' . $id))->toBeTrue()
                ->and($session->bestiary->timesSeen('Synthetic enemy ' . $id))->toBe(3)
                ->and($session->bestiary->timesDefeated('Synthetic enemy ' . $id))->toBe(2)
                ->and($session->bestiary->knowledge)->toBe($session->knowledge)
                ->and($session->income->ledger->toArray())->toBe($data['income'])
                ->and($game->getProjectStateRegistry()->getState('fixture/campaign'))->toBe(['value' => $id]);
            expect($session->knowledge->catalog->subjectForEnemy('Synthetic enemy ' . $id)?->id)->toBe('creature.' . $id);
            $balance = $session->party->accountBalance;
            expect($session->income->payday('synthetic.pay'))->toBe(14)
                ->and($session->party->accountBalance)->toBe($balance + 14)
                ->and($session->notices->awaitRecruitmentNotices())->toBeTrue();
            expect($session->tutorialDirector)->toBeInstanceOf(\Ichiloto\Engine\Progression\TutorialDirector::class)
                ->and($session->gameState->getTutorialPhase('guide.' . $id))->toBe(\Ichiloto\Engine\Progression\TutorialPhase::PENDING);
            $modern = $config->getSaveCompatibilityData();
            $modern['knowledge'] = $session->knowledge->progress->toArray();
            $modern['bestiary'] = [];
            $modernConfig = unserialize(serialize($config));
            $modernConfig->applySaveCompatibilityData($modern);
            $modernGame = new PassiveGame();
            registerCampaignSessionState($modernGame);
            $modernSession = CampaignSession::restore($modernGame, $modernConfig, $root);
            try {
                expect($modernSession->bestiary->timesSeen('Synthetic enemy ' . $id))->toBe(3)
                    ->and($modernSession->bestiary->timesDefeated('Synthetic enemy ' . $id))->toBe(2)
                    ->and($modernSession->knowledge->progress->toArray())->toBe($modern['knowledge']);
            } finally { $modernSession->release(); }
            $session->setCurrentMapId('synthetic.destination');
            expect($session->currentMapId)->toBe('synthetic.destination');
        }
        $sessions[0]->release();
        expect(QuestManager::current())->toBe($sessions[1]->questManager)
            ->and(AchievementManager::current())->toBe($sessions[1]->achievementManager);
    } finally {
        foreach (array_reverse($sessions) as $session) { $session->release(); }
        chdir($oldDirectory);
    }
    expect(QuestManager::current())->toBe($priorQuest)->and(AchievementManager::current())->toBe($priorAchievement);
});

it('rolls back failed restoration and temporary state while committed campaigns retain project state', function () {
    $game = new PassiveGame();
    registerCampaignSessionState($game);
    $registry = $game->getProjectStateRegistry();
    $root = createTestDirectory('campaign-rollback-');
    mkdir($root . '/Data');
    $config = createCampaignSessionConfig(new Party(), project: [
        'fixture/campaign' => ['version' => 1, 'data' => ['value' => 'restored']],
    ]);
    $priorQuest = QuestManager::current();
    $priorAchievement = AchievementManager::current();
    file_put_contents($root . '/Data/knowledge.php', '<?php return "invalid";');
    expect(fn() => CampaignSession::restore($game, $config, $root))->toThrow(InvalidArgumentException::class)
        ->and((new ReflectionProperty($registry, 'started'))->getValue($registry))->toBeFalse()
        ->and(QuestManager::current())->toBe($priorQuest)
        ->and(AchievementManager::current())->toBe($priorAchievement);
    unlink($root . '/Data/knowledge.php');
    $temporary = CampaignSession::restore($game, $config, $root);
    expect($registry->getState('fixture/campaign'))->toBe(['value' => 'restored']);
    $temporary->release();
    $temporary->release();
    expect((new ReflectionProperty($registry, 'started'))->getValue($registry))->toBeFalse()->and($temporary->gameState->onChange)->toBeNull();
    $live = CampaignSession::restore($game, $config, $root);
    $live->commit();
    $live->release();
    expect($registry->getState('fixture/campaign'))->toBe(['value' => 'restored']);
});

it('releases only its own recruitment queue when another session uses the same party', function () {
    $game = new PassiveGame();
    $party = new Party();
    $a = new CampaignNotices($game, $party);
    $party->addMember(new Character('First', 1, new Stats()));
    $a->queueRecruitmentNotices();
    $b = new CampaignNotices($game, $party);
    $party->addMember(new Character('Second', 1, new Stats()));
    $a->release();
    expect($party->getPendingJoin()?->name)->toBe('Second');
    expect($b->awaitRecruitmentNotices())->toBeTrue();
    $b->release();
    expect($game->getModalManager()->hasPendingNotices($a))->toBeFalse()
        ->and($game->getModalManager()->hasPendingNotices($b))->toBeFalse();
});


it('keeps restored campaign facts without retaining a field host through presentation callbacks', function () {
    $game = new PassiveGame();
    $scene = new class($game) extends \Ichiloto\Engine\Scenes\Game\GameScene {
        public function __construct(private \Ichiloto\Engine\Core\Game $owner) {}
        public function getGame(): \Ichiloto\Engine\Core\Game { return $this->owner; }
        protected function configureCampaign(GameConfig $config): void {}
    };
    $scene->configure(createCampaignSessionConfig(new Party()));
    $campaign = $scene->campaign;
    $weak = WeakReference::create($scene);
    unset($scene);
    gc_collect_cycles();
    try { expect($weak->get())->toBeNull()->and($campaign->isReleased)->toBeFalse(); }
    finally { $campaign->release(); }
});
