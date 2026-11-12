<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Field\Enumerations\BedPosture;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use InvalidArgumentException;

/** An NPC's bed binding and character-owned poses; its ordinary x/y is the standing exit. */
final readonly class NpcBedDefinition
{
    public const array FIELDS = ['object', 'attachment', 'posture', 'poses2d', 'transitions2d', 'terminal'];
    private function __construct(public string $objectId, public string $attachmentId, public BedPosture $posture,
        public array $poses, public array $transitions, public string $terminal) {}

    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || array_diff(array_keys($data), self::FIELDS) !== []) {
            throw new InvalidArgumentException('NPC bed accepts object, attachment, posture, poses2d, transitions2d and terminal.');
        }
        WorldObjectResource::assertId($data['object'] ?? null);
        WorldObjectAttachment::assertId($data['attachment'] ?? null);
        $posture = $data['posture'] ?? BedPosture::LYING->value;
        if (!is_string($posture) || ($posture = BedPosture::tryFrom($posture)) === null) {
            throw new InvalidArgumentException('Bed posture must be lying, sitting or standing.');
        }
        $terminal = $data['terminal'] ?? '▣';
        if (!is_string($terminal) || TerminalText::stripAnsi($terminal) !== $terminal || TerminalText::displayWidth($terminal) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $terminal)) {
            throw new InvalidArgumentException('An occupied bed needs one printable Terminal cell.');
        }
        $readRoles = static function (mixed $roles, bool $transition): array {
            if (!is_array($roles)) { throw new InvalidArgumentException('Bed pose roles must be keyed by posture.'); }
            $result = [];
            foreach ($roles as $key => $data) {
                if (!is_string($key) || BedPosture::tryFrom($key) === null || (!$transition && $key === 'standing') || !is_array($data)) {
                    throw new InvalidArgumentException('Bed poses select lying/sitting; transitions select a target posture.');
                }
                $role = FieldSpriteRole::readDefinition($data);
                if ($transition && $role instanceof FieldPoseAnimation && $role->loop) {
                    throw new InvalidArgumentException('Bed transition clips do not loop.');
                }
                $result[$key] = $role;
            }
            return $result;
        };
        return new self($data['object'], $data['attachment'], $posture,
            $readRoles($data['poses2d'] ?? [], false), $readRoles($data['transitions2d'] ?? [], true), $terminal);
    }

    /** Resolve gameplay placement from the named logical attachment, independently of image pixels. */
    public function resolveObject(array $objects): array
    {
        $object = array_find($objects, fn(WorldObjectDefinition $object): bool => $object->id === $this->objectId)
            ?? throw new InvalidArgumentException("NPC bed object '{$this->objectId}' is missing.");
        $attachment = $object->resource?->findAttachment($this->attachmentId)
            ?? throw new InvalidArgumentException("NPC bed '{$this->objectId}' needs attachment '{$this->attachmentId}'.");
        return [$object, $attachment];
    }
}
