<?php

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackTiming;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;

/** Captures terminal bytes while retaining production popup normalization and storage. */
final class FeedbackExposureField extends BattleFieldWindow
{
  public array $draws = [];
  protected function resolveStatChangePopupAnchor(CharacterInterface $battler): ?array
  {
    return ['x' => 20, 'y' => 10, 'partyIndex' => 0];
  }
  protected function renderIndicator(string $text, int $x, int $y): void
  {
    $this->draws[] = ['text' => $text, 'x' => $x, 'y' => $y];
  }
}

final class FeedbackExposureScreen extends BattleScreen
{
  public array $events = [];
  public function __construct(
    Party $party,
    Troop $troop,
    bool $graphical = true,
    ?BattleFeedbackTiming $timing = null,
  ) {
    $this->graphical = $graphical;
    $this->battleScene = new ReflectionClass(BattleScene::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(BattleScene::class, 'config')->setValue($this->battleScene, new BattleConfig($party, $troop));
    $this->screenDimensions = new Rect(0, 0, 135, 36);
    $this->borderPack = new DefaultBorderPack();
    $this->fieldWindow = new FeedbackExposureField($this, $timing);
  }
  private bool $graphical;
  public function usesGraphicalField(): bool { return $this->graphical; }
  public function hideMessage(): void { $this->events[] = 'hide'; }
  public function refresh(): void
  {
    $this->events[] = 'refresh';
    $this->fieldWindow->renderStatChangePopups();
  }
  public function refreshField(): void
  {
    $this->events[] = 'refreshField';
    $this->fieldWindow->renderStatChangePopups();
  }
}

final class FeedbackExposureActionState extends ActionExecutionState
{
  public array $holds = [];
  public function __construct(private FeedbackExposureScreen $screen) {}
  protected function pause(float $seconds): void
  {
    $this->screen->events[] = 'pause';
    $this->holds[] = ['seconds' => $seconds, 'feedback' => $this->screen->fieldWindow->getFeedback()];
  }
}

function feedbackExposureTarget(string $name = 'Twin', int $hp = 50): Character
{
  return new Character($name, 1, new Stats(currentHp: $hp, totalHp: 100, currentMp: 20, totalMp: 30));
}

it('retains stable feedback identity and shown time across redraws until explicit clear', function ($graphical) {
  $now = 10.0;
  $reads = 0;
  $timing = new BattleFeedbackTiming(function () use (&$now, &$reads): float { $reads++; return $now; });
  $screen = new FeedbackExposureScreen(new Party(), new Troop('Test'), $graphical, $timing);
  $field = $screen->fieldWindow;
  $target = feedbackExposureTarget();
  $lines = [['text' => '50', 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::DAMAGE]];
  $field->showStatChangePopup($target, $lines, durationSeconds: 2.5);
  $first = $field->getFeedback();

  expect($first)->toBe([['sequence' => 1, 'battler' => $target, 'lines' => $lines, 'shownAt' => 10.0, 'durationSeconds' => 2.5]]);
  $now = 20.0;
  $screen->refresh();
  $screen->refreshField();
  $screen->refresh();
  expect($field->getFeedback())->toBe($first)->and($reads)->toBe(1);

  $field->showStatChangePopup($target, [['text' => '+5']], false, 0.25);
  expect(array_column($field->getFeedback(), 'sequence'))->toBe([1, 2])
    ->and(array_column($field->getFeedback(), 'shownAt'))->toBe([10.0, 20.0])
    ->and($field->getFeedback()[0])->toBe($first[0]);

  $field->clearStatChangePopups();
  $field->draws = [];
  $screen->refreshField();
  expect($field->getFeedback())->toBe([])->and($field->draws)->toBe([]);
  foreach (['statChangePopups', 'popupPartyIndices', 'popupTroopIndices'] as $property) {
    expect(new ReflectionProperty(BattleFieldWindow::class, $property)->getValue($field))->toBe([]);
  }
  $field->showStatChangePopup($target, [['text' => 'MISS']]);
  expect($field->getFeedback()[0]['sequence'])->toBe(3)
    ->and($field->getFeedback()[0]['durationSeconds'])->toBe(0.0);
})->with([true, false]);

it('preserves legacy line defaults and explicit roles without inspecting text or color', function () {
  $field = new FeedbackExposureScreen(new Party(), new Troop('Test'))->fieldWindow;
  $field->showStatChangePopup(feedbackExposureTarget(), [
    ['text' => ''], ['text' => 0], ['text' => 'MISS', 'color' => Color::LIGHT_GREEN, 'role' => BattleFeedbackRole::DAMAGE],
  ]);
  expect($field->getFeedback()[0]['lines'])->toBe([
    ['text' => '0', 'color' => Color::WHITE],
    ['text' => 'MISS', 'color' => Color::LIGHT_GREEN, 'role' => BattleFeedbackRole::DAMAGE],
  ]);
});

it('rejects an invalid explicit semantic role rather than guessing it', function ($role) {
  $field = new FeedbackExposureScreen(new Party(), new Troop('Test'))->fieldWindow;
  expect(fn() => $field->showStatChangePopup(feedbackExposureTarget(), [['text' => '10', 'role' => $role]]))
    ->toThrow(InvalidArgumentException::class, 'role must be a BattleFeedbackRole');
  expect($field->getFeedback())->toBe([]);
})->with(['damage', 1, null]);

it('keeps exact terminal color reset bytes placement and legacy call behavior', function () {
  $field = new FeedbackExposureScreen(new Party(), new Troop('Test'), false)->fieldWindow;
  $field->showStatChangePopup(feedbackExposureTarget(), [
    ['text' => 'WEAK!', 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::WEAK],
    ['text' => 'CRITICAL', 'color' => Color::YELLOW, 'role' => BattleFeedbackRole::CRITICAL],
    ['text' => '48', 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::DAMAGE],
    ['text' => 'KO'],
  ]);
  $field->renderStatChangePopups();
  expect($field->draws)->toBe([
    ['text' => Color::LIGHT_RED->value . 'WEAK!' . Color::RESET->value, 'x' => 18, 'y' => 7],
    ['text' => Color::YELLOW->value . 'CRITICAL' . Color::RESET->value, 'x' => 16, 'y' => 8],
    ['text' => Color::LIGHT_RED->value . '48' . Color::RESET->value, 'x' => 19, 'y' => 9],
    ['text' => Color::WHITE->value . 'KO' . Color::RESET->value, 'x' => 19, 'y' => 10],
  ]);
});

it('preserves empty popup clear behavior without consuming a feedback sequence', function () {
  $field = new FeedbackExposureScreen(new Party(), new Troop('Test'))->fieldWindow;
  $target = feedbackExposureTarget();
  $field->showStatChangePopup($target, [['text' => 'first']]);
  $first = $field->getFeedback();
  $field->showStatChangePopup($target, [['text' => '']], false);
  expect($field->getFeedback())->toBe($first);
  $field->showStatChangePopup($target, []);
  expect($field->getFeedback())->toBe([]);
  $field->showStatChangePopup($target, [['text' => 'next']]);
  expect($field->getFeedback()[0]['sequence'])->toBe(2);
});

it('exposes focused queued and acting instances independently without changing combined selection', function () {
  $party = new Party();
  [$actor, $ally, $fallen] = [feedbackExposureTarget('Actor'), feedbackExposureTarget('Ally'), feedbackExposureTarget('Fallen', 0)];
  foreach ([$actor, $ally, $fallen] as $member) { $party->addMember($member); }
  $troop = new Troop('Twins');
  [$first, $second] = [feedbackExposureTarget(), feedbackExposureTarget()];
  foreach ([$first, $second] as $member) { $troop->addMember($member); }
  $field = new FeedbackExposureScreen($party, $troop)->fieldWindow;
  $field->focusPartyBattlers([2, 1, 2, 99, -1]);
  $field->focusTroopBattlers([1]);
  $field->setPartyTargetQueue([0 => 2, 1 => 1, 2 => 1, 99 => 1]);
  $field->setTroopTargetQueue([0 => 3, 1 => 0]);
  $field->stepPartyBattlerForward($actor, 0);

  expect($field->getFocusedBattlers())->toBe([$fallen, $ally, $second])
    ->and($field->getQueuedBattlers())->toBe([$actor, $ally, $first])
    ->and($field->getActingBattler())->toBe($actor)
    ->and($field->getSelectedBattlers())->toBe([$fallen, $ally, $actor, $second, $first]);
  $first->stats->currentHp = 0;
  expect($field->getQueuedBattlers())->toBe([$actor, $ally]);
  $field->clearPartyFocus();
  $field->clearTroopFocus();
  expect($field->getFocusedBattlers())->toBe([])->and($field->getSelectedBattlers())->toBe([$actor, $ally]);
  $field->clearTargetIndicators();
  expect($field->getQueuedBattlers())->toBe([])->and($field->getActingBattler())->toBe($actor);
  $field->stepPartyBattlerBack($actor, 0);
  expect($field->getActingBattler())->toBeNull();
});

it('passes the existing single or grouped hold duration without adding waits or changing clears', function ($grouped, $delay) {
  $screen = new FeedbackExposureScreen(new Party(), new Troop('Test'), timing: new BattleFeedbackTiming(static fn(): float => 30.0));
  $state = new FeedbackExposureActionState($screen);
  $context = new ReflectionClass(TurnStateExecutionContext::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(TurnStateExecutionContext::class, 'ui')->setValue($context, $screen);
  $target = feedbackExposureTarget();
  $screen->fieldWindow->showStatChangePopup($target, [['text' => 'stale']]);
  $method = new ReflectionMethod(ActionExecutionState::class, $grouped ? 'displayStatChangesForTargets' : 'displayStatChanges');
  if ($grouped) {
    $method->invoke($state, $context, [$target, $target], [[100, 20], [70, 20]], $delay);
  } else {
    $method->invoke($state, $context, $target, 100, 20, $delay);
  }

  expect($screen->events)->toBe(['hide', 'refresh', 'pause', 'refreshField'])
    ->and($state->holds)->toHaveCount(1)->and($state->holds[0]['seconds'])->toBe($delay)
    ->and($screen->fieldWindow->getFeedback())->toBe([]);
  $feedback = $state->holds[0]['feedback'];
  expect(array_column($feedback, 'sequence'))->toBe($grouped ? [2, 3] : [2])
    ->and(array_column($feedback, 'durationSeconds'))->toBe(array_fill(0, $grouped ? 2 : 1, max(0.0, $delay)))
    ->and(array_column($feedback, 'shownAt'))->toBe(array_fill(0, $grouped ? 2 : 1, 30.0))
    ->and(array_map(fn($entry) => $entry['lines'][0]['text'], $feedback))->toBe($grouped ? ['50', '20'] : ['50']);
})->with([true, false])->with([0.875, 0.0, -0.25]);
