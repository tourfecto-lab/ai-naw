<?php

/**
 * View: Settings - general section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_general" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tAccountPrefs}</h3></div>
                <form id="generalForm">
                    <div class="form-group">
                        <label class="form-label" for="language">{$tInterfaceLang}</label>
                        <select id="language" class="form-control">
                            {$languageOptionsHtml}
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="timezone">{$tTimezone}</label>
                        <select id="timezone" class="form-control">
                            {$timezoneOptionsHtml}
                        </select>
                    </div>
                    <div id="generalAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary">{$tSaveSettings}</button>
                </form>
            </div>

            <div class="p-card" style="margin-top:16px;border:1px solid #f3c6c6;">
                <div class="p-card-head"><h3 style="color:#c0392b;">⚠️ {$tDangerZone}</h3></div>

                <div id="leaveWorkspaceBox" style="display:none;padding:14px;border:1px solid #eee;border-radius:8px;margin-bottom:16px;">
                    <h4 style="margin:0 0 6px;font-size:14px;">{$tLeaveWorkspaceTitle}</h4>
                    <p class="p-cell-muted">{$tLeaveWorkspaceWarning}</p>
                    <form id="leaveWorkspaceForm" novalidate style="margin-top:10px;">
                        <div class="form-group">
                            <label class="form-label" for="leave_password">{$tConfirmPasswordLabel}</label>
                            <input type="password" id="leave_password" class="form-control" required aria-describedby="err_leave_password">
                            <p class="field-error" id="err_leave_password" role="alert"></p>
                        </div>
                        <div id="leaveWorkspaceAlert" class="alert alert-danger" style="display:none;"></div>
                        <button type="submit" class="p-btn outline">{$tLeaveWorkspaceBtn}</button>
                    </form>
                </div>

                <div style="padding:14px;border:1px solid #eee;border-radius:8px;margin-bottom:16px;">
                    <h4 style="margin:0 0 6px;font-size:14px;">{$tDeactivateTitle}</h4>
                    <p class="p-cell-muted">{$tDeactivateWarning}</p>
                    <form id="deactivateForm" novalidate style="margin-top:10px;">
                        <div class="form-group">
                            <label class="form-label" for="deactivate_password">{$tConfirmPasswordLabel}</label>
                            <input type="password" id="deactivate_password" class="form-control" required aria-describedby="err_deactivate_password">
                            <p class="field-error" id="err_deactivate_password" role="alert"></p>
                        </div>
                        <div id="deactivateAlert" class="alert alert-danger" style="display:none;"></div>
                        <button type="submit" class="p-btn outline">{$tDeactivateAccount}</button>
                    </form>
                </div>

                <div style="padding:14px;border:1px solid #f3c6c6;border-radius:8px;">
                    <h4 style="margin:0 0 6px;font-size:14px;color:#c0392b;">{$tDeleteAccount}</h4>
                    <p class="p-cell-muted">{$tDeleteWarning}</p>
                    <form id="deleteAccountForm" novalidate style="margin-top:10px;">
                        <div class="form-group">
                            <label class="form-label" for="delete_password">{$tConfirmPasswordLabel}</label>
                            <input type="password" id="delete_password" class="form-control" required aria-describedby="err_delete_password">
                            <p class="field-error" id="err_delete_password" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="delete_confirm_email">{$tConfirmEmailLabel}</label>
                            <input type="email" id="delete_confirm_email" class="form-control" required aria-describedby="err_delete_confirm_email">
                            <p class="p-cell-muted" style="font-size:12px;">{$tConfirmEmailHint}</p>
                            <p class="field-error" id="err_delete_confirm_email" role="alert"></p>
                        </div>
                        <div id="deleteAccountAlert" class="alert alert-danger" style="display:none;"></div>
                        <button type="submit" class="p-btn danger">{$tDeleteAccount}</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- الحسابات المرتبطة -->
HTML;
