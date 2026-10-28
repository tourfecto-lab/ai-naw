<?php

/**
 * View: Settings - header section (extracted Phase 16F).
 */
// تبويبات الربط والفوترة كانت نسخة من صفحاتها في القائمة - بقت لينكات
$tfSetAr = (function_exists('current_lang') ? current_lang() : 'ar') === 'ar';
$tLinkIntegrations = $tfSetAr ? 'الحسابات المربوطة' : 'Connected accounts';
$tLinkBilling = $tfSetAr ? 'الاشتراك والفواتير' : 'Subscription & billing';
// "سجل النشاط" كان مكتوب على تبويبين: ده سجل الدخول والأمان
$tTabAuditLabel = $tfSetAr ? 'سجل الأمان' : 'Security log';
return <<<HTML
        <script>window.TF_CSRF_TOKEN = "{$csrfToken}";</script>
        <style>
            /* Phase 14: تحويل تابات الإعدادات لـ Dropdown على الموبايل.
               مقصود إنها مربوطة بـ #settingsTabs/#settingsTabsMobile
               بالتحديد، مش .p-tabs/.p-tab العامة - الكلاسات دي مستخدمة
               في صفحات تانية ومش عايزين نغيّر سلوكها الافتراضي هناك. */
            #settingsTabsMobile { display: none; }
            @media (max-width: 640px) {
                #settingsTabs { display: none; }
                #settingsTabsMobile { display: block; width: 100%; margin-bottom: 14px; }
            }
        </style>
        <div class="p-tabs" id="settingsTabs">
            <button class="p-tab active" data-section="profile">👤 {$tTabProfile}</button>
            <button class="p-tab" data-section="security">🔒 {$tTabSecurity}</button>
            <button class="p-tab" data-section="notifications">🔔 {$tTabNotifications}</button>
            <button class="p-tab" data-section="api">🔑 {$tTabApi}</button>
            <button class="p-tab" data-section="audit">🛡️ {$tTabAuditLabel}</button>
            <button class="p-tab" data-section="workspace">🏢 {$tTabWorkspace}</button>
            <button class="p-tab" data-section="team">👥 {$tTabTeam}</button>
            <button class="p-tab" data-section="general">🌐 {$tTabGeneral}</button>
            <button class="p-tab" data-section="connected">🔗 {$tTabConnected}</button>
            <button class="p-tab" data-section="activity">📋 {$tTabActivity}</button>
            <button class="p-tab" data-section="permissions">🛡️ {$tTabPermissions}</button>
            <a class="p-tab" href="/integrations" style="text-decoration:none">🔌 {$tLinkIntegrations} ↗</a>
            <a class="p-tab" href="/subscription" style="text-decoration:none">💳 {$tLinkBilling} ↗</a>
        </div>

        <select id="settingsTabsMobile" aria-label="{$tTabsAriaLabel}">
            <option value="profile">👤 {$tTabProfile}</option>
            <option value="security">🔒 {$tTabSecurity}</option>
            <option value="notifications">🔔 {$tTabNotifications}</option>
            <option value="api">🔑 {$tTabApi}</option>
            <option value="audit">🛡️ {$tTabAuditLabel}</option>
            <option value="workspace">🏢 {$tTabWorkspace}</option>
            <option value="team">👥 {$tTabTeam}</option>
            <option value="general">🌐 {$tTabGeneral}</option>
            <option value="connected">🔗 {$tTabConnected}</option>
            <option value="activity">📋 {$tTabActivity}</option>
            <option value="permissions">🛡️ {$tTabPermissions}</option>
        </select>

HTML;
