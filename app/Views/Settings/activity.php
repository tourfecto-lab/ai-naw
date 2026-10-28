<?php

/**
 * View: Settings - activity section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_activity" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tActivityTitle}</h3></div>
                {$loginActivityHtml}
            </div>
        </div>

        <!-- الصلاحيات (Read-only) -->
HTML;
