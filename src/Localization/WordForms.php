<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Localization;

use Assegai\Util\Path;
use Atatusoft\Plural\Plural;
use InvalidArgumentException;

/**
 * A project's word form exceptions: authored names whose singular and plural
 * forms the inflector must not derive from its English rules (a name such as
 * "S-Mana" that the rules would turn into "S-Manum").
 *
 * The project owns every word; the Engine holds none. Each entry is keyed by
 * the word as the game writes it and names its singular and plural forms.
 * {@see self::registerExceptions()} hands them to the inflector, so every
 * inflection in the game, wherever it happens, respects them. The inflector
 * matches a whole name, so an exception names the whole word it inflects.
 */
final readonly class WordForms
{
  public const string FILE = 'Data/word-forms.php';
  public const string SCHEMA = 'ichiloto.word-forms/1';
  public const int MAX_WORDS = 1024;
  /** The inflector's rule set these exceptions extend. */
  public const string LANGUAGE = 'en';
  /** The fields one word entry holds. */
  public const array FORM_FIELDS = ['singular', 'plural'];

  /** @var array<string, array{singular: string, plural: string}> Keyed by the word as the game writes it. */
  public array $words;

  /** @param array<mixed, mixed> $words */
  public function __construct(array $words = [])
  {
    if (count($words) > self::MAX_WORDS) {
      throw new InvalidArgumentException(sprintf('Word forms exceed %d words.', self::MAX_WORDS));
    }

    $copy = [];
    foreach ($words as $word => $forms) {
      if (! is_string($word) || trim($word) === '' || trim($word) !== $word) {
        throw new InvalidArgumentException('Each word form is keyed by its word, written without surrounding spaces.');
      }
      if (! is_array($forms) || array_diff(array_keys($forms), self::FORM_FIELDS) !== []) {
        throw new InvalidArgumentException(sprintf('The word "%s" holds only a singular and a plural form.', $word));
      }
      foreach (self::FORM_FIELDS as $field) {
        $form = $forms[$field] ?? null;
        if (! is_string($form) || trim($form) === '' || trim($form) !== $form) {
          throw new InvalidArgumentException(sprintf('The word "%s" needs its %s form, written without surrounding spaces.', $word, $field));
        }
      }
      $copy[$word] = ['singular' => $forms['singular'], 'plural' => $forms['plural']];
    }

    $this->words = $copy;
  }

  /** Reads the project's word forms; a project without the file has none. */
  public static function load(string $assetRoot): self
  {
    $file = $assetRoot . '/' . self::FILE;
    if (! file_exists($file) && ! is_link($file)) {
      return new self();
    }

    $root = realpath($assetRoot);
    $path = realpath($file);
    if ($root === false || $path === false || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR) || ! is_file($path)) {
      throw new InvalidArgumentException('Word forms must be a readable file inside assets.');
    }

    return self::fromArray((static fn(string $path): mixed => require $path)($path));
  }

  /** Reads the running project's word forms. */
  public static function fromProject(): self
  {
    return self::load(Path::join(Path::getCurrentWorkingDirectory(), 'assets'));
  }

  public static function fromArray(mixed $data): self
  {
    if (! is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA
      || array_diff(array_keys($data), ['schema', 'words']) !== [] || ! is_array($data['words'] ?? [])) {
      throw new InvalidArgumentException(sprintf('Word forms require schema %s and a words dictionary.', self::SCHEMA));
    }

    return new self($data['words'] ?? []);
  }

  /** @return array{schema: string, words: array<string, array{singular: string, plural: string}>} */
  public function toArray(): array
  {
    return ['schema' => self::SCHEMA, 'words' => $this->words];
  }

  /**
   * Registers every word with the inflector. Each of the word's spellings
   * singularizes to its singular form and pluralizes to its plural form, so
   * inflecting a form that is already right never changes it.
   */
  public function registerExceptions(): void
  {
    // Registering before the rule set loads would file the exceptions under no language.
    Plural::loadLanguage(self::LANGUAGE);
    Plural::setLanguage(self::LANGUAGE);

    foreach ($this->words as $word => ['singular' => $singular, 'plural' => $plural]) {
      foreach (array_unique([$word, $singular, $plural]) as $spelling) {
        Plural::addIrregular($spelling, $plural);
        Plural::addSingularIrregular($spelling, $singular);
      }
    }
  }
}
