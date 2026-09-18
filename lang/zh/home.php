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
    'chip_countries_subtitle' => '超过 :count 个目的地的旅行 eSIM 和本地充值',
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
];
