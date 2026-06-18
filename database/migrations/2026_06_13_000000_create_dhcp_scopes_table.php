<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dhcp_scopes')) {
            return;
        }

        Schema::create('dhcp_scopes', function (Blueprint $table) {
            $table->increments('dhcp_scope_id');
            $table->unsignedInteger('device_id')->index();
            $table->string('scope_id', 64);                 // network address, e.g. "10.0.1.0"
            $table->string('name', 255)->nullable();
            $table->string('state', 16)->nullable();        // active / inactive / ...
            $table->unsignedInteger('addresses_total')->default(0);
            $table->unsignedInteger('addresses_in_use')->default(0);
            $table->unsignedInteger('addresses_free')->default(0);
            $table->unsignedInteger('addresses_reserved')->default(0);
            // Active/inactive split of addresses_reserved. Populated only when the
            // "Monitor reservation states" setting is on (opt-in; needs a per-scope
            // lease enumeration). 0 when monitoring is off. See windows-dhcp:poll.
            $table->unsignedInteger('reservations_active')->default(0);
            $table->unsignedInteger('reservations_inactive')->default(0);
            $table->unsignedInteger('pending_offers')->default(0);
            // Count of addresses the server flagged as conflicting (BAD_ADDRESS /
            // declined). A real operational signal — see windows-dhcp:poll.
            $table->unsignedInteger('bad_addresses')->default(0);
            $table->decimal('percent_in_use', 5, 2)->default(0);
            $table->unique(['device_id', 'scope_id']);

            // Remove a device's scopes when the device is deleted from LibreNMS.
            $table->foreign('device_id')
                ->references('device_id')->on('devices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_scopes');
    }
};
