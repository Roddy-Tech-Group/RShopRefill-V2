<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * The mobile API's contract, browsable.
 *
 * Serves docs/api/v2/openapi.yaml, the file the contract test holds the code to,
 * and a page that renders it. Admin-only: the contract describes every door into
 * customer accounts and money, which is Roddy's business and nobody else's.
 */
class AdminApiDocsController extends Controller
{
    public function page(): View
    {
        return view('admin.api-docs', [
            'specUrl' => route('admin.management.api-docs.spec'),
            'guide' => (string) file_get_contents(base_path('docs/api/v2/README.md')),
        ]);
    }

    public function spec(): Response
    {
        return response((string) file_get_contents(base_path('docs/api/v2/openapi.yaml')))
            ->header('Content-Type', 'application/yaml; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }
}
