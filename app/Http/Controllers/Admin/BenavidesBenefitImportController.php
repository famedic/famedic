<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BenavidesCodeImport;
use App\Services\Benavides\BenavidesCodeImportPreviewService;
use App\Services\Benavides\BenavidesCodeImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class BenavidesBenefitImportController extends Controller
{
    private const PERMISSION = 'benavides-benefit.manage';

    public function create(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Admin/BenavidesBenefit/Import', [
            'preview' => session('benavidesPreview'),
            'result' => session('benavidesImportResult'),
        ]);
    }

    public function preview(Request $request, BenavidesCodeImportPreviewService $service): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'source_file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx'],
        ]);

        try {
            $preview = $service->preview($validated['source_file'], $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['source_file' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.benavides-benefit.import')
            ->with('benavidesPreview', $preview);
    }

    public function confirm(Request $request, BenavidesCodeImportService $service): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'import_id' => ['required', 'integer', 'exists:benavides_code_imports,id'],
        ]);

        $run = BenavidesCodeImport::query()->findOrFail($validated['import_id']);
        abort_unless((int) $run->uploaded_by_user_id === (int) $request->user()->id, 403);

        try {
            $result = $service->confirm((int) $validated['import_id'], $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['import_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.benavides-benefit.import')
            ->with('benavidesImportResult', $result);
    }

    private function authorizeAdmin(Request $request): void
    {
        try {
            abort_unless($request->user()?->administrator?->hasPermissionTo(self::PERMISSION), 403);
        } catch (PermissionDoesNotExist) {
            abort(403);
        }
    }
}
