<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Shows the project's credits.
 *
 * Credits are authored in `assets/Data/credits.php` as a list of sections:
 *
 * ```php
 * return [
 *   ['title' => 'Ichiloto', 'lines' => ['A terminal JRPG engine']],
 *   ['title' => 'Design', 'lines' => ['Andrew Masiye']],
 * ];
 * ```
 *
 * Each section is shown as a page; the project name and version are used
 * when no file is authored, so the entry is never empty.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class ShowCreditsCommand extends MenuItem
{
  /**
   * ShowCreditsCommand constructor.
   *
   * @param MenuInterface $menu The menu that this command belongs to.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct(
      $menu,
      get_message('title.credits', 'Credits'),
      get_message('title.credits_description', 'See who made this game.')
    );
  }

  /**
   * Determines whether the project authors credits.
   *
   * @return bool True when a credits file exists.
   */
  public static function projectHasCredits(): bool
  {
    return file_exists(self::getCreditsPath());
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    foreach ($this->loadSections() as $section) {
      $lines = array_values(array_filter((array) ($section['lines'] ?? []), 'is_string'));

      if (empty($lines)) {
        continue;
      }

      show_text(implode("\n", $lines), strval($section['title'] ?? ''), charactersPerSecond: dialogue_speed());
    }

    return self::SUCCESS;
  }

  /**
   * Returns the credits file path.
   *
   * @return string The absolute path.
   */
  protected static function getCreditsPath(): string
  {
    return Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'credits.php');
  }

  /**
   * Loads the authored credit sections, falling back to project metadata.
   *
   * @return array<int, array<string, mixed>> The sections.
   */
  protected function loadSections(): array
  {
    $filename = self::getCreditsPath();

    if (file_exists($filename)) {
      try {
        $sections = require $filename;

        if (is_array($sections) && ! empty($sections)) {
          return array_values(array_filter($sections, 'is_array'));
        }
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Could not read credits: %s', $exception->getMessage()));
      }
    }

    return [[
      'title' => strval(config(ProjectConfig::class, 'name', 'Credits')),
      'lines' => ['Made with the Ichiloto engine.'],
    ]];
  }
}
