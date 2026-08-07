<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for the /admin/customers page: the name and email of every
 * customer the page lists, in the same order.
 *
 * Uses chunkById() so the whole users table never lands in memory at once -
 * this is the one admin export whose row count grows with the customer base
 * rather than with activity.
 */
class AdminCustomerExportController extends Controller
{
    public function csv(Request $request): StreamedResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        // A bulk download of every customer's name and email is strictly more
        // access than opening one customer's profile, so it must not be
        // reachable by a role that is blocked from the profile page itself
        // (Moderators). Checked explicitly rather than left to the route name,
        // so renaming the route can never silently open this up.
        abort_unless($admin->canAccessAdminRoute('admin.customer'), 403);

        $filename = 'customers-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email']);

            User::query()
                ->select(['id', 'name', 'email'])
                ->chunkById(500, function ($customers) use ($out) {
                    foreach ($customers as $customer) {
                        fputcsv($out, [$customer->name, $customer->email]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
