<?php

namespace App\Console\Commands;

use App\Imports\MedicalAttentionMembersImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportMedicalAttentionMembers extends Command
{
    protected $signature = 'medical-attention:members-import {path : Path to the Excel file}';
    protected $description = 'Import medical attention members from an Excel file stored in a specified path';

    public function handle()
    {
        $path = $this->argument('path');

        if (!Storage::exists($path)) {
            $this->error("File not found");
            return;
        }

        try {
            Excel::import(new MedicalAttentionMembersImport, $path);
            $this->info('Medical attention members imported successfully');
        } catch (\Exception $e) {
            $this->error('Error importing medical attention members: ' . $e->getMessage());
        }
    }
}
