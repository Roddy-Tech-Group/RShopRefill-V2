<?php

/**
 * eSIM 商店的专属文案。为何单独成文件，见 lang/en/esim.php。国家、地区和
 * 套餐名称、价格及品牌名称保持原样，通过 :placeholders 传入。
 */
return [
    // 页面标题。
    'page_title' => ':region eSIM',
    'heading_region' => ':region eSIM',
    'breadcrumb_store' => 'eSIM 商店',

    // eSIM 页面广告。
    'hero_1_heading' => '尽享无限流量的自由',
    'hero_1_text' => '尽管去看那个视频、听那首歌、下载那个应用。探索我们的 eSIM 流量套餐，在 190 多个国家享受不间断的连接。',
    'hero_2_heading' => '落地即可上网',
    'hero_2_text' => '从登机口到会议室，旅行者、自由职业者和远程团队在 190 多个国家都能即时获得流量。没有漫游账单，无需更换 SIM 卡。',
    'hero_3_heading' => '几分钟即可设置好，打包行李前就能搞定',
    'hero_3_text' => '购买流量套餐，扫描二维码即可使用。无需在营业厅排队，不会弄丢塑料 SIM 卡，您的常用号码仍可照常接打电话和收发短信。',
    'go_to_advert' => '前往第 :number 则广告',

    // 标签页和网格标题。
    'tab_popular' => '热门',
    'tab_local' => '本地',
    'tab_regional' => '区域',
    'tab_global' => '全球',
    'tab_unlimited' => '无限流量',
    'title_popular' => '热门目的地',
    'title_local' => '本地 eSIM',
    'title_regional' => '区域 eSIM',
    'title_global' => '全球 eSIM',
    'title_unlimited' => '无限流量 eSIM',
    'title_all' => '全部 eSIM',
    'subtitle_popular' => '探索我们最受欢迎的 eSIM。套餐起价如图所示。',
    'subtitle_local' => '单一国家 eSIM。套餐起价如图所示。',
    'subtitle_regional' => '多国区域 eSIM。套餐起价如图所示。',
    'subtitle_global' => '全球覆盖 eSIM。套餐起价如图所示。',
    'subtitle_unlimited' => '提供无限流量套餐的目的地。套餐起价如图所示。',
    'subtitle_all' => '我们提供的所有 eSIM。套餐起价如图所示。',

    // 搜索。
    'search_placeholder' => '您要去哪里旅行？',
    'search_label' => '搜索 eSIM 目的地',
    'clear_search' => '清除搜索',
    'show_matching' => '显示匹配的目的地',

    // 可用性和空状态。
    'unavailable_title' => 'eSIM 暂时无法购买',
    'unavailable_browse' => '您可以浏览覆盖范围，但目前暂停购买。请稍后再来。',
    'unavailable_view' => '您可以查看套餐，但目前暂停购买。请稍后再来。',
    'empty_title' => '暂无可用的 eSIM',
    'empty_body' => '我们正在增加覆盖范围，请稍后再来。',
    'browse_all' => '浏览全部 :count 个目的地',

    // 目的地卡片标签。
    'from' => '起',
    'from_data' => ':data 起',
    'days_range' => ':range 天',
    'unlimited' => '无限',
    'chip_data' => '流量',
    'chip_phone_number' => '电话号码',
    'chip_data_only' => '仅流量',
    'chip_top_up' => '可充值',
    'view_plans' => '查看套餐',

    // 购买前确认。
    'tip_local_heading' => '仅当您身在 :name 或即将前往时才购买此 eSIM',
    'tip_regional_heading' => '请确认 :name 覆盖您要前往的国家',
    'tip_local_body' => '它只连接当地网络，在其他地方无法使用。',
    'tip_regional_body' => '它仅在此套餐列出的国家可用，其他地方均不可用。完整列表见套餐页面。',
    'tip_continue' => '继续前往 :name',
    'tip_not_going' => '不去那里？一张 eSIM 即可覆盖全球。',
    'tip_buy_global' => '购买 :name',

    // 首页目的地卡片。
    'card_label_global' => '全球 eSIM',
    'card_label_regional' => '区域 eSIM',
    'card_label_local' => '本地 eSIM',
    'card_network_available' => '支持 :network',
    'card_aria_from' => ':name，:price 起',
    'card_from_price' => ':price 起',
    'card_instant_qr' => '即时二维码',
    'card_hotspot' => '热点',
    'card_unavailable' => '不可用',
    'card_currently_unavailable' => '暂不可用',
    'card_global_description' => '美国流量 + 真实美国号码。让您随处保持连接。',
    'card_region_description' => '在 :count 个国家保持连接。',
    'card_region_description_generic' => '在整个区域保持连接。',
    'card_local_description' => '为您前往 :country 的旅程提供可靠的移动流量。',
    'card_local_description_article' => '为您前往 :country 的旅程提供可靠的移动流量。',

    // 目的地页头。
    'countries_and_networks_count' => ':count 个国家和网络',
    'networks' => '网络',
    'check_compatibility' => '检查兼容性',
    'topup_note' => '流量不足时，您随时可以:topup',
    'topup_word' => '充值',
    'starts_on_connect' => '连接到支持的网络后，套餐才开始计时',
    'guard_warning' => '仅当您身在 :region 或即将前往时才购买此 eSIM，它只能在 :local 的网络上使用。不去那里？:link，享受全球覆盖。',
    'guard_link' => '改为购买 Discover Global',
    'change_region' => '更换国家或地区',
    'search_region' => '搜索国家或地区',
    'choose_region' => '选择国家或地区',
    'instant_activation' => '即时激活',
    'no_id_required' => '无需身份证件',
    'covers_countries' => '覆盖 :count 个国家和地区',

    // 套餐选择器和套餐卡片。
    'tab_voice' => '流量 / 通话 / 短信',
    'tab_data' => '仅流量',
    'mode_fixed' => '固定流量',
    'choose_package' => '选择您的套餐',
    'badge_calls' => '通话',
    'day_one' => ':count 天',
    'days_other' => ':count 天',
    'plan_minutes' => ':count 分钟',
    'plan_sms' => ':count 条短信',
    'get_esim' => '获取 eSIM',
    'no_packages' => '此类别暂无套餐。',

    // 每个语音套餐包含。
    'voice_includes' => '每个语音套餐均包含',
    'voice_app_title' => '应用验证',
    'voice_app_desc' => 'WhatsApp、Telegram、TikTok 以及其他许多需要号码的应用。',
    'voice_imessage_title' => '无限 iMessage',
    'voice_imessage_desc' => '在套餐整个有效期内无限使用 iMessage。',
    'voice_topup_title' => '可续订、可充值',
    'voice_topup_desc' => '随时充值本地通话，iMessage 无限使用。',

    // 更广覆盖。
    'broader_title' => '需要更广的覆盖？',
    'broader_subtitle' => '探索我们的区域和全球 eSIM。套餐起价如图所示，并包含所选目的地的覆盖。',

    // 为什么选择我们。
    'why_heading' => '旅行者为何选择 :site eSIM',
    'why_coverage' => '覆盖 :count 个目的地的本地、区域和全球网络',
    'why_flexible' => '灵活的套餐，包括无限流量选项',
    'why_pay_languages' => '用您的货币或加密货币付款，商店支持 :languages',
    'why_pay' => '用您的货币或加密货币付款',
    'why_install' => '安装设置简单，几分钟即可连接',

    // 如何添加 eSIM。
    'how_heading' => '如何添加您的 eSIM',
    'how_subtitle' => '只需三个简单步骤，即可按当地资费漫游。无需 SIM 卡托，无需在机场柜台排队。',
    'how_step_1_title' => '打开设置 &rarr; 蜂窝网络',
    'how_step_1_body' => '解锁手机，轻点蜂窝网络（旧款 iPhone 上为蜂窝移动网络）。设备上的所有号码都在这里。',
    'how_step_2_title' => '轻点添加 eSIM 并扫描二维码',
    'how_step_2_body' => '付款后几秒内，我们会通过电子邮件发送二维码。用相机对准它，或轻点手动输入详细信息来粘贴代码。',
    'how_step_3_title' => '打开此号码，并开启数据漫游',
    'how_step_3_body' => '打开“启用此号码”，然后为新号码开启数据漫游。落地后，您的手机即可按当地资费连接。',
    'how_guide' => '仍有问题？阅读完整指南',

    // 匿名购买。
    'anon_heading' => '用 MOMO、银行卡、加密货币等购买匿名 eSIM。无需 KYC。',
    'anon_body' => '与需要护照和个人信息的传统 SIM 卡不同，您的 :site eSIM 可以用移动支付、银行卡、加密货币等方式购买。无需身份验证，不收集个人数据。',
    'anon_kyc_title' => '无需 KYC',
    'anon_kyc_body' => '无需身份验证或个人信息，即可立即购买。',
    'anon_pay_title' => '随心支付',
    'anon_pay_body' => '移动支付、银行卡、Apple Pay、银行转账或加密货币（Bitcoin、Ethereum、Tether、BNB、Solana、Litecoin），由您决定。',
    'anon_delivery_title' => '即时交付',
    'anon_delivery_body' => '付款确认后，eSIM 二维码会立即发送到您的邮箱。',

    // 支付方式。
    'payments_heading' => '我们接受的支付方式',
    'payments_subtitle' => '用加密货币或本地银行卡付款，以您已持有的货币结算。',
    'payments_label' => '接受的支付方式',

    // 无套餐。
    'no_plans_title' => '暂无流量套餐',
    'no_plans_body' => '该地区目前没有可售套餐，请稍后再来。',
    'browse_other_regions' => '浏览其他地区',

    // 购买窗口。
    'calls_and_texts' => '通话和短信',
    'coverage' => '覆盖范围',
    'buy_works_across' => '可在 :countries 使用，在每个国家自动连接当地网络。',
    'countries_count' => ':count 个国家',
    'more_count' => '另有 :count 个',
    'buy_works_in' => '可在 :region 使用。抵达后自动连接，您也可以在出发前安装。',
    'pay_in' => '支付币种',
    'total' => '合计',
    'package_details' => '套餐详情',

    // 网络窗口。
    'countries_networks' => '国家和网络',
    'networks_across' => '此 eSIM 可在 :count 个国家使用，并在每个国家自动连接当地网络。',
    'search_country' => '按国家搜索',
    'networks_switch' => '此 eSIM 可能会根据可用性和速度在以下网络之间切换。您可以在设备设置中进行调整。',
    'networks_auto' => '您的设备会自动连接 :region 最佳的可用本地网络。',

    // 兼容性。
    'compat_intro' => '要使用 eSIM，设备必须同时满足以下三个条件：',
    'compat_supports' => '设备支持 eSIM。',
    'compat_unlocked' => '设备未被运营商或网络锁定。',
    'compat_not_jailbroken' => '设备未越狱（iOS）或未 root（Android）。',
    'compat_missing_title' => '没有找到您的设备？',
    'compat_missing_body' => '此列表会持续更新但并不完整，而且所列设备的部分地区型号不支持 eSIM。如果您的设备不在列表中，请向制造商确认。',
    'compat_find' => '查找您的设备',
    'compat_counts' => ':brands 家制造商的 :models 款型号',
    'compat_search' => '按制造商或型号搜索',
    'compat_keep_typing' => '请继续输入，至少三个字符。',
    'compat_models' => ':count 款型号|:count 款型号',
    'compat_no_match' => '没有与该搜索匹配的制造商或型号。',

    // 套餐详情。
    'details_package' => '套餐',
    'details_texts' => '短信',
    'details_validity' => '有效期',
    'details_flexible' => '灵活',
    'details_apple_services' => 'iMessage 和 Apple 服务',
    'details_imessage_desc' => '与其他 Apple 设备无限畅聊。',
    'details_imessage_calls' => 'iMessage 通话',
    'details_imessage_calls_desc' => '通过 iMessage / 流量进行语音通话。',
    'details_imessage_text' => 'iMessage 信息',
    'details_imessage_text_desc' => '通过 iMessage 收发信息。',
    'details_facetime_desc' => '通过流量进行 FaceTime 视频和语音通话。',
    'details_topups_title' => '充值和 iMessage',
    'details_topups_body' => '您可以随时为此套餐充值，用于本地通话、短信和日常上网。使用 iMessage 始终免费且无限，不会占用您的套餐额度。',
    'details_data_only_body' => '这是仅含流量的套餐，在整个有效期内通过移动流量让您保持在线。它不含通话、短信或电话号码，仅提供上网功能。',
    'details_activation' => '激活规则',
    'activation_first_usage' => '有效期从 eSIM 在覆盖区域内连接到支持的网络时开始，而不是从购买时开始。如果您在覆盖区域外安装，它会在您抵达时激活。',
    'activation_installation' => '有效期从 eSIM 安装到您的设备上时开始。',
    'activation_automatic' => 'eSIM 安装并连接到支持的网络后会自动激活。',
    'details_topup_option' => '充值选项',
    'details_rechargeable' => '当流量或有效期用完时，您可以为此 eSIM 充值。安装 eSIM 后即可充值。',
    'details_not_rechargeable' => '此套餐不可充值。用完后，请为同一设备购买新套餐。',
    'details_other_info' => '其他信息',
    'details_after_allowance' => '流量、短信和通话用完后，您仍可通过 iMessage、WhatsApp 和 WiFi 打电话、发信息和使用 FaceTime。如需继续使用普通短信、通话和流量，请在 eSIM 到期前充值。如果您打算继续使用，充值会自动将您的 eSIM 续订为所选的充值套餐。在 Web App 的订单页面，您可以管理 eSIM、查看剩余流量、余额和短信、为 eSIM 充值（仅限在该页面）以及安装 eSIM。祝您玩得开心 😊',
    'details_confirm_device' => '完成订单前，请确认您的设备支持 eSIM 且未被网络锁定。',
];
