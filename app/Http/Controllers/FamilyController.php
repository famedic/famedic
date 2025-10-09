<?php

namespace App\Http\Controllers;

use App\Actions\Customers\CreateFamilyAccountCustomerAction;
use App\Actions\Family\DestroyFamilyAccountAction;
use App\Actions\Family\UpdateFamilyAccountAction;
use App\Enums\Gender;
use App\Enums\Kinship;
use App\Http\Requests\Family\StoreFamilyAccountRequest;
use App\Http\Requests\Family\UpdateFamilyAccountRequest;
use App\Models\FamilyAccount;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FamilyController extends Controller
{
    public function index(Request $request)
    {
        $familyAccounts = $request->user()->customer
            ->familyAccounts()
            ->with(['customer'])
            ->get();

        return Inertia::render('Family', [
            'familyAccounts' => $familyAccounts,
            'kinships' => Kinship::casesWithLabels(),
            'allowedKinships' => $this->getAllowedKinships($familyAccounts),
            'genders' => Gender::casesWithLabels(),
        ]);
    }

    public function create(Request $request)
    {
        $familyAccounts = $request->user()->customer
            ->familyAccounts()
            ->with(['customer'])
            ->get();

        return Inertia::render('Family', [
            'familyAccounts' => $familyAccounts,
            'kinships' => Kinship::casesWithLabels(),
            'allowedKinships' => $this->getAllowedKinships($familyAccounts),
            'genders' => Gender::casesWithLabels(),
        ]);
    }

    public function store(StoreFamilyAccountRequest $request, CreateFamilyAccountCustomerAction $action)
    {
        $action(
            name: $request->name,
            paternal_lastname: $request->paternal_lastname,
            maternal_lastname: $request->maternal_lastname,
            kinship: Kinship::from($request->kinship),
            birth_date: Carbon::parse($request->birth_date),
            gender: Gender::from($request->gender),
            customer: $request->user()->customer
        );

        return redirect()->route('family.index')
            ->flashMessage('Familiar guardado exitosamente.');
    }

    public function edit(Request $request, FamilyAccount $familyAccount)
    {
        $familyAccounts = $request->user()->customer
            ->familyAccounts()
            ->with(['customer'])
            ->get();

        return Inertia::render('Family', [
            'familyAccounts' => $familyAccounts,
            'kinships' => Kinship::casesWithLabels(),
            'allowedKinships' => $this->getAllowedKinships($familyAccounts, $familyAccount),
            'genders' => Gender::casesWithLabels(),
            'familyAccount' => $familyAccount,
        ]);
    }

    public function update(UpdateFamilyAccountRequest $request, FamilyAccount $familyAccount, UpdateFamilyAccountAction $action)
    {
        $action(
            name: $request->name,
            paternal_lastname: $request->paternal_lastname,
            maternal_lastname: $request->maternal_lastname,
            birth_date: Carbon::parse($request->birth_date),
            gender: Gender::from($request->gender),
            kinship: Kinship::from($request->kinship),
            familyAccount: $familyAccount
        );

        return redirect()->route('family.index')
            ->flashMessage('Familiar actualizado exitosamente.');
    }

    public function destroy(FamilyAccount $familyAccount, DestroyFamilyAccountAction $action)
    {
        $action($familyAccount);

        return redirect()->route('family.index')
            ->flashMessage('Familiar eliminado exitosamente.');
    }

    private function getAllowedKinships($familyAccounts, $excludeFamilyAccount = null)
    {
        $existing = $familyAccounts
            ->reject(fn($account) => $excludeFamilyAccount && $account->id === $excludeFamilyAccount->id)
            ->pluck('kinship')
            ->map(fn($kinship) => $kinship->value)
            ->unique();

        if ($existing->isEmpty()) return array_column(Kinship::casesWithLabels(), 'value');

        // Group 1: spouse/child, Group 2: parent
        return $existing->intersect(['spouse', 'child'])->isNotEmpty() 
            ? ['spouse', 'child'] 
            : ['parent'];
    }
}
