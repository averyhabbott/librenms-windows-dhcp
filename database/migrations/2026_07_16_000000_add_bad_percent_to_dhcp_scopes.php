<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhcp_scopes', function (Blueprint $table) {
            // Percentage of addresses that are in conflict (bad/declined), 0–100.
            // Computed from bad_addresses / addresses_total * 100, same as percent_in_use.
            // Precomputed so it's usable in alert rules (the builder doesn't support
            // field/field arithmetic). Only meaningful when "Monitor declined addresses"
            // is on; stays 0 otherwise.
            $table->decimal('bad_percent', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('dhcp_scopes', function (Blueprint $table) {
            $table->dropColumn('bad_percent');
        });
    }
};
