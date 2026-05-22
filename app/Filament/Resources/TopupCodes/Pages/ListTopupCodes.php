<?php

namespace App\Filament\Resources\TopupCodes\Pages;

use App\Filament\Resources\TopupCodes\TopupCodeResource;
use Filament\Resources\Pages\ListRecords;

class ListTopupCodes extends ListRecords
{
    protected static string $resource = TopupCodeResource::class;

    /**
     * Accept a `?batch=N` query parameter as a deep-link target from the
     * batch resource's "View codes" action. We seed the table's batch_id
     * filter state on mount so the filter pill renders correctly AND the
     * query is constrained — relying on Filament's tableFilters[..] URL
     * serialisation is brittle across point releases, so we bridge through
     * an explicit, named parameter instead.
     */
    public function mount(): void
    {
        parent::mount();

        $batch = request('batch');
        if ($batch !== null && $batch !== '') {
            $this->tableFilters['batch_id']['value'] = (int) $batch;
        }
    }
}
