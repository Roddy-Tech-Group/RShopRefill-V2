@include('shop._inquiry-form', [
    'kind' => 'suppliers',
    'title' => 'Suppliers',
    'tagline' => 'List your gift cards, eSIMs, top-ups or bill payments on '.$siteName.'.',
    'intro' => 'We are always looking for new suppliers across gift cards, mobile airtime, data top-ups, bill aggregators and travel inventory. Tell us about the catalogue you offer, the regions you cover and how you settle, and our supply team will get back to you.',
    'postRoute' => 'suppliers.send',
    'categories' => ['Gift cards', 'eSIM / mobile data', 'Airtime / top-ups', 'Bill payments', 'Travel inventory', 'Other'],
])
