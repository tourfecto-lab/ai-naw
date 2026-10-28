<?php

/**
 * View: Settings - permissions section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_permissions" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tCurrentRoleLabel}: {$currentRoleLabel}</h3></div>
            </div>
            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tFeatureAccessLabel}</h3></div>
                <p class="p-cell-muted">{$tFeatureAccessDesc}</p>
                {$permissionsHtml}
            </div>
            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tPrivacyTitle}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tPrivacyDesc}</p>
                <a class="p-btn outline" href="/privacy" target="_blank" rel="noopener">{$tViewPrivacyPolicy}</a>
            </div>
            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tDataExportTitle}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tDataExportDesc}</p>
                <div id="exportAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="button" class="p-btn primary" onclick="requestDataExport()">{$tRequestExport}</button>
                <div id="exportsList" style="margin-top:14px;"></div>
            </div>
        </div>
HTML;
