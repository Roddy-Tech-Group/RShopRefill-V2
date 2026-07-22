<?php

namespace App\Http\Controllers;

use App\Domain\Communication\Services\AdminNotificationService;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Show the Contact page.
     */
    public function index()
    {
        return view('shop.contact');
    }

    /**
     * Store a contact message and notify the admin team.
     */
    public function store(Request $request, AdminNotificationService $adminNotifications)
    {
        // Honeypot: bots fill the hidden "website" field. Accept silently so they
        // do not learn the submission was dropped.
        if (filled($request->input('website'))) {
            return redirect()->route('shop.contact')->with('contact_sent', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'order_id' => ['nullable', 'string', 'max:100'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $contact = ContactMessage::create([
            'user_id' => $request->user()?->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'subject' => $data['subject'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'message' => $data['message'],
            'ip_address' => $request->ip(),
        ]);

        $adminNotifications->push(
            type: 'contact',
            title: 'New contact message',
            message: $contact->name.': '.($contact->subject ?: 'General enquiry'),
            data: ['contact_message_id' => $contact->id, 'email' => $contact->email],
        );

        return redirect()->route('shop.contact')->with('contact_sent', true);
    }

    /**
     * Show the Partnerships inquiry page.
     */
    public function partnerships()
    {
        return view('shop.partnerships');
    }

    /**
     * Show the Suppliers inquiry page.
     */
    public function suppliers()
    {
        return view('shop.suppliers');
    }

    /**
     * Shared handler for the partnerships + suppliers inquiry forms. Stores a
     * ContactMessage with a category-tagged subject and notifies the admin
     * feed, so partnership / supplier leads land in the same Support Tickets
     * page as general enquiries (just filterable by subject prefix).
     *
     * $kind: 'partnership' | 'supplier' - drives the subject prefix and the
     *        redirect route on success.
     */
    public function storeInquiry(Request $request, AdminNotificationService $adminNotifications, string $kind)
    {
        if (filled($request->input('website_hp'))) {
            return back()->with('contact_sent', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'company_url' => ['nullable', 'string', 'max:500'],
            'role' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $isSupplier = $kind === 'supplier';
        $subjectPrefix = $isSupplier ? 'Supplier inquiry' : 'Partnership inquiry';
        $redirectRoute = $isSupplier ? 'shop.suppliers' : 'shop.partnerships';

        // Compose a structured message body so the admin reading the support
        // ticket sees the company + URL + role inline instead of needing a
        // separate column on the model.
        $body = collect([
            'Company: '.$data['company'],
            ! empty($data['company_url']) ? 'Website: '.$data['company_url'] : null,
            ! empty($data['role']) ? 'Role: '.$data['role'] : null,
            ! empty($data['category']) ? 'Category: '.$data['category'] : null,
            '',
            $data['message'],
        ])->filter(fn ($l) => $l !== null)->implode("\n");

        $contact = ContactMessage::create([
            'user_id' => $request->user()?->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'subject' => $subjectPrefix.($data['category'] ? ' - '.$data['category'] : ''),
            'message' => $body,
            'ip_address' => $request->ip(),
        ]);

        $adminNotifications->push(
            type: 'contact',
            title: 'New '.$subjectPrefix.' submitted',
            message: $contact->name.' from '.$data['company'],
            data: ['contact_message_id' => $contact->id, 'email' => $contact->email, 'kind' => $kind],
        );

        return redirect()->route($redirectRoute)->with('contact_sent', true);
    }
}
