UPDATE ai_chat_threads
SET page_link_status = NULL,
    page_link_warning = NULL,
    page_link_basic_auth_username = NULL,
    page_link_basic_auth_password = NULL
WHERE page_link_status = 'unsupported_auth'
  AND page_link_warning = 'This page looks like a login screen or SSO gateway. BugCatcher v1 only supports public pages or HTTP Basic Auth.';
