<?php

namespace App\Enums;

/** §15.3 : suivi d'état de la tâche IA. */
enum AiJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Done, self::Failed], true);
    }
}
