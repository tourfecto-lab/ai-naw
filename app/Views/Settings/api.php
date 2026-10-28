<?php

/**
 * View: Settings - api section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_api" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tApiKeyTitle}</h3></div>
                <p class="p-cell-muted">{$tApiKeyDesc}</p>
                <code id="apiToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:14px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;margin:14px 0;">{$token}</code>
                <div style="display:flex;gap:10px;">
                    <button class="p-btn outline" onclick="copyToken()">📋 {$tCopyKey}</button>
                    <button class="p-btn outline" onclick="regenerateToken()">🔄 {$tRegenerateKey}</button>
                </div>
                <p class="p-cell-muted" style="font-size:12.5px;margin-top:10px;">⚠️ {$tRegenerateWarning}</p>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tPersonalKeysTitle}</h3></div>
                <p class="p-cell-muted">{$tPersonalKeysDesc}</p>

                <div style="display:flex;gap:10px;margin:14px 0;flex-wrap:wrap;">
                    <input type="text" id="newKeyName" class="form-control" placeholder="{$tKeyNamePlaceholder}" maxlength="120" style="flex:1;min-width:180px;">
                    <input type="number" id="newKeyExpiry" class="form-control" placeholder="{$tKeyExpiryDaysPlaceholder}" min="1" max="365" style="width:150px;">
                    <button type="button" class="p-btn primary" id="createKeyBtn">{$tCreateKey}</button>
                </div>
                <p class="p-cell-muted" style="font-size:12px;margin:-4px 0 0;">{$tKeyExpiryLabel}: <span style="color:var(--panel-text-muted,#888)">{$tKeyExpiresNever}</span></p>
                <div style="margin:12px 0 4px;">
                    <p class="p-cell-muted" style="font-size:12.5px;font-weight:600;">{$tKeyScopesTitle}</p>
                    <p class="p-cell-muted" style="font-size:12px;margin-bottom:8px;">{$tKeyScopesHint}</p>
                    <div id="keyScopesBox" style="display:flex;flex-direction:column;gap:6px;">
                        {$keyScopesCheckboxes}
                    </div>
                </div>
                <p class="field-error" id="err_key_name" role="alert"></p>
                <div id="newKeyRevealBox" style="display:none;background:#1e1e2e;padding:14px;border-radius:8px;margin-bottom:14px;">
                    <p class="p-cell-muted" style="font-size:12.5px;margin-bottom:8px;">⚠️ {$tRegenerateWarning}</p>
                    <code id="newKeyRaw" style="display:block;color:#a6e3a1;overflow-x:auto;direction:ltr;text-align:left;"></code>
                </div>

                <div id="apiKeysList">{$tKeysLoading}</div>
            </div>
        </div>

        <!-- التكاملات (Phase 13) - مؤشر لصفحة /integrations الحقيقية -->
HTML;
