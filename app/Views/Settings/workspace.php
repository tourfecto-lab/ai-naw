<?php

/**
 * View: Settings - workspace section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_workspace" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>🏢 {$tWorkspaceTitle}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">ℹ️ {$tWorkspaceScopeNote}</p>

                <div id="workspaceReadOnlyNotice" style="display:none;" class="p-cell-muted" style="font-size:12.5px;margin:10px 0;">⚠️ {$tWorkspaceReadOnlyNote}</div>

                <form id="workspaceForm" novalidate style="margin-top:14px;">
                    <div class="form-group">
                        <label class="form-label" for="ws_logo_preview">{$tWorkspaceLogo}</label>
                        <div style="display:flex;align-items:center;gap:14px;">
                            <img id="ws_logo_preview" src="" alt="" style="width:56px;height:56px;border-radius:10px;object-fit:cover;display:none;background:#222;">
                            <input type="file" id="ws_logo_input" accept="image/png,image/jpeg,image/webp">
                        </div>
                    </div>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="ws_name">{$tWorkspaceName}</label>
                            <input type="text" id="ws_name" name="name" class="form-control" maxlength="150" aria-describedby="err_ws_name">
                            <p class="field-error" id="err_ws_name" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ws_industry">{$tWorkspaceIndustry}</label>
                            <input type="text" id="ws_industry" name="industry" class="form-control" maxlength="100" aria-describedby="err_ws_industry">
                            <p class="field-error" id="err_ws_industry" role="alert"></p>
                        </div>
                    </div>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="ws_country">{$tWorkspaceCountry}</label>
                            <input type="text" id="ws_country" name="country_code" class="form-control" maxlength="5" placeholder="EG">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ws_timezone">{$tWorkspaceTimezone}</label>
                            <input type="text" id="ws_timezone" name="timezone" class="form-control" placeholder="Africa/Cairo">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ws_language">{$tWorkspaceLanguage}</label>
                        <select id="ws_language" name="default_language" class="form-control">
                            <option value="ar">العربية</option>
                            <option value="en">English</option>
                            <option value="fr">Français</option>
                            <option value="de">Deutsch</option>
                        </select>
                    </div>
                    <div id="workspaceAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary" id="workspaceSaveBtn">{$tSaveChanges}</button>
                    <span id="workspaceSavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);margin-inline-start:10px;">{$tSaving}</span>
                </form>
            </div>
        </div>

        <!-- الفريق -->
HTML;
