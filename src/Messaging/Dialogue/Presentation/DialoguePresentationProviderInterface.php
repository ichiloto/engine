<?php

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

interface DialoguePresentationProviderInterface
{
    public function getDialogueSnapshot(): DialogueSnapshot;
}
