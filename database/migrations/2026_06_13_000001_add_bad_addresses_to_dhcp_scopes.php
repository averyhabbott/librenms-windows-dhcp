<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dhcp_scopes') || Schema::hasColumn('dhcp_scopes', 'bad_addresses')) {
            return;
        }

        Schema::table('dhcp_scopes', function (Blueprint $table): void {
            // Count of addresses the server flagged as conflicting (BAD_ADDRESS /
            // declined). A real operational signal — see windows-dhcp:poll.
            $table->unsignedInteger('bad_addresses')->default(0)->after('pending_offers');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('dhcp_scopes', 'bad_addresses')) {
            Schema::table('dhcp_scopes', function (Blueprint $table): void {
                $table->dropColumn('bad_addresses');
            });
        }
    }
};
