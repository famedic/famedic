<?php

namespace App\Console\Commands;

use App\Imports\LaboratoryStoresImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportLaboratoryStores extends Command
{
    protected $signature = 'laboratory:stores-import {path : Path to the Excel file}';
    protected $description = 'Import laboratory stores from an Excel file stored in a specified path';

    public function handle()
    {
        $path = $this->argument('path');

        if (!Storage::exists($path)) {
            $this->error("File not found");
            return;
        }

        try {
            Excel::import(new LaboratoryStoresImport, $path);
            $this->info('Laboratory stores imported successfully');
        } catch (\Exception $e) {
            $this->error('Error importing laboratory stores: ' . $e->getMessage());
        }
    }
}
