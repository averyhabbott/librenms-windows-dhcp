<?php

declare(strict_types=1);

use AveryAbbott\WindowsDhcp\Http\GraphController;
use AveryAbbott\WindowsDhcp\Http\ScopeDetailController;
use AveryAbbott\WindowsDhcp\Http\ScopeTableController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    // Server-side paginated/searchable table data for the scopes browser page.
    Route::match(['get', 'post'], 'plugin/windows-dhcp/scopes/table', ScopeTableController::class)
        ->name('windows-dhcp.scopes-table');

    // Full-page per-scope detail view (timeframe strip + date range + large graph).
    Route::get('plugin/windows-dhcp/scope/{scope}', ScopeDetailController::class)
        ->whereNumber('scope')
        ->name('windows-dhcp.scope');

    // On-demand per-scope utilization graph (RRD -> PNG).
    Route::get('plugin/windows-dhcp/scope/{scope}/graph', GraphController::class)
        ->whereNumber('scope')
        ->name('windows-dhcp.scope-graph');
});
