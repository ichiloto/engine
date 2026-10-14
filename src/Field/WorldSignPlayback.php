<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Rendering\Presentation\Text\ProjectTextCatalog;
use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationRequest;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationState;
use Ichiloto\Engine\Rendering\Text\TextPreparationProviderInterface;
use Ichiloto\Engine\Util\Debug;
use LogicException;
use OverflowException;
use Throwable;

/** Nonblocking map-owned label preparation; the transport owner alone polls events. */
final class WorldSignPlayback
{
  private ?TextPreparationProviderInterface $provider = null;
  private ?object $session = null;
  private ?RendererTextPreparationRequest $request = null;
  private ?TextSpec $spec = null;
  private ?array $intent = null;
  private int $attempts = 0;
  private bool $failed = false;
  private ?string $diagnostic = null;

  public function __construct(private readonly WorldObjectDefinition $definition, private readonly string $assetRoot) {}

  /** Null is pending or explicitly diagnosed unavailable art; no substitute board/font is invented. */
  public function getPresentation(?TextPreparationProviderInterface $provider): ?array
  {
    if ($provider === null) { $this->release(); return null; }
    $resolved = false;
    try {
      $session = $provider->getTextPreparationSession();
      if ($session === null) { $this->release(); return null; }
      $style = $this->definition->resource?->sign ?? throw new LogicException('A sign requires its resource style.');
      $board = $style->getBoard($this->assetRoot);
      $role = (ProjectTextCatalog::load($this->assetRoot) ?? throw new LogicException('Project text roles are not declared.'))
        ->getRole($style->fontRole);
      $text = $this->definition->label?->getText() ?? throw new LogicException('A sign requires its localized label.');
      $spec = $role->createSpec($text, MessageCatalog::locale() ?: 'und');
      $fonts = [];
      foreach ($spec->fonts as $font) {
        $path = $this->assetRoot . '/' . $font->asset;
        clearstatcache(true, $path);
        $stat = is_file($path) ? @stat($path) : false;
        $fonts[] = $stat === false ? null : array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
      }
      // File facts invalidate live preparation only; neither filenames nor these facts become identity/save data.
      $intent = [$spec->toArray(), $fonts, $board->source->toArray(), $board->left, $board->right];
      $resolved = true;
      if ($this->provider !== $provider || $this->session !== $session || $this->intent !== $intent
        || $this->request?->state === RendererTextPreparationState::RETIRED) {
        $this->release();
        $this->provider = $provider;
        $this->session = $session;
        $this->intent = $intent;
        $this->spec = $spec;
        $this->failed = false;
        $this->attempts = 0;
      }
      if ($this->failed) { return null; }
      if ($this->request === null) {
        try { $this->request = $provider->requestTextPreparation($this->spec); }
        catch (OverflowException $error) {
          // Another consumer owns the bounded slots; retry on a later frame after its cleanup.
          $this->reportFailure($error);
          return null;
        }
      }
      if ($this->request === null || ($measurement = $this->request->getResult()) === null) { return null; }
      $ratio = $style->getFitRatio($board, $measurement);
      if ($ratio < 1.0) {
        $size = $this->spec->fontSize * $ratio;
        if (++$this->attempts > 8 || $size < 1.0 || $size >= $this->spec->fontSize) {
          throw new LogicException('Localized label cannot fit its declared board within supported font limits.');
        }
        $provider->releaseTextPreparation($this->request);
        $this->request = null;
        $this->spec = new TextSpec($text, $spec->fonts, $size, $spec->locale, $spec->foreground, $spec->glyphEffects);
        return null;
      }
      $this->diagnostic = null;
      return $style->composeCanvas($board, $measurement, $this->definition->pivot);
    } catch (Throwable $error) {
      if (!$resolved) { $this->release(); }
      else { $this->releaseRequest(); }
      $this->failed = true;
      $this->reportFailure($error);
      return null;
    }
  }

  /** Map, preview-host and session changes retire only tickets owned by this sign. */
  public function release(): void
  {
    $this->releaseRequest();
    $this->provider = null;
    $this->session = null;
    $this->intent = null;
    $this->spec = null;
    $this->failed = false;
  }

  private function releaseRequest(): void
  {
    $request = $this->request;
    $provider = $this->provider;
    $this->request = null;
    if ($provider !== null && $request !== null
      && in_array($request->state, [RendererTextPreparationState::PENDING, RendererTextPreparationState::PREPARED], true)) {
      try { $provider->releaseTextPreparation($request); }
      catch (Throwable $error) { $this->reportFailure($error); }
    }
  }

  private function reportFailure(Throwable $error): void
  {
    if ($this->diagnostic !== $error->getMessage()) {
      $this->diagnostic = $error->getMessage();
      Debug::warn("World sign '{$this->definition->id}' is unavailable; retaining Terminal presentation: " . $error->getMessage());
    }
  }
}
