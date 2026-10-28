<?php

/**
 * View: Settings - profile section (extracted Phase 16F).
 */
return <<<HTML
        <!-- الملف الشخصي -->
        <div class="settings-section" id="section_profile">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tAvatarTitle}</h3></div>
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div id="avatarPreviewWrap" style="width:84px;height:84px;border-radius:50%;overflow:hidden;background:var(--panel-accent,#f59e0b);display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:700;color:#fff;flex-shrink:0;">
                        {$this->avatarInnerHtml($avatarUrl, $initials)}
                    </div>
                    <div>
                        <input type="file" id="avatarInput" accept="image/png,image/jpeg,image/webp" style="display:none;">
                        <button type="button" class="p-btn outline xs" onclick="document.getElementById('avatarInput').click()">📷 {$tChangePhoto}</button>
                        <p class="p-cell-muted" style="font-size:12.5px;margin-top:6px;">{$tAvatarHint}</p>
                    </div>
                </div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tAccountInfo}</h3></div>
                <div class="p-kv"><span class="k">{$tEmail}</span><span class="v" style="direction:ltr;display:inline-block;">{$email}</span></div>
                <div class="p-kv"><span class="k">{$tAccountId}</span><span class="v" style="direction:ltr;display:inline-block;">#{$accountId}</span></div>
                <div class="p-kv"><span class="k">{$tRole}</span><span class="v">{$role}</span></div>
                <div class="p-kv"><span class="k">{$tAccountStatus}</span><span class="v"><span class="p-badge status-{$accountStatusRaw}">{$accountStatus}</span></span></div>
                <div class="p-kv"><span class="k">{$tMemberSince}</span><span class="v">{$memberSince}</span></div>
                <div class="p-kv"><span class="k">{$tLastLogin}</span><span class="v">{$lastLogin}</span></div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tEditData}</h3></div>
                <form id="profileForm" novalidate>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="first_name">{$tFirstName}</label>
                            <input type="text" id="first_name" name="first_name" class="form-control" value="{$firstName}" maxlength="100" aria-describedby="err_first_name">
                            <p class="field-error" id="err_first_name" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="last_name">{$tLastName}</label>
                            <input type="text" id="last_name" name="last_name" class="form-control" value="{$lastName}" maxlength="100" aria-describedby="err_last_name">
                            <p class="field-error" id="err_last_name" role="alert"></p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="display_name">{$tDisplayName}</label>
                        <input type="text" id="display_name" name="display_name" class="form-control" value="{$displayName}" maxlength="120" aria-describedby="err_display_name">
                        <p class="p-cell-muted" style="font-size:12.5px;margin-top:4px;">{$tDisplayNameHint}</p>
                        <p class="field-error" id="err_display_name" role="alert"></p>
                    </div>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="company_name">{$tCompanyName}</label>
                            <input type="text" id="company_name" name="company_name" class="form-control" value="{$companyName}" maxlength="150" aria-describedby="err_company_name">
                            <p class="field-error" id="err_company_name" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="job_title">{$tJobTitle}</label>
                            <input type="text" id="job_title" name="job_title" class="form-control" value="{$jobTitle}" maxlength="120" aria-describedby="err_job_title">
                            <p class="field-error" id="err_job_title" role="alert"></p>
                        </div>
                    </div>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="phone">{$tPhone}</label>
                            <input type="text" id="phone" name="phone" class="form-control" value="{$phone}" maxlength="20" aria-describedby="err_phone">
                            <p class="field-error" id="err_phone" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="country_code">{$tCountryCode}</label>
                            <select id="country_code" name="country_code" class="form-control" aria-describedby="err_country_code">
                                {$countryOptionsHtml}
                            </select>
                            <p class="field-error" id="err_country_code" role="alert"></p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="currency">{$tCurrency}</label>
                        <select id="currency" name="currency" class="form-control" aria-describedby="err_currency">
                            {$currencyOptionsHtml}
                        </select>
                        <p class="field-error" id="err_currency" role="alert"></p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bio">{$tBio}</label>
                        <textarea id="bio" name="bio" class="form-control" rows="3" maxlength="500" aria-describedby="err_bio">{$bio}</textarea>
                        <p class="p-cell-muted" style="font-size:12.5px;margin-top:4px;"><span id="bioCount">0</span>/500 — {$tBioHint}</p>
                        <p class="field-error" id="err_bio" role="alert"></p>
                    </div>
                    <div id="profileAlert" class="alert alert-danger" style="display:none;" role="alert"></div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="submit" class="p-btn primary" id="profileSaveBtn">{$tSaveChanges}</button>
                        <button type="button" class="p-btn outline" id="profileCancelBtn">{$tCancel}</button>
                        <span id="profileSavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);">{$tSaving}</span>
                    </div>
                </form>
            </div>
        </div>

        <!-- الأمان -->
HTML;
