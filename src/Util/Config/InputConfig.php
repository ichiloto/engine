<?php

namespace Ichiloto\Engine\Util\Config;

use Assegai\Util\Path;
use Exception;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Util\Debug;
use UnitEnum;

/**
 * Class InputConfig. Represents the input configuration.
 *
 * @package Ichiloto\Engine\Util\Config
 */
class InputConfig extends AbstractConfig
{
  /**
   * Returns the configuration array.
   *
   * @return array The configuration array.
   */
  public function all(): array
  {
    return $this->config;
  }

  /**
   * @inheritDoc
   * @throws Exception
   */
  protected function load(): array
  {
    $filename = $this->getFilename();

    $content = require($filename);

    if (false === $content) {
      throw new Exception("Could not read file: $filename");
    }

    return $content;
  }

  /**
   * @inheritDoc
   * @throws Exception If the configuration could not be written to the file.
   * @noinspection DuplicatedCode
   */
  public function persist(): void
  {
    $filename = $this->getFilename();
    $content = "<?php\n\nreturn " . $this->exportPhpValue($this->config) . ";\n";

    $bytes = file_put_contents($filename, $content);

    if (false === $bytes) {
      throw new Exception("Could not write to file: $filename");
    }

    if ($bytes !== strlen($content)) {
      throw new Exception("Could not write all bytes to file: $filename");
    }

    $basename = basename($filename);
    $humanReadableBytes = number_format($bytes);

    Debug::info("Update $basename ($humanReadableBytes)");
  }

  /**
   * Exports a PHP value using the repository's short-array style. Enum cases —
   * the KeyCode instances stored under each binding's "keys" — are exported as
   * fully-qualified case references so load() can require the file back.
   *
   * @param mixed $value The value to export.
   * @param int $indentLevel The current indentation depth.
   * @return string The exported PHP code.
   */
  protected function exportPhpValue(mixed $value, int $indentLevel = 0): string
  {
    if ($value instanceof UnitEnum) {
      return '\\' . $value::class . '::' . $value->name;
    }

    if (! is_array($value)) {
      return var_export($value, true);
    }

    if ($value === []) {
      return '[]';
    }

    $indent = str_repeat('  ', $indentLevel);
    $nextIndent = str_repeat('  ', $indentLevel + 1);
    $isList = array_is_list($value);
    $lines = ['['];

    foreach ($value as $key => $item) {
      $exportedItem = $this->exportPhpValue($item, $indentLevel + 1);

      if ($isList) {
        $lines[] = "{$nextIndent}{$exportedItem},";
        continue;
      }

      $exportedKey = var_export($key, true);
      $lines[] = "{$nextIndent}{$exportedKey} => {$exportedItem},";
    }

    $lines[] = "{$indent}]";

    return implode("\n", $lines);
  }

  /**
   * Returns the filename of the input configuration file.
   *
   * @return string The filename of the input configuration file.
   * @throws NotFoundException
   */
  protected function getFilename(): string
  {
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'input.php');

    if (!file_exists($filename)) {
      throw new NotFoundException($filename);
    }
    return $filename;
  }
}