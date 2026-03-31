<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/orgs.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/issues.php';
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/realtime.php';
require_once __DIR__ . '/admin_ai.php';
require_once __DIR__ . '/admin_openclaw.php';
require_once __DIR__ . '/ai_chat.php';
require_once __DIR__ . '/checklist.php';
require_once __DIR__ . '/aliases.php';

function bc_v1_routes(): array
{
    return [
        ['method' => 'GET', 'pattern' => '/', 'handler' => static function (mysqli $conn, array $params): void {
            bc_v1_json_success([
                'name' => 'BugCatcher API',
                'version' => 'v1',
                'status' => 'ok',
            ]);
        }],
        ['method' => 'GET', 'pattern' => '/health', 'handler' => static function (mysqli $conn, array $params): void {
            bc_v1_json_success(['status' => 'ok']);
        }],

        ['method' => 'POST', 'pattern' => '/auth/login', 'handler' => 'bc_v1_auth_login'],
        ['method' => 'POST', 'pattern' => '/auth/signup', 'handler' => 'bc_v1_auth_signup'],
        ['method' => 'POST', 'pattern' => '/auth/refresh', 'handler' => 'bc_v1_auth_refresh'],
        ['method' => 'POST', 'pattern' => '/auth/logout', 'handler' => 'bc_v1_auth_logout'],
        ['method' => 'GET', 'pattern' => '/auth/me', 'handler' => 'bc_v1_auth_me'],
        ['method' => 'PATCH', 'pattern' => '/auth/profile', 'handler' => 'bc_v1_auth_profile_patch'],
        ['method' => 'POST', 'pattern' => '/auth/change-password', 'handler' => 'bc_v1_auth_change_password'],
        ['method' => 'POST', 'pattern' => '/auth/forgot/request-otp', 'handler' => 'bc_v1_auth_forgot_request_otp'],
        ['method' => 'POST', 'pattern' => '/auth/forgot/resend-otp', 'handler' => 'bc_v1_auth_forgot_resend_otp'],
        ['method' => 'POST', 'pattern' => '/auth/forgot/verify-otp', 'handler' => 'bc_v1_auth_forgot_verify_otp'],
        ['method' => 'POST', 'pattern' => '/auth/forgot/reset-password', 'handler' => 'bc_v1_auth_forgot_reset_password'],
        ['method' => 'PUT', 'pattern' => '/session/active-org', 'handler' => 'bc_v1_session_active_org_put'],
        ['method' => 'GET', 'pattern' => '/dashboard/summary', 'handler' => 'bc_v1_dashboard_summary_get'],

        ['method' => 'GET', 'pattern' => '/orgs', 'handler' => 'bc_v1_orgs_get'],
        ['method' => 'POST', 'pattern' => '/orgs', 'handler' => 'bc_v1_orgs_post'],
        ['method' => 'GET', 'pattern' => '/orgs/{id}/members', 'handler' => 'bc_v1_orgs_members_get'],
        ['method' => 'POST', 'pattern' => '/orgs/{id}/members', 'handler' => 'bc_v1_orgs_members_post'],
        ['method' => 'POST', 'pattern' => '/orgs/{id}/join', 'handler' => 'bc_v1_orgs_join_post'],
        ['method' => 'POST', 'pattern' => '/orgs/{id}/leave', 'handler' => 'bc_v1_orgs_leave_post'],
        ['method' => 'POST', 'pattern' => '/orgs/{id}/transfer-owner', 'handler' => 'bc_v1_orgs_transfer_owner_post'],
        ['method' => 'DELETE', 'pattern' => '/orgs/{id}', 'handler' => 'bc_v1_orgs_delete'],
        ['method' => 'PATCH', 'pattern' => '/orgs/{id}/members/{userId}/role', 'handler' => 'bc_v1_orgs_member_role_patch'],
        ['method' => 'DELETE', 'pattern' => '/orgs/{id}/members/{userId}', 'handler' => 'bc_v1_orgs_member_delete'],

        ['method' => 'GET', 'pattern' => '/issues', 'handler' => 'bc_v1_issues_get'],
        ['method' => 'POST', 'pattern' => '/issues', 'handler' => 'bc_v1_issues_post'],
        ['method' => 'GET', 'pattern' => '/issues/{id}', 'handler' => 'bc_v1_issues_id_get'],
        ['method' => 'DELETE', 'pattern' => '/issues/{id}', 'handler' => 'bc_v1_issues_delete'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/assign-dev', 'handler' => 'bc_v1_issues_assign_dev_post'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/assign-junior', 'handler' => 'bc_v1_issues_assign_junior_post'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/junior-done', 'handler' => 'bc_v1_issues_junior_done_post'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/assign-qa', 'handler' => 'bc_v1_issues_assign_qa_post'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/report-senior-qa', 'handler' => 'bc_v1_issues_report_senior_qa_post'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/report-qa-lead', 'handler' => 'bc_v1_issues_report_qa_lead_post'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/qa-lead-approve', 'handler' => 'bc_v1_issues_qa_lead_approve_post'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/qa-lead-reject', 'handler' => 'bc_v1_issues_qa_lead_reject_post'],
        ['method' => 'POST', 'pattern' => '/issues/{id}/pm-close', 'handler' => 'bc_v1_issues_pm_close_post'],

        ['method' => 'GET', 'pattern' => '/projects', 'handler' => 'bc_v1_projects_get'],
        ['method' => 'POST', 'pattern' => '/projects', 'handler' => 'bc_v1_projects_post'],
        ['method' => 'GET', 'pattern' => '/projects/{id}', 'handler' => 'bc_v1_projects_id_get'],
        ['method' => 'PATCH', 'pattern' => '/projects/{id}', 'handler' => 'bc_v1_projects_id_patch'],
        ['method' => 'POST', 'pattern' => '/projects/{id}/archive', 'handler' => static function (mysqli $conn, array $params): void {
            bc_v1_projects_status_post($conn, $params, 'archived');
        }],
        ['method' => 'POST', 'pattern' => '/projects/{id}/activate', 'handler' => static function (mysqli $conn, array $params): void {
            bc_v1_projects_status_post($conn, $params, 'active');
        }],

        ['method' => 'GET', 'pattern' => '/notifications', 'handler' => 'bc_v1_notifications_get'],
        ['method' => 'POST', 'pattern' => '/notifications/read-all', 'handler' => 'bc_v1_notifications_read_all_post'],
        ['method' => 'POST', 'pattern' => '/notifications/{id}/read', 'handler' => 'bc_v1_notifications_read_post'],
        ['method' => 'POST', 'pattern' => '/realtime/socket-token', 'handler' => 'bc_v1_realtime_socket_token_post'],

        ['method' => 'GET', 'pattern' => '/admin/ai/runtime', 'handler' => 'bc_v1_admin_ai_runtime_get'],
        ['method' => 'PUT', 'pattern' => '/admin/ai/runtime', 'handler' => 'bc_v1_admin_ai_runtime_put'],
        ['method' => 'PATCH', 'pattern' => '/admin/ai/runtime', 'handler' => 'bc_v1_admin_ai_runtime_put'],
        ['method' => 'GET', 'pattern' => '/admin/ai/providers', 'handler' => 'bc_v1_admin_ai_providers_get'],
        ['method' => 'POST', 'pattern' => '/admin/ai/providers', 'handler' => 'bc_v1_admin_ai_providers_post'],
        ['method' => 'DELETE', 'pattern' => '/admin/ai/providers/{id}', 'handler' => 'bc_v1_admin_ai_providers_delete'],
        ['method' => 'GET', 'pattern' => '/admin/ai/models', 'handler' => 'bc_v1_admin_ai_models_get'],
        ['method' => 'POST', 'pattern' => '/admin/ai/models', 'handler' => 'bc_v1_admin_ai_models_post'],
        ['method' => 'DELETE', 'pattern' => '/admin/ai/models/{id}', 'handler' => 'bc_v1_admin_ai_models_delete'],

        ['method' => 'GET', 'pattern' => '/ai-chat/bootstrap', 'handler' => 'bc_v1_ai_chat_bootstrap_get'],
        ['method' => 'GET', 'pattern' => '/ai-chat/threads', 'handler' => 'bc_v1_ai_chat_threads_get'],
        ['method' => 'POST', 'pattern' => '/ai-chat/threads', 'handler' => 'bc_v1_ai_chat_threads_post'],
        ['method' => 'GET', 'pattern' => '/ai-chat/threads/{id}', 'handler' => 'bc_v1_ai_chat_threads_id_get'],
        ['method' => 'PATCH', 'pattern' => '/ai-chat/threads/{id}/draft-context', 'handler' => 'bc_v1_ai_chat_threads_id_draft_context_patch'],
        ['method' => 'POST', 'pattern' => '/ai-chat/threads/{id}/page-link-preview', 'handler' => 'bc_v1_ai_chat_threads_id_page_link_preview_post'],
        ['method' => 'POST', 'pattern' => '/ai-chat/threads/{id}/checklist-drafts', 'handler' => 'bc_v1_ai_chat_threads_id_checklist_drafts_post'],
        ['method' => 'DELETE', 'pattern' => '/ai-chat/threads/{id}', 'handler' => 'bc_v1_ai_chat_threads_id_delete'],
        ['method' => 'POST', 'pattern' => '/ai-chat/generated-items/{id}/approve', 'handler' => 'bc_v1_ai_chat_generated_items_id_approve_post'],
        ['method' => 'POST', 'pattern' => '/ai-chat/generated-items/{id}/reject', 'handler' => 'bc_v1_ai_chat_generated_items_id_reject_post'],
        ['method' => 'POST', 'pattern' => '/ai-chat/threads/{id}/messages/stream', 'handler' => 'bc_v1_ai_chat_threads_messages_stream_post'],

        ['method' => 'GET', 'pattern' => '/checklist/batches', 'handler' => 'bc_v1_checklist_batches_get'],
        ['method' => 'GET', 'pattern' => '/checklist/batches/{id}', 'handler' => 'bc_v1_checklist_batch_get'],
        ['method' => 'GET', 'pattern' => '/checklist/items/{id}', 'handler' => 'bc_v1_checklist_item_get'],
        ['method' => 'ANY', 'pattern' => '/checklist/batches', 'handler' => 'bc_v1_alias_checklist_batches'],
        ['method' => 'ANY', 'pattern' => '/checklist/batch', 'handler' => 'bc_v1_alias_checklist_batch'],
        ['method' => 'ANY', 'pattern' => '/checklist/batches/{id}', 'handler' => 'bc_v1_alias_checklist_batch_by_id'],
        ['method' => 'ANY', 'pattern' => '/checklist/items', 'handler' => 'bc_v1_alias_checklist_items'],
        ['method' => 'ANY', 'pattern' => '/checklist/item', 'handler' => 'bc_v1_alias_checklist_item'],
        ['method' => 'ANY', 'pattern' => '/checklist/items/{id}', 'handler' => 'bc_v1_alias_checklist_item_by_id'],
        ['method' => 'ANY', 'pattern' => '/checklist/item_status', 'handler' => 'bc_v1_alias_checklist_item_status'],
        ['method' => 'ANY', 'pattern' => '/checklist/item_attachments', 'handler' => 'bc_v1_alias_checklist_item_attachments'],
        ['method' => 'ANY', 'pattern' => '/checklist/item_attachment', 'handler' => 'bc_v1_alias_checklist_item_attachment'],
        ['method' => 'ANY', 'pattern' => '/checklist/item-attachments/{id}', 'handler' => 'bc_v1_alias_checklist_item_attachment_by_id'],
        ['method' => 'ANY', 'pattern' => '/checklist/batch_attachments', 'handler' => 'bc_v1_alias_checklist_batch_attachments'],
        ['method' => 'ANY', 'pattern' => '/checklist/batch_attachment', 'handler' => 'bc_v1_alias_checklist_batch_attachment'],
        ['method' => 'ANY', 'pattern' => '/checklist/batch-attachments/{id}', 'handler' => 'bc_v1_alias_checklist_batch_attachment_by_id'],

        ['method' => 'POST', 'pattern' => '/openclaw/checklist-duplicates', 'handler' => 'bc_v1_alias_openclaw_checklist_duplicates'],
        ['method' => 'POST', 'pattern' => '/openclaw/checklist_duplicates', 'handler' => 'bc_v1_alias_openclaw_checklist_duplicates'],
        ['method' => 'POST', 'pattern' => '/openclaw/checklist-batches', 'handler' => 'bc_v1_alias_openclaw_checklist_batches'],
        ['method' => 'POST', 'pattern' => '/openclaw/checklist_batches', 'handler' => 'bc_v1_alias_openclaw_checklist_batches'],
        ['method' => 'POST', 'pattern' => '/openclaw/checklist-ingest', 'handler' => 'bc_v1_alias_openclaw_checklist_ingest'],
        ['method' => 'POST', 'pattern' => '/openclaw/checklist_bot_ingest', 'handler' => 'bc_v1_alias_openclaw_checklist_ingest'],

        ['method' => 'GET', 'pattern' => '/admin/openclaw/runtime', 'handler' => 'bc_v1_admin_openclaw_runtime_get'],
        ['method' => 'PUT', 'pattern' => '/admin/openclaw/runtime', 'handler' => 'bc_v1_admin_openclaw_runtime_put'],
        ['method' => 'PATCH', 'pattern' => '/admin/openclaw/runtime', 'handler' => 'bc_v1_admin_openclaw_runtime_put'],
        ['method' => 'GET', 'pattern' => '/admin/openclaw/providers', 'handler' => 'bc_v1_admin_openclaw_providers_get'],
        ['method' => 'POST', 'pattern' => '/admin/openclaw/providers', 'handler' => 'bc_v1_admin_openclaw_providers_post'],
        ['method' => 'DELETE', 'pattern' => '/admin/openclaw/providers/{id}', 'handler' => 'bc_v1_admin_openclaw_providers_delete'],
        ['method' => 'GET', 'pattern' => '/admin/openclaw/models', 'handler' => 'bc_v1_admin_openclaw_models_get'],
        ['method' => 'POST', 'pattern' => '/admin/openclaw/models', 'handler' => 'bc_v1_admin_openclaw_models_post'],
        ['method' => 'DELETE', 'pattern' => '/admin/openclaw/models/{id}', 'handler' => 'bc_v1_admin_openclaw_models_delete'],
    ];
}
