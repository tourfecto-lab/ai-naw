<?php

/**
 * View: Settings - audit section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_audit" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tAuditTitle}</h3></div>
                <p class="p-cell-muted">{$tAuditDesc}</p>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0;align-items:end;">
                    <div class="form-group" style="flex:1;min-width:180px;margin:0;">
                        <input type="text" id="auditSearch" class="form-control" placeholder="{$tAuditSearchPlaceholder}">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="auditFrom">{$tAuditFrom}</label>
                        <input type="date" id="auditFrom" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="auditTo">{$tAuditTo}</label>
                        <input type="date" id="auditTo" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="auditResult">{$tAuditColResult}</label>
                        <select id="auditResult" class="form-control">
                            <option value="">{$tAuditAllResults}</option>
                            <option value="success">{$tAuditResultSuccess}</option>
                            <option value="failed">{$tAuditResultFailed}</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="auditAction">{$tAuditColAction}</label>
                        <input type="text" id="auditAction" class="form-control" placeholder="{$tAuditActionPlaceholder}">
                    </div>
                    <button type="button" class="p-btn outline" id="auditFilterBtn">{$tAuditFilterBtn}</button>
                    <button type="button" class="p-btn outline" id="auditExportBtn">⬇ {$tAuditExportBtn}</button>
                </div>

                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
                        <thead>
                            <tr style="border-bottom:1px solid rgba(255,255,255,.1);">
                                <th style="padding:8px;">{$tAuditColAction}</th>
                                <th style="padding:8px;">{$tAuditColObject}</th>
                                <th style="padding:8px;">{$tAuditColResult}</th>
                                <th style="padding:8px;">{$tAuditColTime}</th>
                            </tr>
                        </thead>
                        <tbody id="auditLogBody">
                            <tr><td colspan="4" class="p-cell-muted" style="padding:14px;">{$tBillingLoading}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex;justify-content:center;gap:12px;margin-top:14px;align-items:center;">
                    <button type="button" class="p-btn outline xs" id="auditPrevBtn">← {$tAuditPrev}</button>
                    <span id="auditPageInfo" class="p-cell-muted" style="font-size:12.5px;"></span>
                    <button type="button" class="p-btn outline xs" id="auditNextBtn">{$tAuditNext} →</button>
                </div>
            </div>
        </div>

        <!-- الـ Workspace -->
HTML;
