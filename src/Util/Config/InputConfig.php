<?php

namespace Ichiloto\Engine\Util\Config;

use Assegai\Util\Path;
use Exception;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Util\Debug;

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
   * @throws Exception If the JSON could not be encoded or written to the file.
   * @noinspection DuplicatedCode
   */
  public function persist(): void
  {
    $filename = $this->getFilename();

    $content = json_encode($this->config);

    if (false === $content) {
      throw new Exception("Could not encode JSON: " . json_last_error_msg());
    }

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