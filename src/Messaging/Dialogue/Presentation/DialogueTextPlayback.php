<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasLabel;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationFallbackReport;
use Ichiloto\Engine\Rendering\Presentation\Text\ProjectTextCatalog;
use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationRequest;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationState;
use Ichiloto\Engine\Rendering\Text\TextPreparationProviderInterface;
use Ichiloto\Engine\UI\Presentation\MenuTextWrap;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use OverflowException;
use RuntimeException;
use Throwable;

/** One measured layout per active dialogue owner and text role. The host pumps the broker. */
final class DialogueTextPlayback
{
    private ?TextPreparationProviderInterface $provider = null;
    private ?object $session = null;
    private ?ProjectTextCatalog $catalogue = null;
    private bool $catalogueLoaded = false;
    private readonly PresentationFallbackReport $fallbacks;

    /** @var array<string, array{spec: TextSpec, request: ?RendererTextPreparationRequest, failed: bool}> */
    private array $layouts = [];
    /** @var array<string, true> */
    private array $visible = [];

    public function __construct(private readonly string $assetRoot)
    {
        $this->fallbacks = new PresentationFallbackReport();
    }

    public function beginFrame(?TextPreparationProviderInterface $provider): void
    {
        $session = $provider?->getTextPreparationSession();
        if ($this->provider !== $provider || $this->session !== $session) {
            foreach (array_keys($this->layouts) as $key) { $this->release($key); }
            $this->provider = $provider;
            $this->session = $session;
        }
        $this->visible = [];
    }

    public function endFrame(): void
    {
        foreach (array_diff_key($this->layouts, $this->visible) as $key => $_) { $this->release($key); }
    }

    public function getLabel(string $ownerId, string $roleId, string $text, CanvasRectangle $bounds, bool $compact,
        HorizontalAlignment $alignment, int $layer, ?string $visibleText = null, ?float $lineHeight = null): ?CanvasLabel
    {
        $provider = $this->provider;
        if ($provider === null || $this->session === null || $text === '') { return null; }
        $key = $ownerId . '/' . $roleId;
        try {
            if (!$this->catalogueLoaded) {
                $this->catalogueLoaded = true;
                $this->catalogue = ProjectTextCatalog::load($this->assetRoot);
            }
            $role = ($compact ? $this->catalogue?->findRole($roleId . '-compact') : null)
                ?? $this->catalogue?->findRole($roleId);
            if ($role === null) { return null; }
            $source = TerminalText::stripAnsi(MenuTextWrap::normalizeText($text));
            $prefix = $visibleText === null ? $source : TerminalText::stripAnsi(MenuTextWrap::normalizeText($visibleText));
            $spec = new TextSpec($source, $role->fonts, $role->fontSize, MessageCatalog::locale() ?: 'und',
                $role->foreground, $role->glyphEffects, wrapWidth: max(1, $bounds->width - 4), lineHeight: $lineHeight);
            $this->visible[$key] = true;
            if (!isset($this->layouts[$key]) || $this->layouts[$key]['spec'] != $spec) {
                $this->release($key);
                $this->layouts[$key] = ['spec' => $spec, 'request' => null, 'failed' => false];
            }
            if ($this->layouts[$key]['failed'] || !str_starts_with($source, $prefix)) { return null; }
            $request = $this->layouts[$key]['request'] ??= $provider->requestTextPreparation($spec);
            $measurement = $request?->getResult();
            if ($measurement === null) { return null; }
            $paint = $measurement->metrics->paintBounds;
            if ($paint->width > $bounds->width || $paint->height > $bounds->height) {
                throw new RuntimeException('Measured dialogue text exceeds its reading area.');
            }
            $inset = match ($alignment) {
                HorizontalAlignment::LEFT => 0.0,
                HorizontalAlignment::CENTER => ($bounds->width - $paint->width) / 2,
                HorizontalAlignment::RIGHT => $bounds->width - $paint->width,
            };
            $top = $visibleText === null ? ($bounds->height - $paint->height) / 2 : 0.0;
            return new CanvasLabel($roleId, $measurement, $bounds->x + $inset - $paint->x,
                $bounds->y + $top - $paint->y, $layer, $bounds,
                visibleBytes: $prefix === $source ? null : strlen($prefix));
        } catch (OverflowException) {
            // Other consumers can temporarily own all the broker slots.
            return null;
        } catch (Throwable $error) {
            if (isset($this->layouts[$key])) { $this->layouts[$key]['failed'] = true; }
            $this->fallbacks->report('Dialogue uses grid text for ' . $roleId . ': ' . $error->getMessage(), $error);
            return null;
        }
    }

    private function release(string $key): void
    {
        $request = $this->layouts[$key]['request'] ?? null;
        unset($this->layouts[$key]);
        if ($request !== null
            && in_array($request->state, [RendererTextPreparationState::PENDING, RendererTextPreparationState::PREPARED], true)) {
            try { $this->provider?->releaseTextPreparation($request); }
            catch (Throwable $error) {
                $this->fallbacks->report('Dialogue text cleanup failed: ' . $error->getMessage(), $error);
            }
        }
    }
}
