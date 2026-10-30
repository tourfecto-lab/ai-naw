# AI Voice & Sales Agent: Phase 1, integration map

The voice module is part of Tourfecto, not a separate system. It adds only what did not exist and calls the existing services for everything else.

| Need | Reused (existing) | Added for voice |
|---|---|---|
| Accounts / tenant | `CrmPermissionService::resolveTenantId` (account owner = tenant) | `VoiceAccessService` |
| Roles | CRM roles (admin, manager, sales, support, viewer) | capability matrix per role |
| Plans / entitlements | `FeatureResolver`, `PlanFeature` catalogue | features `voice_agent` (off), `voice_max_numbers` |
| CRM | `CrmContactService`, `CrmLeadService`, `CrmTaskService`, `CrmNoteService` | via `VoicePostCallCrmGateway` |
| Bookings and payments | `BookingEngine`, `InventoryService`, `PaymentLinkService` | via the same gateway |
| WhatsApp / email | `CrmWhatsAppService`, `CrmEmailService` (unchanged) | via the same gateway |
| Automation | `CrmAutomationService::trigger('voice.call_completed')` | event only |
| Notifications | `Notification::notify` | via the gateway |
| Audit | `ActivityLog` (module `voice`) | none (no own table) |
| AI calls | `AIOrchestrator` (tasks `voice_agent`, `voice_postcall`) | prompts only |
| Provider settings | `app/Config/integrations.php` registry | **Phase 2** |
| AI CEO | tenant: `CeoAdvisorService`; platform: `PlatformSnapshot` | **Phase 8** |

Tenant rule: every voice table has `tenant_user_id`, no endpoint reads a tenant id from the request, and `tests/Unit/VoiceTenantIsolationTest.php` fails the build if a voice SQL statement lacks the tenant.
