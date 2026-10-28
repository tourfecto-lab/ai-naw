<?php

/**
 * View: Settings - team section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_team" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>👥 {$tTeamTitle}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">ℹ️ {$tTeamPermissionNote}</p>

                <div id="teamInviteBox" style="display:none;margin:14px 0;padding:14px;border:1px solid rgba(255,255,255,.1);border-radius:8px;">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                        <div class="form-group" style="flex:1;min-width:200px;margin:0;">
                            <label class="form-label" for="invite_email">{$tInviteEmail}</label>
                            <input type="email" id="invite_email" class="form-control">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="invite_role">{$tInviteRole}</label>
                            <select id="invite_role" class="form-control">
                                <option value="admin">admin</option>
                                <option value="manager">manager</option>
                                <option value="sales">sales</option>
                                <option value="support">support</option>
                                <option value="viewer" selected>viewer</option>
                            </select>
                        </div>
                        <button type="button" class="p-btn primary" id="sendInviteBtn">{$tInviteSend}</button>
                    </div>
                    <p class="field-error" id="err_invite_email" role="alert"></p>
                    <div id="inviteResultBox" style="display:none;margin-top:10px;padding:10px;background:#1e1e2e;border-radius:8px;font-size:12.5px;"></div>
                </div>

                <h4 style="font-size:13.5px;margin:18px 0 8px;">{$tPendingInvitesTitle}</h4>
                <div id="pendingInvitesList" class="p-cell-muted">{$tBillingLoading}</div>

                <h4 style="font-size:13.5px;margin:18px 0 8px;">{$tMembersTitle}</h4>
                <div id="membersList">{$tBillingLoading}</div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tPermissionMatrixTitle}</h3></div>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead><tr style="border-bottom:1px solid rgba(255,255,255,.1);">
                            <th style="padding:6px;text-align:start;">Role</th>
                            <th style="padding:6px;">manage_workspace</th>
                            <th style="padding:6px;">manage_team</th>
                            <th style="padding:6px;">view_billing</th>
                        </tr></thead>
                        <tbody>
                            <tr><td style="padding:6px;">admin</td><td style="text-align:center;">✅</td><td style="text-align:center;">✅</td><td style="text-align:center;">✅</td></tr>
                            <tr><td style="padding:6px;">manager</td><td style="text-align:center;">—</td><td style="text-align:center;">✅</td><td style="text-align:center;">✅</td></tr>
                            <tr><td style="padding:6px;">sales</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td></tr>
                            <tr><td style="padding:6px;">support</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td></tr>
                            <tr><td style="padding:6px;">viewer</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- اللغة والمنطقة -->
HTML;
