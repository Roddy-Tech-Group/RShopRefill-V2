<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Services\AdminGlobalSearchService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoint behind the admin header command bar. Thin on purpose - the
 * matching, permission gating, and ranking all live in
 * {@see AdminGlobalSearchService} so they can be tested without HTTP.
 */
class AdminSearchController extends Controller
{
    public function __construct(private AdminGlobalSearchService $search) {}

    public function suggest(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return response()->json(
            $this->search->search((string) $request->query('q', ''), $admin)
        );
    }
}
