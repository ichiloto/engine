<?php

declare(strict_types=1);

use Ichiloto\Engine\Localization\{LocalizedText, MessageCatalog};
use Ichiloto\Engine\Util\Config\{ConfigStore, ProjectConfig};

beforeEach(function () {
  $this->localizedTextPriorConfig = ConfigStore::has(ProjectConfig::class)
    ? ConfigStore::get(ProjectConfig::class) : null;
  ConfigStore::remove(ProjectConfig::class);
});

afterEach(function () {
  ConfigStore::remove(ProjectConfig::class);
  if ($this->localizedTextPriorConfig !== null) {
    ConfigStore::put(ProjectConfig::class, $this->localizedTextPriorConfig);
  }
});

it('round trips authored message identity and base text without persisting locale or renderer state', function () {
  $data = ['message' => 'signs.synthetic-label_2', 'default' => "  \u{00C9}tiquette %1  "];
  $text = LocalizedText::fromArray($data);
  expect($text->message)->toBe($data['message'])->and($text->default)->toBe($data['default'])
    ->and($text->getText())->toBe($data['default'])->and($text->toArray())->toBe($data)
    ->and(LocalizedText::fromArray($text->toArray())->toArray())->toBe($data)
    ->and(MessageCatalog::locale())->toBe('')
    ->and(fn() => $text->message = 'changed')->toThrow(Error::class)
    ->and(fn() => $text->default = 'changed')->toThrow(Error::class);
});

it('accepts empty single-line text and inclusive message byte and Unicode scalar limits', function () {
  $message = 'A' . str_repeat('x', 255);
  foreach (['', str_repeat('a', 4096), str_repeat("\u{1F331}", 4096)] as $default) {
    $text = LocalizedText::fromArray(['message' => $message, 'default' => $default]);
    expect($text->getText())->toBe($default)->and($text->toArray()['message'])->toBe($message);
  }
  expect(new LocalizedText('Synthetic.segment-2_name', 'label')->message)->toBe('Synthetic.segment-2_name');
});

