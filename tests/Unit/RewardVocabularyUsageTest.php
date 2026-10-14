<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleHudSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleResultsPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleResultsSkin;
use Ichiloto\Engine\Battle\Presentation\BattleResultsText;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleHud;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleResults;
use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Entities\Abilities\AbilityLearningRequirement;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Magic\SpellLearningRequirement;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

function getRewardVocabularyTextures(array $roles): array
{
  return array_combine($roles, array_map(static fn($role) => new CanvasNineSlice($role . '.png',
    new SpriteSourceRect(0, 0, 8, 8)), $roles));
}

function getRewardVocabularyCanvasText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(static fn($layer) => implode('', array_column($layer->runs, 'text')), $canvas->textLayers));
}

beforeEach(function () {
  $this->priorRewardConfig = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  putSceneAudioConfig(['vocab' => [
    'currency' => ['name' => 'Ancient Sapphire Tokens', 'symbol' => 'TK'],
    'stats' => ['exp' => 'XP', 'mp' => 'SP', 'hp' => 'Life', 'level' => 'Rank', 'level_short' => 'Rk'],
    'battle' => ['victory' => 'Triumph', 'rewards' => 'Spoils', 'party_progress' => 'Growth',
      'complete' => 'Reveal', 'atb' => 'ATB'], 'command' => ['continue' => 'Proceed'],
  ]]);
});

afterEach(function () {
  foreach ($this->priorRewardConfig as $key => $value) { new ReflectionProperty(ConfigStore::class, $key)->setValue(null, $value); }
});

it('uses the same configured currency in graphical and terminal rewards without mutating facts', function () {
  $facts = new BattleRewards(150, 123, []);
  $before = serialize($facts);
  $playback = new BattleResultsPlayback($facts, true);
  $skin = new BattleResultsSkin(getRewardVocabularyTextures(['panel', 'quiet', 'track', 'selector', 'portrait', 'exp', 'divider', 'button']),
    array_fill_keys(['text', 'muted', 'accent', 'positive', 'negative', 'ink'], PresentationColor::rgb(220, 220, 220)));
  $frame = GraphicalBattleResults::frame(new PresentationCanvas(1350, 720), $skin, $playback);
  expect(getRewardVocabularyCanvasText($frame))->toContain('Ancient Sapphire Tokens', 'Triumph', 'Spoils', 'Growth', '123', 'XP / MEMBER', 'Proceed')
    ->not->toContain('MONEY', 'Gold')
    ->and(implode("\n", BattleResultsText::getLines($playback, 80)))->toContain('Ancient Sapphire Tokens: 123 TK', 'XP per member: 150')
    ->and(serialize($facts))->toBe($before);
  $layers = array_column($frame->textLayers, null, 'id');
  $label = $layers['results-gold-label']->bounds;
  $value = $layers['results-gold-value']->bounds;
  expect($label->x + $label->width)->toBeLessThan($value->x);
});

it('keeps quest and learning summaries currency-aware while preserving serialized cost and reward identities', function () {
  $quest = new Quest('synthetic.quest', 'Authored quest', rewards: ['gold' => 20, 'experience' => 30]);
  expect($quest->describeRewards())->toBe('20 TK, 30 XP')
    ->and($quest->rewards)->toBe(['gold' => 20, 'experience' => 30]);
  ConfigStore::put(ItemStore::class, makeBareScene(ItemStore::class));
  $actor = new Character('Synthetic Actor', 0, new Stats());
  $party = new Party();
  $party->accountBalance = 34;
  foreach ([new AbilityLearningRequirement(goldCost: 50), new SpellLearningRequirement(goldCost: 50)] as $requirement) {
    $before = $requirement->toArray();
    $progress = $requirement instanceof AbilityLearningRequirement
      ? $requirement->describeProgress($actor, $party) : $requirement->describeProgress($actor, $party, 0);
    expect($progress)->toBe('Ancient Sapphire Tokens 34/50')
      ->and($requirement->toArray())->toBe($before)->and($party->accountBalance)->toBe(34);
  }
});

it('supports currency without a symbol in quest and terminal rewards', function () {
  putSceneAudioConfig(['vocab' => ['currency' => ['name' => 'Credits', 'symbol' => '']]]);
  expect(new Quest('synthetic', 'Quest', rewards: ['gold' => 20])->describeRewards())->toBe('20')
    ->and(implode("\n", BattleResultsText::getLines(new BattleResultsPlayback(new BattleRewards(0, 20, []), true), 80)))
    ->toContain('Credits: 20')->not->toContain('20 G');
});

it('renames resource refusal and result controls without changing combat state or outcomes', function () {
  $actor = new Character('Synthetic Actor', 0, new Stats(currentMp: 0, totalMp: 10));
  $action = new SkillBattleAction(new MagicSkill('Authored Spell', 'Authored description.', '?', 5, 0));
  expect($action->getExecutionRefusal($actor))->toBe('Synthetic Actor cannot use Authored Spell: not enough SP.')
    ->and($action->name)->toBe('Authored Spell')->and($actor->stats->currentMp)->toBe(0);
  $playback = new BattleResultsPlayback(new BattleRewards(0, 0, []));
  expect($playback->confirmation()['label'])->toBe('Reveal');
  $playback->update(3);
  $playback->update(1);
  expect($playback->confirmation()['label'])->toBe('Proceed')
    ->and(new BattleResult('Victory')->outcome())->toBe('victory');
});

it('uses configured stat headings in both battle presentations with stable graphical identities', function () {
  $window = makeBareScene(BattleCharacterStatusWindow::class);
  new ReflectionProperty($window, 'camera')->setValue($window, makeBareScene(Camera::class));
  new ReflectionProperty($window, 'borderPack')->setValue($window, new DefaultBorderPack());
  $header = new ReflectionMethod($window, 'formatHeaderLine')->invoke($window, true);
  expect($header)->toContain('Life', 'SP', 'ATB');
  $skin = new BattleUiSkin(getRewardVocabularyTextures(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued']),
    array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'], PresentationColor::rgb(200, 200, 200)));
  $status = new BattleHudStatusSnapshot('', '', [new BattleHudStatusRow(0, 100, 100, 10, 10, 0.5)]);
  $frame = GraphicalBattleHud::compose(new BattleCanvasLayout(1350, 720, skin: $skin,
    feedbackArea: new CanvasRectangle(0, 80, 1350, 452)), new BattleHudSnapshot(status: $status), null, 0);
  expect(getRewardVocabularyCanvasText($frame))->toContain('Life', 'SP', 'ATB')
    ->and(array_column($frame->textLayers, 'id'))->toContain('hud-stats-HP', 'hud-stats-MP', 'hud-stats-Time');
});
