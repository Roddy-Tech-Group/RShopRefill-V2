<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Serves the self-contained HTML reference documents that live alongside the
 * markdown runbooks (docs/runbooks/*.html) - today the Fraud & Risk Engine
 * handbook - exactly as authored, so they render identically to the source.
 * Lives under the Management section, so it inherits the same permission
 * gate as the runbooks library that links to it. The slug pattern plus the
 * on-disk check double as the path-traversal guard.
 */
class AdminRunbookDocController extends Controller
{
    public function __invoke(string $doc): Response
    {
        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]*$/', $doc) === 1, 404);

        $path = base_path("docs/runbooks/{$doc}.html");

        abort_unless(is_file($path), 404);

        return response((string) file_get_contents($path))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