it('requires exactly message and default strings without implicit values or extra metadata', function (mixed $data) {
  expect(fn() => LocalizedText::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(function () {
  $base = ['message' => 'synthetic.label', 'default' => 'Base label'];
  $cases = ['null' => [null], 'boolean' => [false], 'string' => ['label'], 'object' => [new stdClass()],
    'empty' => [[]], 'list' => [array_values($base)], 'missing-message' => [['default' => 'label']],
    'missing-default' => [['message' => 'synthetic.label']]];
  foreach (['locale', 'width', 'layoutId', 'translations'] as $field) {
    $cases['unknown-' . $field] = [[...$base, $field => 'not-authored-text']];
  }
  foreach (['message', 'default'] as $field) {
    foreach ([null, false, 1, 1.0, [], new stdClass()] as $index => $value) {
      $cases[$field . '-type-' . $index] = [array_replace($base, [$field => $value])];
    }
  }
  return $cases;
});

it('rejects malformed or excessive message paths at both array and direct admission', function (string $message) {
  expect(fn() => LocalizedText::fromArray(['message' => $message, 'default' => 'label']))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => new LocalizedText($message, 'label'))->toThrow(InvalidArgumentException::class);
})->with([
  'empty' => [''], 'numeric-start' => ['1label'], 'leading-dot' => ['.label'], 'trailing-dot' => ['label.'],
  'empty-segment' => ['sign..label'], 'space' => ['sign label'], 'slash' => ['sign/label'],
  'newline' => ["label\n"], 'nul' => ["label\0"], 'invalid-utf8' => ["\xFF"],
  'nonascii-identifier' => ["\u{00E9}tiquette"], 'too-long' => [str_repeat('a', 257)],
]);

it('rejects multiline control invalid UTF-8 and excessive scalar base text', function (string $default) {
  expect(fn() => LocalizedText::fromArray(['message' => 'synthetic.label', 'default' => $default]))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => new LocalizedText('synthetic.label', $default))->toThrow(InvalidArgumentException::class);
})->with([
  'newline' => ["one\ntwo"], 'carriage-return' => ["one\rtwo"], 'crlf' => ["one\r\ntwo"],
  'tab' => ["one\ttwo"], 'nul' => ["one\0two"], 'escape' => ["one\x1Btwo"], 'delete' => ["one\x7Ftwo"],
  'unicode-control' => ["one\u{0085}two"], 'line-separator' => ["one\u{2028}two"],
  'paragraph-separator' => ["one\u{2029}two"], 'invalid-byte' => ["\xFF"],
  'truncated-utf8' => ["\xC3"], 'too-many-ascii-scalars' => [str_repeat('a', 4097)],
  'too-many-multibyte-scalars' => [str_repeat("\u{1F331}", 4097)],
]);

it('resolves current locale then base catalog then authored default without changing stored intent', function () {
  $text = new LocalizedText('synthetic.label', 'Authored fallback');
  $intent = $text->toArray();
  foreach ([
    ['fr', ['fr' => ['synthetic' => ['label' => "\u{00C9}tiquette"]], 'synthetic' => ['label' => 'Base catalog']], "\u{00C9}tiquette"],
    ['fr', ['fr' => ['synthetic' => ['other' => 'Other']], 'synthetic' => ['label' => 'Base catalog']], 'Base catalog'],
    ['fr', ['fr' => ['synthetic' => ['label' => '']], 'synthetic' => ['label' => 'Base catalog']], 'Base catalog'],
    ['fr', ['fr' => ['synthetic' => ['label' => 12]], 'synthetic' => ['label' => 'Base catalog']], 'Base catalog'],
    ['de', [], 'Authored fallback'], ['', ['synthetic' => ['label' => 'Base catalog']], 'Base catalog'],
    ['fr', ['synthetic' => ['label' => false]], 'Authored fallback'],
  ] as [$locale, $messages, $expected]) {
    $config = new SceneAudioConfigStub(['locale' => $locale, 'messages' => $messages]);
    ConfigStore::put(ProjectConfig::class, $config);
    expect($text->getText())->toBe($expected)->and($text->toArray())->toBe($intent)
      ->and(MessageCatalog::locale())->toBe($locale)->and(ConfigStore::get(ProjectConfig::class))->toBe($config);
  }
});

it('tracks locale switches while preserving whitespace placeholders Unicode and the configured locale', function () {
  $text = new LocalizedText('synthetic.label', 'Fallback');
  $messages = ['en-GB' => ['synthetic' => ['label' => '  Label %1  ']],
    'ja' => ['synthetic' => ['label' => "\u{5408}\u{6210} %1"]]];
  foreach ([['  en-GB  ', 'en-GB', '  Label %1  '], ['ja', 'ja', "\u{5408}\u{6210} %1"]] as [$raw, $locale, $expected]) {
    $config = new SceneAudioConfigStub(['locale' => $raw, 'messages' => $messages]);
    ConfigStore::put(ProjectConfig::class, $config);
    expect($text->getText())->toBe($expected)->and(MessageCatalog::locale())->toBe($locale)
      ->and($config->get('locale'))->toBe($raw)
      ->and($text->toArray())->toBe(['message' => 'synthetic.label', 'default' => 'Fallback']);
  }
  ConfigStore::remove(ProjectConfig::class);
  expect($text->getText())->toBe('Fallback')->and(MessageCatalog::locale())->toBe('');
});

it('preserves valid empty base catalog text and exact translated scalar limits', function () {
  $text = new LocalizedText('synthetic.label', 'Fallback');
  foreach (['', str_repeat("\u{1F331}", 4096)] as $resolved) {
    ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub([
      'locale' => 'fr', 'messages' => ['synthetic' => ['label' => $resolved]],
    ]));
    expect($text->getText())->toBe($resolved)->and(MessageCatalog::locale())->toBe('fr');
  }
});

it('refuses invalid resolved catalog text instead of silently replacing a broken translation', function (string $resolved, bool $localized) {
  $tree = ['synthetic' => ['label' => $resolved]];
  $messages = $localized ? ['fr' => $tree, 'synthetic' => ['label' => 'Valid base']] : $tree;
  $config = new SceneAudioConfigStub(['locale' => 'fr', 'messages' => $messages]);
  ConfigStore::put(ProjectConfig::class, $config);
  $text = new LocalizedText('synthetic.label', 'Valid fallback');
  expect(fn() => $text->getText())->toThrow(InvalidArgumentException::class)
    ->and(MessageCatalog::locale())->toBe('fr')->and(ConfigStore::get(ProjectConfig::class))->toBe($config)
    ->and($text->toArray())->toBe(['message' => 'synthetic.label', 'default' => 'Valid fallback']);
})->with(function () {
  $cases = [];
  foreach (['newline' => "one\ntwo", 'tab' => "one\ttwo", 'nul' => "one\0two",
    'invalid-utf8' => "\xFF", 'too-long' => str_repeat("\u{1F331}", 4097),
    'line-separator' => "one\u{2028}two", 'paragraph-separator' => "one\u{2029}two"] as $name => $resolved) {
    $cases['base-' . $name] = [$resolved, false];
    $cases['localized-' . $name] = [$resolved, true];
  }
  return $cases;
});
