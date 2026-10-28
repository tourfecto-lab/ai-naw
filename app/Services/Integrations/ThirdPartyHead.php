<?php

/**
 * Tourfecto - Client-Side Integrations Head Snippets
 * @version 1.1.0
 *
 * بيرجّع كود HTML (Script tags) للخدمات اللي بتشتغل في المتصفح
 * (Hotjar/Contentsquare، Mixpanel، OneSignal، Calendly) عشان تتحقن في
 * <head> اللوحة. كل snippet بيتحقن بس لو الخدمة مفعّلة ومهيّأة.
 * القيم من صفحة التكاملات في الأدمن (system_settings) ليها الأولوية على .env:
 * قبل كده كان بيقرا .env بس، فالخدمة تتحفظ من الأدمن وتظهر "مُهيأ" ومابتتحقنش.
 */

class ThirdPartyHead
{
    /** قيمة الإعداد: الأدمن الأول (integration_xxx) وبعدين الثابت/الـ .env */
    private static function val(string $key): string
    {
        static $settings = null;
        if ($settings === null && class_exists('SystemSettingsService')) {
            try {
                $settings = new SystemSettingsService();
            } catch (Throwable $e) {
                $settings = false;
            }
        }
        if ($settings) {
            try {
                $v = trim((string) $settings->get('integration_' . strtolower($key), ''));
                if ($v !== '') {
                    return $v;
                }
            } catch (Throwable $e) {
            }
        }
        return defined($key) ? trim((string) constant($key)) : '';
    }

    private static function enabled(string $flag): bool
    {
        return filter_var(self::val($flag), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * إرجاع كل الـ snippets المفعّلة كـ HTML (سطر واحد لكل خدمة).
     * @param int $userId العميل الداخل: OneSignal بيسجّله بـ external_id عشان إشعاراته توصله هو بس
     */
    public static function render(int $userId = 0): string
    {
        $html = '';

        $tagId = self::val('CONTENTSQUARE_TAG_ID');
        if (self::enabled('CONTENTSQUARE_ENABLED') && $tagId !== '') {
            $tagId = htmlspecialchars($tagId, ENT_QUOTES, 'UTF-8');
            $html .= '<script async src="https://t.contentsquare.net/uxa/' . $tagId . '.js"></script>' . "\n";
        }

        $token = self::val('MIXPANEL_TOKEN');
        if (self::enabled('MIXPANEL_ENABLED') && $token !== '') {
            $token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
            $html .= '<script>' . "\n"
                . '(function(f,b){if(!b.__SV){var e,g,i,h;window.mixpanel=b;b._i=[];b.init=function(e,f,c){function g(a,d){var b=d.split(".");2==b.length&&(a=a[b[0]],d=b[1]);a[d]=function(){a.push([d].concat(Array.prototype.slice.call(arguments,0)))}}var a=b;"undefined"!==typeof c?a=b[c]=[]:c="mixpanel";a.people=a.people||[];a.toString=function(a){var d="mixpanel";"mixpanel"!==c&&(d+="."+c);a||(d+=" (stub)");return d};a.people.toString=function(){return a.toString(1)+".people (stub)"};i="disable time_event track track_pageview track_links track_forms track_with_groups register register_once alias unregister identify name_tag set_config reset opt_in_tracking opt_out_tracking has_opted_in_tracking has_opted_out_tracking clear_opt_in_out_tracking start_batch_senders people.set people.set_once people.unset people.increment people.append people.union people.track_charge people.clear_charges people.delete_user people.remove".split(" ");for(h=0;h<i.length;h++)g(a,i[h]);b._i.push([e,f,c])};b.__SV=1.2;e=f.createElement("script");e.type="text/javascript";e.async=!0;e.src="https://cdn.mxpnl.com/libs/mixpanel-2-latest.min.js";g=f.getElementsByTagName("script")[0];g.parentNode.insertBefore(e,g)}})(document,window.mixpanel||[]);' . "\n"
                . 'mixpanel.init("' . $token . '");' . "\n"
                . '</script>' . "\n";
        }

        $appId = self::val('ONESIGNAL_APP_ID');
        if (self::enabled('ONESIGNAL_ENABLED') && preg_match('/^[a-f0-9-]{36}$/i', $appId)) {
            // الـ Service Worker بتاع OneSignal متحمّل جوه /sw.js (نفس الـ PWA) عشان مايبقاش فيه اتنين على نفس النطاق
            $login = $userId > 0 && class_exists('OneSignalService')
                ? ' OneSignal.login(' . json_encode(OneSignalService::externalId($userId)) . ');'
                : '';
            $html .= '<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>' . "\n"
                . '<script>' . "\n"
                . 'window.OneSignalDeferred = window.OneSignalDeferred || [];' . "\n"
                . 'OneSignalDeferred.push(async function(OneSignal){ await OneSignal.init({ appId: ' . json_encode($appId) . ', serviceWorkerPath: "sw.js", serviceWorkerParam: { scope: "/" } });' . $login . ' });' . "\n"
                . '</script>' . "\n";
        }

        if (self::enabled('CALENDLY_ENABLED')) {
            $html .= '<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js" async></script>' . "\n";
        }

        return $html;
    }
}
