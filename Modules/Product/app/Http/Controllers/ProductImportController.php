<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Imports\ProductImport;
use Modules\Product\Imports\BundleImport;
use Modules\Product\Services\ProductImportService;
use App\Traits\ApiResponse;

class ProductImportController extends Controller
{
    use ApiResponse;

    protected ProductImportService $importService;

    public function __construct(ProductImportService $importService)
    {
        $this->importService = $importService;
    }

    public function importSingle(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv'
        ]);

        try {
            Excel::import(new ProductImport($this->importService), $request->file('file'));
            return $this->successResponse(null, 'Import produk biasa berhasil dilakukan.');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal melakukan import produk: ' . $e->getMessage(), 500);
        }
    }

    public function importBundle(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv'
        ]);

        try {
            Excel::import(new BundleImport($this->importService), $request->file('file'));
            return $this->successResponse(null, 'Import produk bundle berhasil dilakukan.');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal melakukan import bundle: ' . $e->getMessage(), 500);
        }
    }

    public function downloadSingleTemplate()
    {
        $path = base_path('template_import_productv2.xlsx');
        if (!file_exists($path)) {
            return $this->errorResponse('Template tidak ditemukan', 404);
        }
        return response()->download($path, 'Template_Import_Product.xlsx');
    }

    public function downloadBundleTemplate()
    {
        $path = base_path('template_import_bundle.xlsx');
        if (!file_exists($path)) {
            return $this->errorResponse('Template tidak ditemukan', 404);
        }
        return response()->download($path, 'Template_Import_Bundle.xlsx');
    }
}
