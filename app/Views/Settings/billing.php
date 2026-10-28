<?php

/**
 * View: Settings - billing section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_billing" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tBillingPlanTitle}</h3></div>
                <div id="billingPlanBox">{$tBillingLoading}</div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tBillingWalletTitle}</h3></div>
                <div id="billingWalletBox">{$tBillingLoading}</div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tBillingInvoicesTitle}</h3></div>
                <div id="billingInvoicesBox">{$tBillingLoading}</div>
            </div>
        </div>

        <!-- سجل النشاط -->
HTML;
