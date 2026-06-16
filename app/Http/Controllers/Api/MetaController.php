<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\LogsApiUsage;
use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ApiToken;
use App\Models\ApiTokenUsage;
use App\Models\Persona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only roster endpoints. Until these existed, an API client had no way
 * to discover which persona slugs or model strings POST /discuss accepts —
 * the lists lived only in the human-facing docs page. Any valid token may
 * read them (no scope needed); nothing here is sensitive or billable.
 */
class MetaController extends Controller
{
    use LogsApiUsage;

    /**
     * GET /api/v1/personas
     *
     * The fixed debate roster (ephemeral architect-generated personas are
     * excluded — they exist only inside their own chat). Slugs feed the
     * `panel` / `personas` fields of POST /discuss.
     */
    public function personas(Request $request): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);

        $personas = Persona::query()
            ->where('is_ephemeral', false)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['slug', 'name', 'title', 'expertise_areas', 'is_scribe', 'is_chair'])
            ->map(fn (Persona $p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'title' => $p->title,
                'expertise_areas' => $p->expertise_areas,
                'role' => $p->is_scribe ? 'scribe' : ($p->is_chair ? 'chair' : 'debater'),
            ]);

        return $this->logUsage($token, 'GET /api/v1/personas', ApiTokenUsage::STATUS_OK, $started,
            response()->json(['personas' => $personas]),
        );
    }

    /**
     * GET /api/v1/models
     *
     * Active models whose provider is usable (has an API key). Model strings
     * feed the `models` / `panel[].model` fields of POST /discuss. Provider
     * cost rates are intentionally not exposed — users are billed at their
     * margin tier, not raw provider prices.
     */
    public function models(Request $request): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);

        $models = AiModel::query()
            ->where('is_active', true)
            ->whereHas('provider', fn ($q) => $q->where('is_active', true)->whereNotNull('api_key'))
            ->with('provider:id,name,slug')
            ->orderBy('model_string')
            ->get(['id', 'ai_provider_id', 'name', 'model_string', 'supports_vision', 'max_context_tokens'])
            ->map(fn (AiModel $m) => [
                'model_string' => $m->model_string,
                'name' => $m->name,
                'provider' => $m->provider?->slug,
                'supports_vision' => (bool) $m->supports_vision,
                'max_context_tokens' => (int) $m->max_context_tokens,
            ]);

        return $this->logUsage($token, 'GET /api/v1/models', ApiTokenUsage::STATUS_OK, $started,
            response()->json(['models' => $models]),
        );
    }

    private function token(Request $request): ApiToken
    {
        $token = $request->attributes->get('api_token');
        \assert($token instanceof ApiToken);

        return $token;
    }
}
