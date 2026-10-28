<?php

/**
 * View: Settings - integrations section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_integrations" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>🔌 {$tIntegrationsTitle}</h3></div>
                <p class="p-cell-muted">{$tIntegrationsDesc}</p>
                <div class="p-cell-muted" style="font-size:12.5px;margin:10px 0 16px;padding:12px;background:var(--panel-bg,#151521);border-radius:8px;">
                    💡 {$tIntegrationsListHint}
                </div>
                <a href="/integrations" class="p-btn primary">{$tIntegrationsManageBtn}</a>
            </div>
        </div>

        <!-- الفوترة -->
HTML;
