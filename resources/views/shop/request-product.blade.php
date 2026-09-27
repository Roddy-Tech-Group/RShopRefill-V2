{{-- Request a product. A customer telling us about a brand or service we do not
     stock yet, so it lands in Support Tickets as a "Product request" alongside
     every other inquiry.

     Shares _inquiry-form with the influencers page: the field NAMES are that
     form's (company, company_url, role, category) and the labels below say what
     a customer is actually typing into them. ContactController::storeInquiry
     re-labels the same fields when it composes the ticket body, so an admin
     reads "Product or brand", not "Brand, channel or handle". --}}
@include('shop._inquiry-form', [
    'kind' => 'product',
    'badge' => 'Request a product',
    'title' => 'Request a product',
    'tagline' => 'Tell us what you would like '.$siteName.' to stock, and we will look into sourcing it.',
    'intro' => 'We add brands and services based on what customers ask for. If there is a gift card, eSIM, mobile top-up or bill payment you cannot find here, tell us what it is and where you need it, and our sourcing team will look into whether we can carry it.',
    'postRoute' => 'request-product.send',
    'steps' => [
        'Send us the details below',
        'We check whether we can source it',
    ],
    'benefits' => [
        'No account needed',
        'Free to ask',
        'Takes about a minute',
    ],
    'categories' => ['Gift card', 'eSIM or data plan', 'Mobile top-up', 'Bill payment', 'Something else'],
    'emailLabel' => 'Your email',
    'emailPlaceholder' => 'you@example.com',
    'companyLabel' => 'Product or brand',
    'companyPlaceholder' => 'e.g. Nintendo eShop',
    'urlLabel' => 'Link to the product',
    'roleLabel' => 'Country or region',
    'rolePlaceholder' => 'e.g. Cameroon',
    'categoryLabel' => 'Product type',
    'messagePlaceholder' => 'Tell us what you are trying to buy and what you would use it for',
])
