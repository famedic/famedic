<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Registro append-only de una aprobación comercial B02 (RC2).
 * Solo lo crea `akubica:b02-commercial-approval --apply`.
 */
class LaboratoryPackageCommercialApproval extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'before_state' => 'array',
            'after_state' => 'array',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('LaboratoryPackageCommercialApproval is append-only.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('LaboratoryPackageCommercialApproval is append-only.');
    }
}
