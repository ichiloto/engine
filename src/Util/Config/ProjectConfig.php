<?php

namespace Ichiloto\Engine\Util\Config;

use Assegai\Util\Path;
use Exception;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Util\Debug;

class ProjectConfig extends AbstractConfig
{
  /**
   * @inheritDoc
   * @throws NotFoundException
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
    $content = "<?php\n\nreturn " . var_export($this->config, true) . ";\n";

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
   * Returns the filename of the configuration file.
   *
   * @return string The filename of the configuration file.
   * @throws NotFoundException
   */
  protected function getFilename(): string
  {
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'config.php');

    if (!file_exists($filename)) {
      throw new NotFoundException($filename);
    }
    return $filename;
  }
}
