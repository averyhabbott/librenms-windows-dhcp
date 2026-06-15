<?php

namespace AveryAbbott\WindowsDhcp\Models;

use App\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Windows DHCP scope, collected from a PowerShell Universal endpoint.
 * One row per scope per DHCP server device.
 *
 * @property int $dhcp_scope_id
 * @property int $device_id
 * @property string $scope_id
 */
class DhcpScope extends Model
{
    protected $table = 'dhcp_scopes';
    protected $primaryKey = 'dhcp_scope_id';
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'scope_id',
        'name',
        'state',
        'addresses_total',
        'addresses_in_use',
        'addresses_free',
        'addresses_reserved',
        'pending_offers',
        'bad_addresses',
        'percent_in_use',
    ];

    protected function casts(): array
    {
        return [
            'addresses_total' => 'integer',
            'addresses_in_use' => 'integer',
            'addresses_free' => 'integer',
            'addresses_reserved' => 'integer',
            'pending_offers' => 'integer',
            'bad_addresses' => 'integer',
            'percent_in_use' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
