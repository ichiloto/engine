<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use InvalidArgumentException;

/**
 * One authored field of a registered script command.
 *
 * The field is plain data, so the runtime, the Editor and other tools read
 * the same declaration without loading the command's handler.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final readonly class ScriptCommandField
{
  /** A key, or a dotted path into nested keys such as `confirmDialogue.text`. */
  private const string KEY_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*\z/';

  /**
   * @param string $key The command key holding the value, or a dotted path.
   * @param string $label The name authors see.
   * @param ScriptCommandFieldKind $kind The kind of value.
   * @param bool $required Whether every command must give a value.
   * @param ScriptCommandReference|null $reference The resource a reference names.
   * @param list<string> $options The values an option field allows.
   * @param list<ScriptCommandField> $fields The fields of each entry of a list.
   * @param int|float|null $minimum The smallest number allowed.
   * @param string $description What the field means, for authors.
   */
  public function __construct(
    public string $key,
    public string $label,
    public ScriptCommandFieldKind $kind,
    public bool $required = false,
    public ?ScriptCommandReference $reference = null,
    public array $options = [],
    public array $fields = [],
    public int|float|null $minimum = null,
    public string $description = '',
  )
  {
  }

  /**
   * Reads a field declaration.
   *
   * @param mixed $declaration The declared field.
   * @param string $where Where the declaration is, for messages.
   * @param bool $allowsList Whether this field may itself be a list.
   * @throws InvalidArgumentException When the declaration is malformed.
   */
  public static function fromArray(mixed $declaration, string $where, bool $allowsList = true): self
  {
    if (! is_array($declaration) || array_is_list($declaration)) {
      throw new InvalidArgumentException("{$where} must be a keyed array.");
    }

    $key = trim(strval($declaration['key'] ?? ''));

    if (preg_match(self::KEY_PATTERN, $key) !== 1) {
      throw new InvalidArgumentException("{$where} needs a key of letters, digits and underscores, with dots between nested keys.");
    }

    if ($key === 'type') {
      throw new InvalidArgumentException("{$where} cannot use the key \"type\"; it names the command.");
    }

    $label = trim(strval($declaration['label'] ?? ''));

    if ($label === '') {
      throw new InvalidArgumentException("{$where} ({$key}) needs a label.");
    }

    $kind = ScriptCommandFieldKind::tryFrom(strval($declaration['kind'] ?? ''))
      ?? throw new InvalidArgumentException(sprintf(
        '%s (%s) needs a kind: %s.',
        $where,
        $key,
        implode(', ', array_map(static fn(ScriptCommandFieldKind $kind): string => $kind->value, ScriptCommandFieldKind::cases())),
      ));

    if ($kind === ScriptCommandFieldKind::LIST && ! $allowsList) {
      throw new InvalidArgumentException("{$where} ({$key}) cannot be a list inside a list.");
    }

    $reference = null;

    if ($kind === ScriptCommandFieldKind::REFERENCE) {
      $reference = ScriptCommandReference::tryFrom(strval($declaration['reference'] ?? ''))
        ?? throw new InvalidArgumentException(sprintf(
          '%s (%s) needs the resource it references: %s.',
          $where,
          $key,
          implode(', ', array_map(static fn(ScriptCommandReference $reference): string => $reference->value, ScriptCommandReference::cases())),
        ));
    } elseif (array_key_exists('reference', $declaration)) {
      throw new InvalidArgumentException("{$where} ({$key}) declares a reference but is not a reference field.");
    }

    $options = $declaration['options'] ?? [];

    if ($kind === ScriptCommandFieldKind::OPTION) {
      if (! is_array($options) || ! array_is_list($options) || $options === []
        || array_filter($options, static fn(mixed $option): bool => ! is_string($option) || trim($option) === '') !== []
        || count(array_unique($options)) !== count($options)
      ) {
        throw new InvalidArgumentException("{$where} ({$key}) needs a list of distinct, non-empty options.");
      }
    } elseif ($options !== []) {
      throw new InvalidArgumentException("{$where} ({$key}) declares options but is not an option field.");
    }

    $fields = [];

    if ($kind === ScriptCommandFieldKind::LIST) {
      $fields = self::readFields($declaration['fields'] ?? null, "{$where} ({$key}) fields", allowsList: false);

      if ($fields === []) {
        throw new InvalidArgumentException("{$where} ({$key}) needs the fields of each entry.");
      }
    } elseif (array_key_exists('fields', $declaration)) {
      throw new InvalidArgumentException("{$where} ({$key}) declares fields but is not a list field.");
    }

    $minimum = $declaration['minimum'] ?? null;

    if ($minimum !== null) {
      if (! in_array($kind, [ScriptCommandFieldKind::INTEGER, ScriptCommandFieldKind::NUMBER], true)) {
        throw new InvalidArgumentException("{$where} ({$key}) declares a minimum but is not a number field.");
      }

      if (! is_int($minimum) && ! is_float($minimum)) {
        throw new InvalidArgumentException("{$where} ({$key}) needs a numeric minimum.");
      }
    }

    return new self(
      $key,
      $label,
      $kind,
      (bool) ($declaration['required'] ?? false),
      $reference,
      array_values($options),
      $fields,
      $minimum,
      trim(strval($declaration['description'] ?? '')),
    );
  }

  /**
   * Reads a list of field declarations, refusing repeated keys.
   *
   * @param mixed $declarations The declared fields.
   * @param string $where Where the list is, for messages.
   * @param bool $allowsList Whether the fields may be lists.
   * @return list<self>
   * @throws InvalidArgumentException When a declaration is malformed.
   */
  public static function readFields(mixed $declarations, string $where, bool $allowsList = true): array
  {
    if ($declarations === null) {
      return [];
    }

    if (! is_array($declarations) || ! array_is_list($declarations)) {
      throw new InvalidArgumentException("{$where} must be a list.");
    }

    $fields = [];

    foreach ($declarations as $index => $declaration) {
      $field = self::fromArray($declaration, sprintf('%s[%d]', $where, $index + 1), $allowsList);

      foreach ($fields as $declared) {
        if ($declared->key === $field->key
          || str_starts_with($field->key, "{$declared->key}.")
          || str_starts_with($declared->key, "{$field->key}.")
        ) {
          throw new InvalidArgumentException("{$where} declares \"{$field->key}\" over \"{$declared->key}\".");
        }
      }

      $fields[] = $field;
    }

    return $fields;
  }

  /**
   * Finds what is wrong with this field's value in an authored command.
   *
   * @param array<string, mixed> $command The command, or a list entry.
   * @param string $prefix The path of the entry, for messages.
   * @return list<string> The problems; empty when the value is acceptable.
   */
  public function findProblems(array $command, string $prefix = ''): array
  {
    $path = $prefix . $this->key;
    $value = self::readPath($command, $this->key);

    if ($value === null || (is_string($value) && trim($value) === '')) {
      return $this->required ? [sprintf('"%s" is required.', $path)] : [];
    }

    return match ($this->kind) {
      ScriptCommandFieldKind::TEXT, ScriptCommandFieldKind::REFERENCE => is_string($value)
        ? []
        : [sprintf('"%s" must be text.', $path)],
      ScriptCommandFieldKind::INTEGER => is_int($value)
        ? $this->findMinimumProblems($value, $path)
        : [sprintf('"%s" must be a whole number.', $path)],
      ScriptCommandFieldKind::NUMBER => is_int($value) || is_float($value)
        ? $this->findMinimumProblems($value, $path)
        : [sprintf('"%s" must be a number.', $path)],
      ScriptCommandFieldKind::BOOLEAN => is_bool($value)
        ? []
        : [sprintf('"%s" must be true or false.', $path)],
      ScriptCommandFieldKind::OPTION => in_array($value, $this->options, true)
        ? []
        : [sprintf('"%s" must be one of %s.', $path, implode(', ', $this->options))],
      ScriptCommandFieldKind::POSITION => is_array($value) && is_int($value['x'] ?? null) && is_int($value['y'] ?? null)
        ? []
        : [sprintf('"%s" must give whole-number x and y.', $path)],
      ScriptCommandFieldKind::LIST => $this->findListProblems($value, $path),
    };
  }

  /**
   * Reads a value at a dotted path.
   *
   * @param array<string, mixed> $data The data.
   * @param string $path The key or dotted path.
   * @return mixed The value, or null when any part of the path is absent.
   */
  public static function readPath(array $data, string $path): mixed
  {
    $value = $data;

    foreach (explode('.', $path) as $key) {
      if (! is_array($value) || ! array_key_exists($key, $value)) {
        return null;
      }

      $value = $value[$key];
    }

    return $value;
  }

  /** @return list<string> */
  private function findMinimumProblems(int|float $value, string $path): array
  {
    return $this->minimum !== null && $value < $this->minimum
      ? [sprintf('"%s" must be at least %s.', $path, $this->minimum)]
      : [];
  }

  /** @return list<string> */
  private function findListProblems(mixed $value, string $path): array
  {
    if (! is_array($value) || ! array_is_list($value)) {
      return [sprintf('"%s" must be a list.', $path)];
    }

    if ($value === []) {
      return $this->required ? [sprintf('"%s" needs at least one entry.', $path)] : [];
    }

    $problems = [];

    foreach ($value as $index => $entry) {
      $entryPath = sprintf('%s[%d]', $path, $index + 1);

      if (! is_array($entry) || array_is_list($entry)) {
        $problems[] = sprintf('"%s" must be a keyed entry.', $entryPath);
        continue;
      }

      foreach ($this->fields as $field) {
        $problems = [...$problems, ...$field->findProblems($entry, "{$entryPath}.")];
      }
    }

    return $problems;
  }
}
