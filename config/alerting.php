<?php

/*
 * Slack is off unless it is deliberately switched on. The team does not use it,
 * and a routing table listing a channel nobody reads is worse than one that
 * omits it: `canReachAHumanUrgently()` and the /health endpoint would both count
 * a dead Slack webhook as a working escalation path.
 */
$slackEnabled = (bool) env('OPS_SLACK_ENABLED', false);

$withOptionalSlack = static fn (array $channels): array => $slackEnabled
    ? [...$channels, 'slack']
    : $channels;

return [

    /*
    |--------------------------------------------------------------------------
    | Operational alert delivery
    |--------------------------------------------------------------------------
    |
    | Where operational alerts go when the platform notices something wrong.
    | Sentry catches exceptions; these channels catch the failures that throw
    | nothing at all — a payment success rate sliding from 96% to 78%, a backup
    | that stopped running, a queue quietly filling up.
    |
    | Every channel is optional. Anything unconfigured is skipped silently
    | EXCEPT that `alerting:test` and the /health endpoint will report the gap,
    | so an unconfigured alert path is visible rather than assumed working.
    |
    */

    'enabled' => (bool) env('ALERTING_ENABLED', true),

    /*
     * Shown in every alert so a message pasted into a chat is unambiguous about
     * which environment produced it.
     */
    'environment' => env('APP_ENV', 'production'),

    'channels' => [

        /*
         * PRIMARY CHANNEL. Telegram is first for every tier that needs a human,
         * because a phone can be set to override silent mode for a single chat —
         * something neither email nor Slack does reliably. Create the bot via
         * @BotFather, then read the chat id from
         * https://api.telegram.org/bot<TOKEN>/getUpdates after messaging it.
         */
        'telegram' => [
            'bot_token' => env('OPS_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('OPS_TELEGRAM_CHAT_ID'),
        ],

        /*
         * SECONDARY CHANNEL. Slower and easy to miss at 3 a.m., which is exactly
         * why it is the backstop rather than the front line: it survives Telegram
         * being unreachable, and it leaves a durable, searchable record.
         */
        'mail' => [
            'to' => env('OPS_ALERT_EMAIL'),
        ],

        /*
         * OPTIONAL, DISABLED BY DEFAULT. Retained so a future team that does live
         * in Slack can switch it on with OPS_SLACK_ENABLED=true without a code
         * change. Falls back to the long-dormant logging webhook URL.
         */
        'slack' => [
            'enabled' => $slackEnabled,
            'webhook_url' => env('OPS_SLACK_WEBHOOK_URL', env('LOG_SLACK_WEBHOOK_URL')),
        ],
    ],

    /*
     * Which channels each severity uses, in delivery order.
     *
     * SEV-1 fans out to both real channels: the cost of a duplicate notification
     * is annoyance, the cost of a missed one is the company. SEV-2 goes to
     * Telegram only — it needs to be seen today, not to wake anyone. SEV-3 is
     * email only, because a digest that buzzes a phone is how a team learns to
     * silence the phone.
     */
    'routing' => [
        'sev1' => $withOptionalSlack(['telegram', 'mail']),
        'sev2' => $withOptionalSlack(['telegram']),
        'sev3' => $withOptionalSlack(['mail']),
    ],

    /*
     * Suppression window, in minutes, for an identical alert key. Stops a
     * five-minute scheduled check from sending the same message 288 times a day
     * while a known problem is being worked. Set to 0 to disable.
     */
    'throttle_minutes' => (int) env('ALERTING_THROTTLE_MINUTES', 60),

    /*
     * Where a reader should go to act on the alert. Individual alerts override
     * this with their own runbook.
     */
    'runbook_base' => env('OPS_RUNBOOK_BASE', 'docs/runbooks'),

];
