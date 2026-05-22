<?php

namespace App\Filament\User\Pages;

use App\Models\ApiToken;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Self-serve REST API reference for /user. Sits in the "Developers" nav group
 * below the API Tokens resource (navigationSort=2 vs ApiToken=1) so a user
 * who just issued a token immediately sees how to use it.
 *
 * Documentation lives in the Blade view to keep code minimal — the page just
 * passes the URL base and the live scope list (pulled from the model) to
 * keep copy-pasteable examples honest if scopes ever change.
 */
class ApiDocs extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static string|\UnitEnum|null $navigationGroup = 'Developers';

    protected static ?string $navigationLabel = 'API Documentation';

    protected static ?string $title = 'REST API Documentation';

    /** Below API Tokens (sort=1) so the natural read order is "make token → see how to use it". */
    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.user.pages.api-docs';

    /**
     * Pulled into the Blade view so the curl examples in the docs match the
     * actual app URL — no risk of a stale "http://cortex.test" in production.
     */
    public function getBaseUrl(): string
    {
        return rtrim(config('app.url'), '/');
    }

    /** Live scope list from the model — single source of truth. */
    public function getScopes(): array
    {
        return ApiToken::availableScopes();
    }
}
