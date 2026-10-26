<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Launch;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/**
 * The game's application icon, which a graphical renderer shows in place of
 * its own (the macOS Dock, for example). A project names it in ichiloto.json
 * as an asset-root-relative PNG or ICNS file; the terminal has no icon.
 */
final class ApplicationIcon
{
  public const string KEY = 'icon';
  public const array FORMATS = ['png', 'icns'];

  /**
   * The declared icon when it is a readable PNG or ICNS inside the asset root,
   * otherwise null. An unusable icon keeps the renderer's own and is reported.
   */
  public static function getAssetPath(mixed $declared, string $assetRoot): ?string
  {
    if ($declared === null) {
      return null;
    }
    try {
      self::assertAssetPath($declared);
      $root = realpath($assetRoot);
      $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $declared);
      if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
        || !is_file($path) || !is_readable($path)) {
        throw new InvalidArgumentException("{$declared} is not a readable file inside the assets.");
      }
      return $declared;
    } catch (InvalidArgumentException $error) {
      Debug::warn('ichiloto.json icon is unusable; keeping the renderer icon: ' . $error->getMessage());
      return null;
    }
  }

  /** @throws InvalidArgumentException */
  public static function assertAssetPath(mixed $asset): void
  {
    if (!is_string($asset) || !in_array(strtolower(pathinfo($asset, PATHINFO_EXTENSION)), self::FORMATS, true)) {
      throw new InvalidArgumentException('The application icon must be a PNG or ICNS path relative to the assets.');
    }
    SpriteValidation::validateAssetPath($asset);
  }
}
