<?php

declare(strict_types=1);

const BUGCATCHER_ISSUE_WORKFLOW_DEFAULT = 'unassigned';
const BUGCATCHER_ISSUE_WORKFLOW_CLOSED = 'closed';
const BUGCATCHER_ISSUE_WORKFLOW_STATES = [
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
const BUGCATCHER_ISSUE_WORKFLOW_FILTERS = ['all', 'open', 'closed'];
const BUGCATCHER_ISSUE_WORKFLOW_LABELS = [
    'unassigned' => 'Unassigned',
    'with_senior' => 'With Senior',
    'with_junior' => 'With Junior',
    'done_by_junior' => 'Ready for QA',
    'with_qa' => 'With QA',
    'with_senior_qa' => 'With Senior QA',
    'with_qa_lead' => 'With QA Lead',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'closed' => 'Closed',
];
const BUGCATCHER_ISSUE_WORKFLOW_LANES = [
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
    ['key' => 'with_senior', 'label' => 'With Senior', 'type' => 'workflow', 'states' => ['with_senior']],
    ['key' => 'with_junior', 'label' => 'With Junior', 'type' => 'workflow', 'states' => ['with_junior']],
    ['key' => 'done_by_junior', 'label' => 'Ready for QA', 'type' => 'workflow', 'states' => ['done_by_junior']],
    ['key' => 'with_qa', 'label' => 'With QA', 'type' => 'workflow', 'states' => ['with_qa']],
    ['key' => 'with_senior_qa', 'label' => 'With Senior QA', 'type' => 'workflow', 'states' => ['with_senior_qa']],
    ['key' => 'with_qa_lead', 'label' => 'With QA Lead', 'type' => 'workflow', 'states' => ['with_qa_lead']],
    ['key' => 'approved', 'label' => 'Approved', 'type' => 'workflow', 'states' => ['approved']],
    ['key' => 'rejected', 'label' => 'Rejected', 'type' => 'workflow', 'states' => ['rejected']],
    ['key' => 'closed', 'label' => 'Closed', 'type' => 'workflow', 'states' => ['closed']],
];

function bugcatcher_issue_workflow_default(): string
{
    return BUGCATCHER_ISSUE_WORKFLOW_DEFAULT;
}

function bugcatcher_issue_workflow_states(): array
{
    return BUGCATCHER_ISSUE_WORKFLOW_STATES;
}

function bugcatcher_issue_workflow_filters(): array
{
    return BUGCATCHER_ISSUE_WORKFLOW_FILTERS;
}

function bugcatcher_issue_workflow_lanes(): array
{
    return BUGCATCHER_ISSUE_WORKFLOW_LANES;
}

function bugcatcher_issue_workflow_normalize(?string $value): string
{
    $trimmed = trim((string) $value);
    return in_array($trimmed, BUGCATCHER_ISSUE_WORKFLOW_STATES, true)
        ? $trimmed
        : BUGCATCHER_ISSUE_WORKFLOW_DEFAULT;
}

function bugcatcher_issue_workflow_filter(?string $value): string
{
    $trimmed = trim((string) $value);
    return in_array($trimmed, BUGCATCHER_ISSUE_WORKFLOW_FILTERS, true)
        ? $trimmed
        : 'open';
}

function bugcatcher_issue_workflow_is_closed(?string $value): bool
{
    return bugcatcher_issue_workflow_normalize($value) === BUGCATCHER_ISSUE_WORKFLOW_CLOSED;
}

function bugcatcher_issue_workflow_is_active(?string $value): bool
{
    return !bugcatcher_issue_workflow_is_closed($value);
}

function bugcatcher_issue_workflow_status_alias(?string $value): string
{
    return bugcatcher_issue_workflow_is_closed($value) ? 'closed' : 'open';
}

function bugcatcher_issue_workflow_assign_status_alias(?string $value): string
{
    return bugcatcher_issue_workflow_normalize($value);
}

function bugcatcher_issue_workflow_label(?string $value): string
{
    $workflowStatus = bugcatcher_issue_workflow_normalize($value);
    return BUGCATCHER_ISSUE_WORKFLOW_LABELS[$workflowStatus] ?? 'Unassigned';
}

function bugcatcher_issue_workflow_matches_filter(?string $workflowStatus, string $filter): bool
{
    $normalizedFilter = bugcatcher_issue_workflow_filter($filter);
    if ($normalizedFilter === 'all') {
        return true;
    }
    if ($normalizedFilter === 'closed') {
        return bugcatcher_issue_workflow_is_closed($workflowStatus);
    }
    return bugcatcher_issue_workflow_is_active($workflowStatus);
}

function bugcatcher_issue_workflow_filter_sql(string $column, string $filter): string
{
    $normalizedFilter = bugcatcher_issue_workflow_filter($filter);
    if ($normalizedFilter === 'all') {
        return '1=1';
    }
    if ($normalizedFilter === 'closed') {
        return $column . " = 'closed'";
    }
    return $column . " <> 'closed'";
}

function bugcatcher_issue_workflow_can_assign_dev(?string $workflowStatus): bool
{
    $normalized = bugcatcher_issue_workflow_normalize($workflowStatus);
    return in_array($normalized, ['unassigned', 'rejected'], true);
}

function bugcatcher_issue_workflow_can_assign_junior(?string $workflowStatus): bool
{
    return bugcatcher_issue_workflow_normalize($workflowStatus) === 'with_senior';
}

function bugcatcher_issue_workflow_can_mark_junior_done(?string $workflowStatus): bool
{
    return bugcatcher_issue_workflow_normalize($workflowStatus) === 'with_junior';
}

function bugcatcher_issue_workflow_can_assign_qa(?string $workflowStatus): bool
{
    return bugcatcher_issue_workflow_normalize($workflowStatus) === 'done_by_junior';
}

function bugcatcher_issue_workflow_can_report_senior_qa(?string $workflowStatus): bool
{
    return bugcatcher_issue_workflow_normalize($workflowStatus) === 'with_qa';
}

function bugcatcher_issue_workflow_can_report_qa_lead(?string $workflowStatus): bool
{
    return bugcatcher_issue_workflow_normalize($workflowStatus) === 'with_senior_qa';
}

function bugcatcher_issue_workflow_can_qa_lead_decide(?string $workflowStatus): bool
{
    return bugcatcher_issue_workflow_normalize($workflowStatus) === 'with_qa_lead';
}

function bugcatcher_issue_workflow_can_pm_close(?string $workflowStatus): bool
{
    return bugcatcher_issue_workflow_normalize($workflowStatus) === 'approved';
}
