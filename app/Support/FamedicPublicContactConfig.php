<?php

namespace App\Support;

class FamedicPublicContactConfig
{
    /**
     * Concierge contact and schedule for checkout and shared frontend props.
     *
     * @return array<string, mixed>
     */
    public static function conciergeForFrontend(): array
    {
        $config = config('famedic.concierge', []);
        $scheduleByDay = $config['schedule_by_day'] ?? [];

        return [
            'phoneDisplay' => (string) ($config['phone_display'] ?? ''),
            'phoneTel' => (string) ($config['phone_tel'] ?? ''),
            'timezone' => (string) ($config['timezone'] ?? 'America/Mexico_City'),
            'scheduleLines' => ServiceHoursEvaluator::buildConciergeDisplayLines($scheduleByDay),
            'scheduleByDay' => $scheduleByDay,
            'availability' => $config['availability'] ?? [],
            'checkoutOfflineMessages' => array_values($config['checkout_offline_messages'] ?? []),
            'availableMessage' => (string) ($config['available_message'] ?? ''),
            'afterHoursMessage' => (string) ($config['after_hours_message'] ?? ''),
            'description' => (string) ($config['description'] ?? ''),
        ];
    }

    /**
     * Public props for the authenticated support page.
     *
     * @return array<string, mixed>
     */
    public static function supportPage(): array
    {
        $support = config('famedic.support', []);
        $concierge = self::conciergeForFrontend();
        $social = config('famedic.social', []);

        $customerService = $support['customer_service'] ?? [];
        $alternativeChannel = $support['alternative_channel'] ?? [];
        $email = $support['email'] ?? [];
        $hours = $support['hours'] ?? [];
        $appointment = $support['appointment_confirmation'] ?? [];

        $lydiaE164 = (string) ($customerService['whatsapp_e164'] ?? '');
        $alternativeE164 = (string) ($alternativeChannel['whatsapp_e164'] ?? '');
        $emailAddress = (string) ($email['address'] ?? '');

        $supportScheduleByDay = $hours['schedule_by_day'] ?? ServiceHoursEvaluator::supportGeneralScheduleByDay();

        return [
            'customerService' => self::channelOrNull([
                'title' => (string) ($customerService['title'] ?? 'Atención a clientes'),
                'contactName' => (string) ($customerService['contact_name'] ?? 'Lydia'),
                'channel' => 'whatsapp',
                'whatsappDisplay' => (string) ($customerService['whatsapp_display'] ?? ''),
                'whatsappUrl' => self::whatsappUrl(
                    $lydiaE164,
                    (string) ($customerService['whatsapp_default_message'] ?? ''),
                ),
            ], $lydiaE164 !== ''),

            'alternativeChannel' => self::channelOrNull([
                'title' => (string) ($alternativeChannel['title'] ?? 'Canal alternativo de atención'),
                'badge' => (string) ($alternativeChannel['badge'] ?? 'Segundo canal'),
                'description' => (string) ($alternativeChannel['description'] ?? ''),
                'buttonLabel' => (string) ($alternativeChannel['button_label'] ?? 'Abrir WhatsApp alternativo'),
                'whatsappDisplay' => (string) ($alternativeChannel['whatsapp_display'] ?? ''),
                'whatsappUrl' => self::whatsappUrl(
                    $alternativeE164,
                    (string) ($alternativeChannel['whatsapp_default_message'] ?? ''),
                ),
            ], $alternativeE164 !== ''),

            'email' => self::channelOrNull([
                'address' => $emailAddress,
                'mailtoUrl' => self::mailtoUrl(
                    $emailAddress,
                    (string) ($email['subject'] ?? ''),
                ),
            ], filter_var($emailAddress, FILTER_VALIDATE_EMAIL) !== false),

            'supportHours' => [
                'timezone' => (string) ($hours['timezone'] ?? 'America/Monterrey'),
                'timezoneLabel' => (string) ($hours['timezone_label'] ?? ''),
                'scheduleByDay' => $supportScheduleByDay,
                'lines' => ServiceHoursEvaluator::buildDisplayLines(
                    $supportScheduleByDay,
                    ServiceHoursEvaluator::supportGeneralDisplayGroups(),
                ),
                'availableMessage' => (string) ($hours['available_message'] ?? ''),
                'afterHoursMessage' => (string) ($hours['after_hours_message'] ?? ''),
            ],

            'concierge' => array_merge($concierge, [
                'telUrl' => self::telUrl($concierge['phoneTel'] ?? ''),
            ]),

            'appointmentConfirmation' => [
                'text' => (string) ($appointment['text'] ?? ''),
                'companionText' => (string) ($appointment['companion_text'] ?? ''),
            ],

            'social' => [
                'intro' => (string) ($social['intro'] ?? ''),
                'profiles' => collect($social['profiles'] ?? [])
                    ->filter(fn (array $profile) => filled($profile['url'] ?? null) && filled($profile['network'] ?? null))
                    ->values()
                    ->map(fn (array $profile) => [
                        'network' => (string) $profile['network'],
                        'url' => (string) $profile['url'],
                        'icon' => (string) ($profile['icon'] ?? ''),
                    ])
                    ->all(),
            ],
        ];
    }

    public static function whatsappUrl(string $e164, string $message = ''): ?string
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';

        if ($digits === '') {
            return null;
        }

        $url = 'https://wa.me/'.$digits;

        if ($message !== '') {
            $url .= '?text='.rawurlencode($message);
        }

        return $url;
    }

    public static function mailtoUrl(string $address, string $subject = ''): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $url = 'mailto:'.$address;

        if ($subject !== '') {
            $url .= '?subject='.rawurlencode($subject);
        }

        return $url;
    }

    public static function telUrl(string $phoneTel): ?string
    {
        $digits = preg_replace('/\D+/', '', $phoneTel) ?? '';

        return $digits !== '' ? 'tel:'.$digits : null;
    }

    /**
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>|null
     */
    private static function channelOrNull(array $channel, bool $enabled): ?array
    {
        if (! $enabled) {
            return null;
        }

        foreach ($channel as $value) {
            if ($value === null || $value === '') {
                return null;
            }
        }

        return $channel;
    }
}
