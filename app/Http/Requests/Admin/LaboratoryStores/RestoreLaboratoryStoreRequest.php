<?php

namespace App\Http\Requests\Admin\LaboratoryStores;

use App\Models\LaboratoryStore;
use Illuminate\Foundation\Http\FormRequest;

class RestoreLaboratoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = LaboratoryStore::withTrashed()->find($this->route('laboratory_store'));

        return $store !== null && $this->user()->can('restore', $store);
    }

    public function rules(): array
    {
        return [];
    }
}
