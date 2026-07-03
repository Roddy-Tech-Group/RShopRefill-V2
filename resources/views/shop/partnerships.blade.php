@include('shop._inquiry-form', [
    'kind' => 'partnerships',
    'title' => 'Partnerships',
    'tagline' => 'Build something with the '.$siteName.' team. Tell us what you have in mind.',
    'intro' => 'We work with brands, fintechs, OTAs, telcos and aggregators to bring more digital products to more people. Share a few details about your company and what kind of collaboration you are exploring and our partnerships team will follow up within a few business days.',
    'postRoute' => 'partnerships.send',
    'categories' => ['Reseller', 'Brand partnership', 'API integration', 'Affiliate', 'Co-marketing', 'Other'],
])
