<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Events;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\Interpreter\EventExecutionHostInterface;
use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/**
 * Runs a troop's event pages in its battle.
 *
 * The battle engine reports each check (the start of a turn and the end of
 * each battler's turn). Pages whose conditions hold and whose span allows it
 * are queued in page order; the battle pauses while they run through the
 * shared event interpreter, one page at a time, and resumes or ends with the
 * outcome a page asked for.
 *
 * @package Ichiloto\Engine\Battle\Events
 */
final class BattleEventRunner implements EventExecutionHostInterface, BattleEventControlInterface
{
  /** @var array<int, true> Pages that ran in this battle, by page index. */
  private array $ranInBattle = [];
  /** @var array<int, int> The turn each page last ran in, by page index. */
  private array $ranInTurn = [];
  /** @var list<int> Pages waiting to run, by page index. */
  private array $queue = [];
  private ?string $lastCheck = null;
  private ?EventInterpreter $interpreter = null;
  private int $turn = 0;
  public private(set) ?BattleOutcome $requestedOutcome = null;

  /** Whether pages are queued or running. */
  public bool $isRunning {
    get => $this->queue !== [] || ($this->interpreter?->hasActiveSession() ?? false);
  }

  /**
   * @param Troop $troop The troop whose pages run.
   * @param \Closure(): list<Character> $partyBattlers The party members fighting now.
   * @param GameScene $gameScene The field scene that owns the world state the pages read and change.
   * @param Party|null $party The battle's party.
   * @param EventPresentationInterface $presentation Shows page dialogue over the battle.
   * @param \Closure(\Ichiloto\Engine\Entities\Enemies\Enemy): void|null $onConcede Redraws the battle after an enemy concedes.
   */
  public function __construct(
    private readonly Troop $troop,
    private readonly \Closure $partyBattlers,
    private readonly GameScene $gameScene,
    private readonly ?Party $party,
    private readonly EventPresentationInterface $presentation,
    private readonly ?\Closure $onConcede = null,
  )
  {
  }

  /** Whether the troop has any page to check. */
  public function hasPages(): bool
  {
    return $this->troop->eventPages !== [];
  }

  /**
   * Queues the pages due at one battle check. A check is evaluated once, so
   * an engine state that runs again after the pages does not repeat it.
   *
   * @param int $turn The battle turn, 1 for the opening turn.
   * @param string $check The check's identity, such as "turn:2" or "action:7".
   * @return bool True when pages were queued and the battle must pause for them.
   */
  public function queueDuePages(int $turn, string $check): bool
  {
    if (! $this->hasPages() || $this->requestedOutcome !== null || $check === $this->lastCheck) {
      return false;
    }

    $this->lastCheck = $check;
    $this->turn = $turn;
    $state = $this->createCheck();

    foreach ($this->troop->eventPages as $index => $page) {
      if (! in_array($index, $this->queue, true) && $this->isSpanOpen($index, $page) && $page->conditions->holdsAt($state)) {
        $this->queue[] = $index;
      }
    }

    return $this->queue !== [];
  }

  /**
   * Advances the running page, starting the next queued one when it ends.
   *
   * @return bool True while pages are still running.
   */
  public function update(float $deltaSeconds): bool
  {
    $interpreter = $this->interpreter ??= new EventInterpreter($this->gameScene, $this->presentation, host: $this);

    if ($interpreter->hasActiveSession()) {
      $interpreter->update($deltaSeconds);

      if ($interpreter->hasActiveSession()) {
        return true;
      }
    }

    while ($this->requestedOutcome === null && ($index = array_shift($this->queue)) !== null) {
      $page = $this->troop->eventPages[$index];

      // An earlier page in this check may have changed what this one tests.
      if (! $this->isSpanOpen($index, $page) || ! $page->conditions->holdsAt($this->createCheck())) {
        continue;
      }

      $this->ranInBattle[$index] = true;
      $this->ranInTurn[$index] = $this->turn;
      $session = $interpreter->run($page->commands, $this->getScriptId($index, $page), origin: [
        'source' => sprintf('troop:%s', $this->troop->definitionId ?? $this->troop->name),
        'trigger' => sprintf('events[%d]', $index),
      ]);

      if ($session === null) {
        throw new RuntimeException(sprintf('Battle event page %s could not start.', $this->getScriptId($index, $page)));
      }

      if ($interpreter->hasActiveSession()) {
        return true;
      }
    }

    $this->queue = [];

    return false;
  }

  /** Abandons a running page when the battle ends early, releasing its dialogue. */
  public function dispose(): void
  {
    $this->queue = [];

    if ($this->interpreter?->hasActiveSession()) {
      $this->interpreter->failActiveSession('The battle ended while its event page was running.');
    }
  }

  /** @inheritDoc */
  public function concedeEnemy(int $member): void
  {
    $enemy = $this->troop->getMember($member)
      ?? throw new RuntimeException(sprintf('Troop "%s" has no member %d to concede.', $this->troop->name, $member));

    if ($enemy->concede() && $this->onConcede !== null) {
      ($this->onConcede)($enemy);
    }
  }

  /** @inheritDoc */
  public function requestOutcome(BattleOutcome $outcome): void
  {
    $this->requestedOutcome ??= $outcome;
  }

  /** @inheritDoc */
  public function getEventScope(): EventExecutionScope
  {
    return EventExecutionScope::BATTLE;
  }

  /** @inheritDoc */
  public function getEventWorldState(): GameState
  {
    return $this->gameScene->gameState;
  }

  /** @inheritDoc */
  public function getEventParty(): ?Party
  {
    return $this->party;
  }

  /** @inheritDoc */
  public function getBattleControl(): ?BattleEventControlInterface
  {
    return $this;
  }

  /** @inheritDoc */
  public function onEventSessionStarted(EventExecutionSession $session): void
  {
    Debug::info(sprintf('Battle event page %s started.', $session->scriptId ?? 'inline'));
  }

  /** @inheritDoc */
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
  {
    Debug::info(sprintf('Battle event page %s %s.', $session->scriptId ?? 'inline', $completed ? 'completed' : 'failed'));
  }

  private function isSpanOpen(int $index, BattleEventPage $page): bool
  {
    return match ($page->span) {
      BattleEventSpan::BATTLE => ! isset($this->ranInBattle[$index]),
      BattleEventSpan::TURN => ($this->ranInTurn[$index] ?? null) !== $this->turn,
      BattleEventSpan::MOMENT => true,
    };
  }

  private function createCheck(): BattleEventCheck
  {
    return new BattleEventCheck(
      $this->turn,
      $this->troop->members->toArray(),
      array_values(array_filter(($this->partyBattlers)(), static fn($member): bool => $member instanceof Character)),
      $this->gameScene->gameState,
      $this->party,
    );
  }

  private function getScriptId(int $index, BattleEventPage $page): string
  {
    return sprintf('%s/events[%d]%s', $this->troop->definitionId ?? $this->troop->name, $index,
      $page->name === '' ? '' : " ({$page->name})");
  }
}
