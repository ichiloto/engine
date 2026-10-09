<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

beforeEach(function () {
  $this->priorVocabularyConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  ConfigStore::remove(ProjectConfig::class);
});

afterEach(function () {
  ConfigStore::remove(ProjectConfig::class);
  if ($this->priorVocabularyConfig !== null) { ConfigStore::put(ProjectConfig::class, $this->priorVocabularyConfig); }
});

it('uses documented defaults without project configuration', function () {
  expect(Vocabulary::getTerm('currency.name', 'Gold'))->toBe('Gold')
    ->and(Vocabulary::getTerm('currency.symbol', 'G'))->toBe('G')
    ->and(Vocabulary::getTerms('command.summon_by_role'))->toBe([]);
});

it('resolves localized terms before base terms and ignores values of the wrong type', function () {
  putSceneAudioConfig(['locale' => 'alternate', 'vocab' => [
    'currency' => ['name' => 'Tokens', 'symbol' => 'TK'],
    'command' => ['attack' => ['invalid']],
    'alternate' => ['currency' => ['name' => 'Marks', 'symbol' => false]],
  ]]);
  expect(Vocabulary::getTerm('currency.name', 'Gold'))->toBe('Marks')
    ->and(Vocabulary::getTerm('currency.symbol', 'G'))->toBe('TK')
    ->and(Vocabulary::getTerm('command.attack', 'Attack'))->toBe('Attack')
    ->and(Vocabulary::getTerm('game.save', 'Save'))->toBe('Save');
});

it('preserves an explicitly empty currency symbol and reads the current project without a stale cache', function () {
  putSceneAudioConfig(['vocab' => ['currency' => ['name' => 'Tokens', 'symbol' => '']]]);
  expect(Vocabulary::getTerm('currency.symbol', 'G'))->toBe('');
  putSceneAudioConfig(['vocab' => ['currency' => ['name' => 'Credits', 'symbol' => 'CR']]]);
  expect(Vocabulary::getTerm('currency.name', 'Gold'))->toBe('Credits')
    ->and(Vocabulary::getTerm('currency.symbol', 'G'))->toBe('CR');
});

it('localizes role labels without changing semantic command and icon identities', function () {
  putSceneAudioConfig(['locale' => 'alternate', 'vocab' => [
    'command' => ['attack' => 'Strike', 'summon' => 'Invoke',
      'summon_by_role' => ['Oracle' => 'Petition', 'Vanguard' => 'Request', 'Invalid' => false]],
    'alternate' => ['command' => ['attack' => 'Smite', 'summon_by_role' => ['Oracle' => 'Entreat']]],
  ]]);
  expect(BattleCommandType::ATTACK->label())->toBe('Smite')
    ->and(BattleCommandType::ATTACK->value)->toBe('attack')
    ->and(BattleCommandType::ATTACK->getIconRole())->toBe('command.attack')
    ->and(BattleCommandType::fromCommandName('attack'))->toBe(BattleCommandType::ATTACK)
    ->and(BattleCommandType::fromCommandName('Smite'))->toBe(BattleCommandType::ATTACK)
    ->and(BattleCommandType::SUMMON->labelForRole('oracle'))->toBe('Entreat')
    ->and(BattleCommandType::SUMMON->labelForRole('Vanguard'))->toBe('Request')
    ->and(BattleCommandType::SUMMON->roleLabels())->not->toHaveKey('Invalid');
});

it('never lets a renamed display label shadow another commands semantic ID', function () {
  putSceneAudioConfig(['vocab' => ['command' => ['attack' => 'Magic']]]);
  expect(BattleCommandType::ATTACK->label())->toBe('Magic')
    ->and(BattleCommandType::fromCommandName('magic'))->toBe(BattleCommandType::MAGIC)
    ->and(BattleCommandType::fromCommandName('attack'))->toBe(BattleCommandType::ATTACK);
});
