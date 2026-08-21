<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveCampaignWebActivity extends Model
{
    protected $table = 'activecampaign_web_activities';

    protected $fillable = [
        'customer_id',
        'ac_contact_id',
        'path',
        'title',
        'label',
        'occurred_at',
        'source',
        'raw_reference_type',
        'raw_reference_id',
        'activity_hash',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'ac_contact_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
