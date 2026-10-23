<?php

namespace App\Http\Controllers\Parties;

use App\Models\Owner;

/** Owners hold capital and drawings. They are NOT customers or suppliers. Money in/out goes through Owner Capital / Drawings. */
class OwnerController extends PartyController
{
    protected function model(): string { return Owner::class; }
    protected function type(): string { return 'owner'; }
    protected function prefix(): string { return 'owners'; }
    protected function historyRelations(): array { return ['transactions']; }

    protected function labels(): array
    {
        return ['singular' => 'Owner', 'plural' => 'Owners', 'balance' => 'Net capital', 'positive' => 'Capital', 'negative' => 'Overdrawn', 'sides' => []];
    }

    protected function columns(): array { return ['Name' => 'name', 'Phone' => 'phone', 'Email' => 'email', 'Share %' => 'share_percent']; }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'cols' => 6, 'required' => true],
            ['name' => 'share_percent', 'label' => 'Share %', 'type' => 'number', 'cols' => 3],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'cols' => 3],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'text', 'cols' => 6],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'cols' => 6],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'cols' => 12],
        ];
    }

    protected function rules(?int $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
