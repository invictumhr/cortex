<?php

namespace App\Services\Ai\Adapters;

class XAiAdapter extends OpenAiCompatibleAdapter
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.x.ai';
    }

    /**
     * Every current xAI text model is a reasoning model whose
     * reasoning_effort DEFAULTS TO HIGH — left unset, each short boardroom
     * contribution pays for maximal hidden reasoning tokens (billed as
     * output). "low" is valid on grok-4.3 and grok-4.5 alike and cuts that
     * cost substantially without hurting 2-6 sentence debate turns.
     */
    protected function extraPayload(): array
    {
        $effort = trim((string) config('cortex.xai_reasoning_effort', 'low'));

        return $effort === '' ? [] : ['reasoning_effort' => $effort];
    }
}
