<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContextProviderInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\UI\Interfaces\ModalPresentationProviderInterface;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;
use Throwable;

/** Session-local optional artwork; the existing scene UI owns modal registration and cleanup. */
final class DialogueScenePresentation
{
    private bool $loaded = false;
    private ?DialoguePresentationCatalog $catalogue = null;
    private array $diagnostics = [];

    public function __construct(private readonly string $assetRoot) {}

    public function getPageLayout(string $speaker, DialogueContext $context, string $help, int $width,
        bool $interactive = true): ?DialoguePageLayout
    {
        try {
            $this->loadCatalogue();
            return $this->catalogue?->theme === null ? null
                : new DialoguePageLayout($this->catalogue, $speaker, $context, $help, $width, $interactive);
        } catch (Throwable $error) {
            $this->report('Dialogue layout degraded to terminal: ' . $error->getMessage());
            return null;
        }
    }

    private function loadCatalogue(): void
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->catalogue = DialoguePresentationCatalog::load($this->assetRoot);
        }
    }

    /** Timed hosts reuse dialogue artwork without registering an input-owning modal. */
    public function composeSnapshot(DialogueSnapshot $line, int $width, int $height): ?PresentationCanvas
    {
        try {
            $this->loadCatalogue();
            if ($this->catalogue?->theme === null) { return null; }
            $canvas = DialogueCanvasPresentation::compose($line, $this->catalogue, $width, $height);
            return MenuCanvas::overlay(new PresentationCanvas($width, $height), $canvas, $this->catalogue->theme);
        } catch (Throwable $error) {
            $this->report('Dialogue presentation degraded to terminal: ' . $error->getMessage());
            return null;
        }
    }

    public function compose(SceneInterface $scene, ?PresentationCanvas $base, int $width, int $height,
        bool $supportsOverlay, bool $supportsCanvas = true, bool $supportsImageTone = true): ?DialogueSurface
    {
        $fallback = null;
        try {
            if (!file_exists($this->assetRoot . '/' . DialoguePresentationCatalog::FILE)) { return null; }
            $context = $scene instanceof ScenePresentationContextProviderInterface ? $scene->getPresentationContext() : null;
            $active = $context !== null ? $context->getActivePresentations() : $scene->getUI()->getActivePresentations();
            if ($active === []) { return null; }
            $embedded = $base?->presentationOwners ?? [];
            // Failed optional graphics must not conceal an unconverted modal behind an opaque scene.
            if ($base !== null && array_any($active, static fn($owner) =>
                !in_array('ui:' . spl_object_id($owner), $embedded, true))) {
                $fallback = new DialogueSurface(null, false);
            }
            if (!$supportsCanvas) {
                $this->report('Renderer lacks graphical dialogue capabilities; terminal presentation retained.');
                return $fallback;
            }
            $this->loadCatalogue();
            $catalogue = $this->catalogue;
            if ($catalogue?->theme === null) { return $fallback; }
            $canvas = $base;
            $excluded = [];
            $overlay = $base === null;
            $changed = false;
            $dialogues = 0;
            // UIManager reports the input-owning top layer first; painting proceeds bottom to top.
            foreach (array_reverse($active) as $owner) {
              $layerId = 'ui:' . spl_object_id($owner);
              if (in_array($layerId, $embedded, true)) { $excluded[] = $layerId; continue; }
              if ($owner instanceof DialoguePresentationProviderInterface) {
                $line = $owner->getDialogueSnapshot();
                $skit = $line->context->skitId !== null;
                if ($canvas === null && !$skit && !$supportsOverlay) {
                    $this->report('Renderer lacks canvas_overlay; field dialogue retains terminal presentation.');
                    return null;
                }
                if ($skit && !isset($catalogue->skits[$line->context->skitId]['background'])) {
                    $this->report('Skit ' . $line->context->skitId . ' has no contextual background binding; using theme backing.');
                }
                if ($skit && !$supportsImageTone && $catalogue->skitStage->inactiveBrightness !== 1.0) {
                    $this->report('Renderer lacks canvas_image_tone; skit speaker emphasis retains scale without dimming.');
                }
                $part = DialogueCanvasPresentation::compose($line, $catalogue,
                    $base?->width ?? $width, $base?->height ?? $height, $supportsImageTone);
                if ($skit) {
                    $canvas = $part;
                    $overlay = false;
                } else {
                    $part = MenuCanvas::overlay(new PresentationCanvas($part->width, $part->height), $part, $catalogue->theme);
                    $canvas = PresentationCanvas::composeOverlay($canvas, $part,
                        $dialogues === 0 ? '' : 'dialogue-' . spl_object_id($owner) . '-');
                }
                $dialogues++;
              } elseif ($owner instanceof ModalPresentationProviderInterface) {
                if ($canvas === null && !$supportsOverlay) { return $fallback; }
                $modal = $owner->getModalPresentation();
                if ($modal === null) { throw new RuntimeException('Active modal has no graphical snapshot.'); }
                $part = MenuModalPresentation::compose(new PresentationCanvas($base?->width ?? $width,
                    $base?->height ?? $height), $modal, $catalogue->theme, ownerLayerId: $layerId);
                $canvas = PresentationCanvas::composeOverlay($canvas, $part, 'modal-' . spl_object_id($owner) . '-');
              } else {
                throw new RuntimeException('Active presentation has no supported modal projection.');
              }
              $excluded[] = $layerId;
              $changed = true;
            }
            return $changed && $canvas !== null ? new DialogueSurface($canvas, $overlay, $excluded) : null;
        } catch (Throwable $error) {
            $this->report('Dialogue presentation degraded to terminal: ' . $error->getMessage());
            return $fallback;
        }
    }

    private function report(string $message): void
    {
        if (!isset($this->diagnostics[$message])) { Debug::warn($message); $this->diagnostics[$message] = true; }
    }
}
