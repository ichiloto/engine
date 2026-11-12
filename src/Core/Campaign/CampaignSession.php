<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Campaign;

use Closure;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\ProjectState\ProjectStateScope;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Income\IncomeCatalog;
use Ichiloto\Engine\Income\IncomeLedger;
use Ichiloto\Engine\Income\IncomeService;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Progress\Bestiary;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgress;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Progression\TutorialCatalog;
use Ichiloto\Engine\Progression\TutorialDirector;
use Ichiloto\Engine\Progression\TutorialProjectReferences;
use Ichiloto\Engine\Progression\TutorialStartContext;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Throwable;

/** Restores campaign facts without creating a field, player, interpreter, UI or native window. */
final class CampaignSession implements CampaignContextInterface
{
    public readonly GameState $gameState;
    public readonly Party $party;
    public private(set) string $currentMapId;
    public private(set) ?QuestManager $questManager = null;
    public private(set) ?AchievementManager $achievementManager = null;
    public private(set) ?TutorialDirector $tutorialDirector = null;
    public private(set) IncomeService $income;
    public private(set) KnowledgeProgressService $knowledge;
    public private(set) Bestiary $bestiary;
    public private(set) ?CampaignNotices $notices = null;
    public private(set) bool $isReleased = false;
    private ?Closure $worldObserver = null;
    private ?Closure $worldListener = null;
    private ?Closure $summonListener = null;
    private ?Closure $previousSummonListener = null;

    private function __construct(
        public readonly Game $game,
        public readonly GameConfig $config,
        public readonly string $assetRoot,
        private readonly ProjectStateScope $projectState,
    ) {
        $this->gameState = GameState::fromArray($config->gameState);
        $this->party = $config->party;
        $this->currentMapId = $config->mapId;
    }

    private function __clone() {}

    /** The supplied config owns its party; previews load an independent config through the save/new-game loader. */
    public static function restore(Game $game, GameConfig $config, string $assetRoot, ?Closure $incomeNotice = null): self
    {
        $scope = $game->getProjectStateRegistry()->acquireSnapshot($config->projectState);
        $session = null;
        try {
            $session = new self($game, $config, $assetRoot, $scope);
            $session->restoreFacts($incomeNotice);
            return $session;
        } catch (Throwable $error) {
            if ($session === null) { $scope->release(); }
            else { $session->release(); }
            throw $error;
        }
    }

    private function restoreFacts(?Closure $incomeNotice): void
    {
        $tutorials = TutorialCatalog::fromProject($this->assetRoot);
        if ($tutorials->definitions !== []) {
            (new TutorialProjectReferences($this->assetRoot))->validateCatalog($tutorials);
            $this->tutorialDirector = new TutorialDirector($tutorials, $this->gameState, $this->game->getTutorialStartConditions());
        }
        $this->party->assertSummonAssignments((new SummonCutsceneLibrary(
            assetPath: $this->assetRoot . '/Cutscenes/Summons', assetRoot: $this->assetRoot,
        ))->load());
        $this->questManager = new QuestManager($this->game, $this, $this->assetRoot);
        $this->questManager->hydrate($this->config->questLog);
        $this->achievementManager = new AchievementManager($this->game, $this, $this->assetRoot, $this->questManager->log);
        $this->achievementManager->hydrate($this->config->achievements);
        $this->knowledge = new KnowledgeProgressService(
            KnowledgeCatalog::fromProject($this->assetRoot), KnowledgeProgress::fromArray($this->config->knowledge),
        );
        $this->bestiary = $this->config->bestiary === [] ? new Bestiary($this->knowledge)
            : Bestiary::fromArray($this->config->bestiary, $this->knowledge);
        $this->notices = new CampaignNotices($this->game, $this->party);
        $this->income = new IncomeService(
            IncomeCatalog::fromProject($this->assetRoot . '/' . IncomeCatalog::FILE),
            IncomeLedger::fromArray($this->config->income), $this->gameState, $this->party,
            $incomeNotice ?? $this->notices->queueNotice(...),
        );
        $owner = \WeakReference::create($this);
        $this->worldListener = static function (string $kind, string $name) use ($owner): void {
            $owner->get()?->handleWorldChange($kind, $name);
        };
        $this->gameState->onChange = $this->worldListener;
        $this->observeSummonAssignments();
        if ($this->tutorialDirector?->canOfferContext(TutorialStartContext::SUMMONS_LIBRARY)) {
            $this->previousSummonListener = $this->party->getSummonAssignmentListener();
            $this->summonListener = static function (Party $party) use ($owner): void { $owner->get()?->observeSummonAssignments(); };
            $this->party->setSummonAssignmentListener($this->summonListener);
        }
    }

    public function setCurrentMapId(string $mapId): void { $this->currentMapId = $mapId; }

    /** Field presentation subscribes after facts are restored; capture hosts may leave this unset. */
    public function observeWorldChanges(?Closure $observer): void { $this->worldObserver = $observer; }

    private function handleWorldChange(string $kind, string $name): void
    {
        if ($this->isReleased) { return; }
        if ($kind === 'event' || ($kind === 'switch' && $this->gameState->getSwitch($name))) {
            $this->questManager?->recordFlag($name);
        }
        $this->achievementManager?->evaluateConditionalAchievements();
        $this->worldObserver?->__invoke($kind, $name);
    }

    private function observeSummonAssignments(): void
    {
        if ($this->isReleased) { return; }
        $this->tutorialDirector?->observeWorldTutorials($this->party, $this->questManager?->log, TutorialStartContext::SUMMONS_LIBRARY);
    }

    /** Normal play retains the installed project state after successful field setup. */
    public function commit(): void { $this->projectState->commit(); }

    /** Temporary hosts restore the previous project snapshot; committed sessions release their listeners and notices. */
    public function release(): void
    {
        if ($this->isReleased) { return; }
        $this->isReleased = true;
        $this->worldObserver = null;
        if ($this->gameState->onChange === $this->worldListener) { $this->gameState->onChange = null; }
        if ($this->summonListener !== null && $this->party->getSummonAssignmentListener() === $this->summonListener) {
            $this->party->setSummonAssignmentListener($this->previousSummonListener);
        }
        $failure = null;
        foreach ([
            fn() => $this->notices?->release(),
            fn() => $this->achievementManager?->release(),
            fn() => $this->questManager?->release(),
            fn() => $this->projectState->release(),
        ] as $cleanup) {
            try { $cleanup(); } catch (Throwable $error) { $failure ??= $error; }
        }
        if ($failure !== null) { throw $failure; }
    }
}
