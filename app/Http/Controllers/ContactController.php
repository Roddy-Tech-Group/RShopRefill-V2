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
     * Show the Influencers and Affiliates inquiry page. It replaced the
     * separate Partnerships and Suppliers pages, whose URLs now redirect here.
     */
    public function affiliates()
    {
        return view('shop.affiliates');
    }

    /**
     * Show the "Request a product" page: a customer telling us about a brand or
     * service they want us to stock. Shares the inquiry form with the
     * influencers page, so a request lands in Support Tickets like any other.
     */
    public function requestProduct()
    {
        return view('shop.request-product');
    }

    /**
     * Handler for the inquiry form. Stores a ContactMessage with a
     * category-tagged subject and notifies the admin feed, so influencer and
     * affiliate leads land in the same Support Tickets page as general
     * enquiries (just filterable by subject prefix).
     *
     * $kind: 'affiliate' today. Kept as a parameter so another inquiry form
     *        can reuse this handler with its own subject and redirect.
     */
    public function storeInquiry(Request $request, AdminNotificationService $adminNotifications, string $kind)
    {
        if (filled($request->input('website_hp'))) {
            return back()->with('contact_sent', true);
        }

        // A link typed without its scheme ("instagram.com/handle") is still a
        // link. Fill the scheme in so the url rule can judge the rest, rather
        // than failing a creator for leaving out what browsers hide anyway.
        $link = trim((string) $request->input('company_url', ''));

        if ($link !== '' && ! preg_match('~^[a-z][a-z0-9+.-]*://~i', $link)) {
            $request->merge(['company_url' => 'https://'.$link]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'company_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'role' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        // The form posts one set of field names (company, company_url, role,
        // category) whatever the inquiry is; what a person actually typed into
        // them depends on the form they filled in. These labels say so, so the
        // admin reading the support ticket sees it inline instead of needing a
        // separate column on the model.
        [$subjectPrefix, $redirectRoute, $labels] = match ($kind) {
            'product' => [
                'Product request',
                'shop.request-product',
                ['company' => 'Product or brand', 'company_url' => 'Where they have seen it', 'role' => 'Country or region', 'category' => 'Product type'],
            ],
            default => [
                'Influencer and affiliate inquiry',
                'shop.affiliates',
                ['company' => 'Brand, channel or handle', 'company_url' => 'Profile or website', 'role' => 'Audience size', 'category' => 'Main platform'],
            ],
        };

        $body = collect([
            $labels['company'].': '.$data['company'],
            ! empty($data['company_url']) ? $labels['company_url'].': '.$data['company_url'] : null,
            ! empty($data['role']) ? $labels['role'].': '.$data['role'] : null,
            ! empty($data['category']) ? $labels['category'].': '.$data['category'] : null,
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

        // Back where they submitted from, when that was one of our own pages.
        // The same form now renders inside the dashboard's Partnership program
        // tab, and a fixed redirect there threw a signed-in customer out to the
        // storefront to read their own confirmation. Falls back to the named
        // route when there is no usable referer, which is also what keeps the
        // plain page's redirect exactly as it was.
        $referer = (string) $request->headers->get('referer');

        if ($referer !== '' && str_starts_with($referer, url('/'))) {
            return redirect()->to($referer)->with('contact_sent', true);
        }

        return redirect()->route($redirectRoute)->with('contact_sent', true);
    }
}
