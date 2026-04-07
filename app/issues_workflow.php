<?php

declare(strict_types=1);

const WEBTEST_ISSUE_WORKFLOW_DEFAULT = 'unassigned';
const WEBTEST_ISSUE_WORKFLOW_CLOSED = 'closed';
const WEBTEST_ISSUE_WORKFLOW_STATES = [
    'unassigned',
    'with_senior',
    'with_junior',
    'done_by_junior',
    'with_qa',
    'with_senior_qa',
    'with_qa_lead',
    'approved',
    'rejected',
    'closed',
];
const WEBTEST_ISSUE_WORKFLOW_FILTERS = ['all', 'open', 'closed'];
const WEBTEST_ISSUE_WORKFLOW_LABELS = [
    'unassigned' => 'Unassigned',
    'with_senior' => 'With Senior Dev',
    'with_junior' => 'With Junior Dev',
    'done_by_junior' => 'Ready for QA',
    'with_qa' => 'With QA',
    'with_senior_qa' => 'With Senior QA',
    'with_qa_lead' => 'With QA Lead',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'closed' => 'Closed',
];
const WEBTEST_ISSUE_WORKFLOW_LANES = [
    ['key' => 'open', 'label' => 'Open', 'type' => 'overview', 'states' => [
        'unassigned',
        'with_senior',
        'with_junior',
        'done_by_junior',
        'with_qa',
        'with_senior_qa',
        'with_qa_lead',
        'approved',
        'rejected',
    ]],
    ['key' => 'unassigned', 'label' => 'Unassigned', 'type' => 'workflow', 'states' => ['unassigned']],
    ['key' => 'with_senior', 'label' => 'With Senior Dev', 'type' => 'workflow', 'states' => ['with_senior']],
    ['key' => 'with_junior', 'label' => 'With Junior Dev', 'type' => 'workflow', 'states' => ['with_junior']],
    ['key' => 'done_by_junior', 'label' => 'Ready for QA', 'type' => 'workflow', 'states' => ['done_by_junior']],
    ['key' => 'with_qa', 'label' => 'With QA', 'type' => 'workflow', 'states' => ['with_qa']],
    ['key' => 'with_senior_qa', 'label' => 'With Senior QA', 'type' => 'workflow', 'states' => ['with_senior_qa']],
    ['key' => 'with_qa_lead', 'label' => 'With QA Lead', 'type' => 'workflow', 'states' => ['with_qa_lead']],
    ['key' => 'approved', 'label' => 'Approved', 'type' => 'workflow', 'states' => ['approved']],
    ['key' => 'rejected', 'label' => 'Rejected', 'type' => 'workflow', 'states' => ['rejected']],
    ['key' => 'closed', 'label' => 'Closed', 'type' => 'workflow', 'states' => ['closed']],
];

function webtest_issue_workflow_default(): string
{
    return WEBTEST_ISSUE_WORKFLOW_DEFAULT;
}

function webtest_issue_workflow_states(): array
{
    return WEBTEST_ISSUE_WORKFLOW_STATES;
}

function webtest_issue_workflow_filters(): array
{
    return WEBTEST_ISSUE_WORKFLOW_FILTERS;
}

function webtest_issue_workflow_lanes(): array
{
    return WEBTEST_ISSUE_WORKFLOW_LANES;
}

function webtest_issue_workflow_normalize(?string $value): string
{
    $trimmed = trim((string) $value);
    return in_array($trimmed, WEBTEST_ISSUE_WORKFLOW_STATES, true)
        ? $trimmed
        : WEBTEST_ISSUE_WORKFLOW_DEFAULT;
}

function webtest_issue_workflow_filter(?string $value): string
{
    $trimmed = trim((string) $value);
    return in_array($trimmed, WEBTEST_ISSUE_WORKFLOW_FILTERS, true)
        ? $trimmed
        : 'open';
}

function webtest_issue_workflow_is_closed(?string $value): bool
{
    return webtest_issue_workflow_normalize($value) === WEBTEST_ISSUE_WORKFLOW_CLOSED;
}

function webtest_issue_workflow_is_active(?string $value): bool
{
    return !webtest_issue_workflow_is_closed($value);
}

function webtest_issue_workflow_status_alias(?string $value): string
{
    return webtest_issue_workflow_is_closed($value) ? 'closed' : 'open';
}

function webtest_issue_workflow_assign_status_alias(?string $value): string
{
    return webtest_issue_workflow_normalize($value);
}

function webtest_issue_workflow_label(?string $value): string
{
    $workflowStatus = webtest_issue_workflow_normalize($value);
    return WEBTEST_ISSUE_WORKFLOW_LABELS[$workflowStatus] ?? 'Unassigned';
}

function webtest_issue_workflow_matches_filter(?string $workflowStatus, string $filter): bool
{
    $normalizedFilter = webtest_issue_workflow_filter($filter);
    if ($normalizedFilter === 'all') {
        return true;
    }
    if ($normalizedFilter === 'closed') {
        return webtest_issue_workflow_is_closed($workflowStatus);
    }
    return webtest_issue_workflow_is_active($workflowStatus);
}

function webtest_issue_workflow_filter_sql(string $column, string $filter): string
{
    $normalizedFilter = webtest_issue_workflow_filter($filter);
    if ($normalizedFilter === 'all') {
        return '1=1';
    }
    if ($normalizedFilter === 'closed') {
        return $column . " = 'closed'";
    }
    return $column . " <> 'closed'";
}

function webtest_issue_workflow_can_assign_dev(?string $workflowStatus): bool
{
    $normalized = webtest_issue_workflow_normalize($workflowStatus);
    return in_array($normalized, ['unassigned', 'rejected'], true);
}

function webtest_issue_workflow_can_assign_junior(?string $workflowStatus): bool
{
    return webtest_issue_workflow_normalize($workflowStatus) === 'with_senior';
}

function webtest_issue_workflow_can_mark_junior_done(?string $workflowStatus): bool
{
    return webtest_issue_workflow_normalize($workflowStatus) === 'with_junior';
}

function webtest_issue_workflow_can_assign_qa(?string $workflowStatus): bool
{
    return webtest_issue_workflow_normalize($workflowStatus) === 'done_by_junior';
}

function webtest_issue_workflow_can_report_senior_qa(?string $workflowStatus): bool
{
    return webtest_issue_workflow_normalize($workflowStatus) === 'with_qa';
}

function webtest_issue_workflow_can_report_qa_lead(?string $workflowStatus): bool
{
    return webtest_issue_workflow_normalize($workflowStatus) === 'with_senior_qa';
}

function webtest_issue_workflow_can_qa_lead_decide(?string $workflowStatus): bool
{
    return webtest_issue_workflow_normalize($workflowStatus) === 'with_qa_lead';
}

function webtest_issue_workflow_can_pm_close(?string $workflowStatus): bool
{
    return webtest_issue_workflow_normalize($workflowStatus) === 'approved';
}
