{{-- Influencers and Affiliates. Replaced the separate Partnerships and
     Suppliers pages (their URLs redirect here). The copy promises no rates,
     commissions or response times: those are business terms nobody has set
     yet, and a page that states them is making an offer. --}}
@include('shop._inquiry-form', [
    'kind' => 'affiliate',
    'badge' => 'Influencers & Affiliates',
    'title' => 'Influencers and Affiliates',
    'tagline' => 'Share '.$siteName.' with your audience. Tell us where you publish and how you would like to work together.',
    'intro' => 'We work with creators, influencers and affiliate publishers who want to bring gift cards, eSIMs, mobile top-ups and bill payments to their audience. Tell us about your channel, who follows you and the kind of collaboration you have in mind, and our team will review it and get back to you.',
    'postRoute' => 'affiliates.send',
    'categories' => ['Instagram', 'TikTok', 'YouTube', 'X (Twitter)', 'Facebook', 'Snapchat', 'WhatsApp or Telegram channel', 'Blog or website', 'Other'],
    'emailLabel' => 'Email',
    'emailPlaceholder' => 'you@example.com',
    'companyLabel' => 'Brand, channel or handle',
    'companyPlaceholder' => '@yourhandle',
    'urlLabel' => 'Profile or website link',
    'roleLabel' => 'Audience size',
    'rolePlaceholder' => 'e.g. 25,000 followers',
    'categoryLabel' => 'Main platform',
    'messagePlaceholder' => 'Tell us about your audience and how you would like to collaborate',
])
