<?php

namespace App\Console\Commands;

use App\Imports\LaboratoryTestsImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportLaboratoryTests extends Command
{
    protected $signature = 'laboratory:tests-import {path : Path to the Excel file}';
    protected $description = 'Import laboratory tests from an Excel file stored in a specified path';

    public function handle()
    {
        $path = $this->argument('path');

        if (!Storage::exists($path)) {
            $this->error("File not found");
            return;
        }

        try {
            Excel::import(new LaboratoryTestsImport, $path);
            $this->info('Laboratory tests imported successfully');
        } catch (\Exception $e) {
            $this->error('Error importing laboratory tests: ' . $e->getMessage());
        }
    }
}
