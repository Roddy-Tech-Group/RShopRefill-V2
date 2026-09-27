<?php

/**
 * The home page's own copy. See lang/en/home.php for why it is a separate
 * file from products.php and navigation.php.
 */
return [
    // Hero. The headline is two lines on a wide screen and the break sits
    // between them, so it is stored as two keys rather than one string with
    // markup in it - a translator should never have to carry a <br> across.
    'hero_heading_line_one' => '礼品卡、eSIM、手机充值',
    'hero_heading_line_two' => '与账单缴费，尽在一处',
    'hero_subtitle' => '使用 Mobile Money、Orange Money、银行卡、银行转账或加密货币，轻松支付您的各类数字商品、订阅充值和账单。',
    'hero_cta_gift_cards' => '选购礼品卡',
    'hero_cta_esims' => '探索 eSIM',

    // The feature chips under the hero. :count is the rounded catalogue figure,
    // so the number stays live while the sentence around it is translatable.
    'chip_products_title' => ':count+ 款数字产品',
    'chip_products_subtitle' => '全球礼品卡、eSIM、充值与账单缴费',
    'chip_countries_title' => '覆盖 :count+ 个国家',
    'chip_countries_subtitle' => '超过 :count 个目的地的eSIM 和本地充值',
    'chip_reviews_title' => '经过验证的客户评价',
    'chip_reviews_subtitle' => '来自在此购买过的客户的真实反馈',
    'chip_devices_title' => '支持所有设备',
    'chip_devices_subtitle' => '兼容电脑、iPad 和手机',
    'chip_search_title' => '快速找到所需',
    'chip_search_subtitle' => '几秒内搜索礼品卡、eSIM 和充值',

    // Assurance strip under the category cards: three promises and the
    // payment methods that back them.
    'assurance_label' => '您获得什么以及如何付款',
    'assurance_instant_title' => '即时交付',
    'panel_title' => '即时交付，毫无麻烦。',
    'assurance_privacy_title' => '安全设计',
    'assurance_support_title' => '全天候真人客服',
    'assurance_pay_with' => '支持 MOMO、OM、银行卡和加密货币付款',
    'assurance_more_methods' => '另有 :count 种',

    // Homepage FAQ. Questions themselves live in the faqs table.
    'faq_heading' => '常见问题',
    'faq_subheading' => '更多问题请查看我们的 :link 页面',
    'faq_link_label' => 'FAQ',

    // Live counts panel. The numbers come from the database; these are
    // only the labels beside them.
    'counts_customers' => '注册客户',
    'counts_orders' => '已交付订单',
    'counts_countries' => '服务国家/地区',
    'counts_rank_value' => '值得信赖',
    'counts_rank_label' => '数字商务平台',

    // Home rows, the how-it-works steps and the services panel. The
    // offering_* words are joined into sentences with the list glue keys,
    // so a language can set its own separators.
    'esim_row_heading' => '使用 eSIM，在 :count+ 个国家保持连接',
    'esim_row_prev' => '上一组 eSIM',
    'esim_row_next' => '更多 eSIM',
    'teaser_flights_and_stays' => '机票与住宿',
    'teaser_flights_body' => '安全预订、快速确认，在世界任何地方都能畅享全球出行，让旅行更省心。',
    'teaser_stays_body' => '把数字货币变成真实的旅行、舒适的住宿和难忘的体验。',
    'row_featured_gift_cards' => '精选礼品卡',
    'row_popular_gift_cards' => '热门礼品卡',
    'row_instant_topups' => '即时手机充值',
    'alt_gift_card' => ':name 礼品卡',
    'alt_top_up' => ':name 充值',
    'how_heading' => '使用流程',
    'how_step1_title' => '1. 选择产品或服务',
    'how_step1_body' => '从 :count+ 款 :offerings 中挑选，让购物更简单。',
    'how_step2_title' => '2. 可用银行卡、加密货币、移动支付、银行转账等付款',
    'how_step2_body' => '在一个流畅的平台上获取 :count+ 款数字产品，包括:offerings等。',
    'how_step3_title' => '3. 即时到账',
    'how_step3_body' => '产品数秒内即可送达，发送到您的邮箱；如果您已注册，也会出现在您的客户面板中，随时可用。',
    'offering_gift_cards' => '礼品卡',
    'offering_esims' => 'eSIM',
    'offering_flights' => '机票',
    'offering_stays' => '住宿',
    'offering_global_stays' => '全球住宿',
    'offering_topups' => '手机充值',
    'offering_bills' => '账单缴费',
    'list_glue' => '、',
    'list_last_glue' => '和',
    'list_last_glue_serial' => '和',
    'promo_heading' => '我们带来什么',
    'promo_aria' => '我们的服务',
    'promo_title' => 'Rshop 为您提供什么',
    'promo_services_count' => '23+ 项数字服务',
    'promo_access' => '在一个强大的生态系统中畅享 :services，包括:offerings。',
    'promo_together' => ':site 将一切汇聚一处，让跨境购物、网络连接、出行和日常数字支付变得快捷、顺畅、无忧。',
    'promo_tagline_one' => '一个平台，无限可能。',
    'promo_tagline_two' => '为简化您的生活而打造。',
    'promo_copy_manually' => '请手动复制此推荐链接：',
    'promo_copy_link_aria' => '复制您的推荐链接',
    'promo_share_link' => '分享您的推荐链接即可赚取 RCoin',
    'promo_set_code' => '设置您的推荐码，开始赚取奖励',
    'promo_open_rewards' => '打开奖励',
    'promo_guest_title' => '创建账户，开始赚取 RCoin',
    'promo_guest_cta' => '免费创建账户',
];
