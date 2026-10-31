<?php

use Assegai\Util\Text;
use Ichiloto\Engine\Localization\WordForms;

// The inflector keeps its exceptions for the whole process, so each test names words no other test uses.

it('keeps an authored word from being inflected by the English rules', function () {
  expect(new Text('Q-Vexmana')->getSingularForm())->toBe('Q-Vexmanum');

  WordForms::fromArray(['schema' => WordForms::SCHEMA, 'words' => [
    'Q-Vexmana' => ['singular' => 'Q-Vexmana', 'plural' => 'Q-Vexmana'],
  ]])->registerExceptions();

  expect(new Text('Q-Vexmana')->getSingularForm())->toBe('Q-Vexmana')
    ->and(new Text('Q-Vexmana')->getPluralForm())->toBe('Q-Vexmana');
});

it('inflects every spelling of an authored word to its authored forms', function () {
  WordForms::fromArray(['schema' => WordForms::SCHEMA, 'words' => [
    'Glimmerstratum' => ['singular' => 'Glimmerstratum', 'plural' => 'Glimmerstrata'],
  ]])->registerExceptions();

  expect(new Text('Glimmerstratum')->getPluralForm())->toBe('Glimmerstrata')
    ->and(new Text('Glimmerstrata')->getSingularForm())->toBe('Glimmerstratum')
    ->and(new Text('Glimmerstratum')->getSingularForm())->toBe('Glimmerstratum')
    ->and(new Text('Glimmerstrata')->getPluralForm())->toBe('Glimmerstrata');
});

it('leaves words the project does not name to the inflector rules', function () {
  WordForms::fromArray(['schema' => WordForms::SCHEMA, 'words' => [
    'R-Vexmana' => ['singular' => 'R-Vexmana', 'plural' => 'R-Vexmana'],
  ]])->registerExceptions();

  expect(new Text('Quillbrand')->getPluralForm())->toBe('Quillbrands')
    ->and(new Text('Quillbrands')->getSingularForm())->toBe('Quillbrand');
});

it('reads a project without word forms as having none', function () {
  $root = sys_get_temp_dir() . '/word-forms-' . bin2hex(random_bytes(4));
  mkdir($root . '/Data', 0777, true);

  try {
    expect(WordForms::load($root)->words)->toBe([]);

    file_put_contents($root . '/' . WordForms::FILE, "<?php return ['schema' => '" . WordForms::SCHEMA
      . "', 'words' => ['Sol-Vexmana' => ['singular' => 'Sol-Vexmana', 'plural' => 'Sol-Vexmanae']]];");
    expect(WordForms::load($root)->words)->toBe(['Sol-Vexmana' => ['singular' => 'Sol-Vexmana', 'plural' => 'Sol-Vexmanae']]);
  } finally {
    @unlink($root . '/' . WordForms::FILE);
    @rmdir($root . '/Data');
    @rmdir($root);
  }
});

it('refuses word forms the inflector could not use', function (mixed $data) {
  expect(fn() => WordForms::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
  'no schema' => [['words' => []]],
  'a list of words' => [['schema' => WordForms::SCHEMA, 'words' => [['singular' => 'A', 'plural' => 'B']]]],
  'a blank plural' => [['schema' => WordForms::SCHEMA, 'words' => ['Word' => ['singular' => 'Word', 'plural' => ' ']]]],
  'a missing singular' => [['schema' => WordForms::SCHEMA, 'words' => ['Word' => ['plural' => 'Words']]]],
  'an unknown field' => [['schema' => WordForms::SCHEMA, 'words' => ['Word' => ['singular' => 'Word', 'plural' => 'Words', 'gender' => 'f']]]],
  'a padded word' => [['schema' => WordForms::SCHEMA, 'words' => [' Word' => ['singular' => 'Word', 'plural' => 'Words']]]],
]);
