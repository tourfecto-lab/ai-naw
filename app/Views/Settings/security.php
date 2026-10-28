<?php

/**
 * View: Settings - security section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_security" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tPasswordStatus}</h3></div>
                <div class="p-kv"><span class="k">{$tPasswordStatus}</span><span class="v"><span class="p-badge status-active">{$tPasswordSet}</span></span></div>
                <div class="p-kv"><span class="k">{$tLastPasswordChange}</span><span class="v">{$lastPasswordChange}</span></div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tChangePassword}</h3></div>
                <form id="securityForm" novalidate>
                    <div class="form-group">
                        <label class="form-label" for="current_password">{$tCurrentPassword}</label>
                        <input type="password" id="current_password" class="form-control" required aria-describedby="err_current_password">
                        <p class="field-error" id="err_current_password" role="alert"></p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="new_password">{$tNewPassword}</label>
                        <input type="password" id="new_password" class="form-control" minlength="8" required aria-describedby="err_new_password">
                        <p class="field-error" id="err_new_password" role="alert"></p>
                    </div>
                    <div id="securityAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary" id="securitySaveBtn">{$tUpdatePassword}</button>
                    <span id="securitySavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);margin-inline-start:10px;">{$tSaving}</span>
                </form>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$t2FATitle}</h3></div>
                <div id="tfaAlert" class="alert alert-danger" style="display:none;"></div>

                <div id="tfaDisabledState" style="display:{$tfaDisabledDisplay};">
                    <p class="p-cell-muted">{$tTwoFactorDesc}</p>
                    <button type="button" class="p-btn primary" onclick="startTwoFactorSetup()">{$tEnableTwoFactor}</button>
                </div>

                <div id="tfaSetupState" style="display:none;">
                    <p class="p-cell-muted">{$tTwoFactorSetupHint}</p>
                    <div class="form-group">
                        <label class="form-label">{$tSetupKeyLabel}</label>
                        <code id="tfaSecretDisplay" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:12px;border-radius:8px;direction:ltr;text-align:left;word-break:break-all;"></code>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tfaConfirmCode">{$tConfirmCodeLabel}</label>
                        <input type="text" id="tfaConfirmCode" class="form-control" inputmode="numeric" maxlength="6" style="letter-spacing:3px;">
                    </div>
                    <button type="button" class="p-btn primary" onclick="confirmTwoFactorSetup()">{$tConfirmAndEnable}</button>
                </div>

                <div id="tfaRecoveryState" style="display:none;">
                    <p class="p-cell-muted">{$tRecoveryCodesHint}</p>
                    <div id="tfaRecoveryCodesList" style="background:#f7f7fb;padding:14px;border-radius:8px;font-family:monospace;direction:ltr;text-align:left;line-height:1.8;"></div>
                    <button type="button" class="p-btn primary" style="margin-top:12px;" onclick="acknowledgeRecoveryCodes()">{$tSavedRecoveryCodes}</button>
                </div>

                <div id="tfaEnabledState" style="display:{$tfaEnabledDisplay};">
                    <p style="color:#2e7d32;">✔ {$tTwoFactorEnabledLabel}</p>
                    <div class="form-group" style="max-width:320px;">
                        <label class="form-label" for="tfaDisablePassword">{$tConfirmPasswordFor2FA}</label>
                        <input type="password" id="tfaDisablePassword" class="form-control">
                    </div>
                    <button type="button" class="p-btn danger" onclick="disableTwoFactor()">{$tDisableTwoFactor}</button>

                    <hr style="border:none;border-top:1px solid var(--panel-border,#2a2a3a);margin:18px 0;">
                    <h4 style="margin:0 0 6px;">{$tRegenerateRecoveryCodes}</h4>
                    <p class="p-cell-muted" style="font-size:12.5px;">{$tRegenRecoveryHint}</p>
                    <div class="form-group" style="max-width:320px;margin-top:12px;">
                        <label class="form-label" for="regenRecoveryPassword">{$tConfirmPasswordFor2FA}</label>
                        <input type="password" id="regenRecoveryPassword" class="form-control" autocomplete="current-password">
                    </div>
                    <div class="form-group" style="max-width:320px;">
                        <label class="form-label" for="regenRecoveryCode">{$tConfirmCodeLabel}</label>
                        <input type="text" id="regenRecoveryCode" class="form-control" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
                    </div>
                    <button type="button" class="p-btn outline" onclick="regenerateRecoveryCodes()">{$tRegenerateRecoveryCodes}</button>
                    <div id="regenRecoveryCodesBox" style="display:none;background:#f7f7fb;padding:14px;border-radius:8px;font-family:monospace;direction:ltr;text-align:left;line-height:1.8;margin-top:12px;"></div>

                    <hr style="border:none;border-top:1px solid var(--panel-border,#2a2a3a);margin:18px 0;">
                    <h4 style="margin:0 0 6px;">{$tReEnroll2FA}</h4>
                    <p class="p-cell-muted" style="font-size:12.5px;">{$tReEnrollHint}</p>
                    <button type="button" class="p-btn outline" onclick="toggleReEnrollForm()">{$tReEnrollBtn}</button>
                    <div id="tfaReEnrollBox" style="display:none;margin-top:12px;">
                        <div class="form-group" style="max-width:320px;">
                            <label class="form-label" for="reEnrollPassword">{$tReEnrollPasswordLabel}</label>
                            <input type="password" id="reEnrollPassword" class="form-control" autocomplete="current-password">
                        </div>
                        <div class="form-group" style="max-width:320px;">
                            <label class="form-label" for="reEnrollCode">{$tReEnrollCodeLabel}</label>
                            <input type="text" id="reEnrollCode" class="form-control" inputmode="numeric" autocomplete="one-time-code">
                        </div>
                        <button type="button" class="p-btn primary" onclick="startReEnroll()">{$tReEnrollStart}</button>
                    </div>
                </div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head">
                    <h3>{$tSessionsTitle}</h3>
                    <button type="button" class="p-btn outline xs" id="logoutOthersBtn">{$tLogoutOthers}</button>
                </div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tSessionsHint}</p>
                <div id="sessionsList" style="margin-top:12px;">{$tSessionsLoading}</div>
            </div>
        </div>

        <!-- الإشعارات -->
HTML;
