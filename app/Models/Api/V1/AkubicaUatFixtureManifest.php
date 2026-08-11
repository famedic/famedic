<?php

namespace App\Models\Api\V1;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use Illuminate\Database\Eloquent\Model;

class AkubicaUatFixtureManifest extends Model
{
    protected $table = 'akubica_uat_fixture_manifests';

    protected $guarded = [];

    public const ALLOWED_STATUSES = AkubicaUatFixtureContract::ALLOWED_STATUSES;

    public function canRecoverPreparing(): bool
    {
        return in_array($this->status, [
            AkubicaUatFixtureContract::STATUS_PREPARING,
            AkubicaUatFixtureContract::STATUS_FAILED,
        ], true)
            && $this->namespace === AkubicaUatFixtureContract::NAMESPACE
            && (int) $this->fixture_version === AkubicaUatFixtureContract::FIXTURE_VERSION
            && is_array($this->metadata);
    }

    public function markActive(array $metadata): void
    {
        if (! in_array($this->status, [
            AkubicaUatFixtureContract::STATUS_PREPARING,
            AkubicaUatFixtureContract::STATUS_FAILED,
            AkubicaUatFixtureContract::STATUS_ACTIVE,
        ], true)) {
            throw new \LogicException('Invalid UAT manifest transition.');
        }

        $this->forceFill([
            'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
            'metadata' => $metadata,
        ])->save();
    }

    public function markFailed(): void
    {
        if (! in_array($this->status, [
            AkubicaUatFixtureContract::STATUS_PREPARING,
            AkubicaUatFixtureContract::STATUS_FAILED,
        ], true)) {
            throw new \LogicException('Invalid UAT manifest transition.');
        }

        $this->forceFill(['status' => AkubicaUatFixtureContract::STATUS_FAILED])->save();
    }

    public function beginReset(): void
    {
        if (! in_array($this->status, [
            AkubicaUatFixtureContract::STATUS_ACTIVE,
            AkubicaUatFixtureContract::STATUS_FAILED,
        ], true)) {
            throw new \LogicException('Invalid UAT manifest transition.');
        }

        $this->forceFill(['status' => AkubicaUatFixtureContract::STATUS_RESETTING])->save();
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
