USE bug_catcher;

INSERT INTO users (id, username, email, password, role, created_at, last_active_org_id) VALUES
  (3, 'Zen', 'zen@gmail.com', '$2y$10$fZEUrsGS9bOxxHJx2uXH.Or5r9iZOBenrnEg86aHeLuekabgXK5wu', 'user', '2026-02-24 06:58:13', 4),
  (4, 'Pol', 'pol@gmail.com', '$2y$10$mrbNTwyw9wQtNkkoNTWhi.jFKrsV2h.xQNqzaE4gTGals5tMfeSYK', 'user', '2026-02-24 06:58:43', 4),
  (5, 'Endy', 'endy@gmail.com', '$2y$10$lt.owHlTl0CkmO1XIOTtNOxh4hs5qk.a3pGU0kgfR36igGifxddeG', 'user', '2026-02-24 08:46:10', 4),
  (6, 'Marsh', 'marsh@gmail.com', '$2y$10$oJwnRfuJzQ0CAs1Ficgu9uppmNoWC4JW8NidzI.eqbS9yGSxWMZBa', 'user', '2026-02-26 12:27:28', 4),
  (7, 'Null', 'null@gmail.com', '$2y$10$0ktfEQpHAbtR9ClqKlpLAumZPEERstsOdaDHgWVvVOllo6sD0hF6.', 'user', '2026-02-26 12:29:23', 4),
  (8, 'N', 'n@gmail.com', '$2y$10$yj29R1TEotpy9Vv4g8aT7O5kjcwVgi4ArlVFpzNzMlifXTzsu7Lc6', 'user', '2026-02-26 12:31:12', 4),
  (9, 'Dragon', 'dragon@gmail.com', '$2y$10$CFkKQj8j65yQ.8yylX.fjuyf8RxPWemJa8s9K0iS8TExN/HS2rlpO', 'user', '2026-02-26 12:32:30', 4)
ON DUPLICATE KEY UPDATE
  username = VALUES(username),
  email = VALUES(email),
  password = VALUES(password),
  role = VALUES(role),
  last_active_org_id = VALUES(last_active_org_id);

INSERT INTO organizations (id, name, owner_id, created_at) VALUES
  (4, 'Future Hope', 3, '2026-02-24 08:33:00'),
  (5, 'Technologia', 3, '2026-02-24 08:40:45'),
  (6, 'Umbral', 4, '2026-02-24 08:43:39')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  owner_id = VALUES(owner_id);

INSERT INTO org_members (org_id, user_id, role, joined_at) VALUES
  (4, 3, 'owner', '2026-02-24 08:33:00'),
  (4, 4, 'Senior Developer', '2026-02-24 08:43:01'),
  (4, 5, 'Project Manager', '2026-02-24 08:46:54'),
  (4, 6, 'Senior QA', '2026-02-26 12:28:33'),
  (4, 7, 'QA Lead', '2026-02-26 12:29:59'),
  (4, 8, 'Junior Developer', '2026-02-26 12:31:28'),
  (4, 9, 'QA Tester', '2026-02-26 12:32:51'),
  (5, 3, 'owner', '2026-02-24 08:40:45'),
  (5, 5, 'member', '2026-02-24 08:47:00'),
  (6, 3, 'member', '2026-02-24 08:48:16'),
  (6, 4, 'owner', '2026-02-24 08:43:39'),
  (6, 5, 'member', '2026-02-24 08:46:58')
ON DUPLICATE KEY UPDATE
  role = VALUES(role),
  joined_at = VALUES(joined_at);

INSERT INTO issues (
  id, title, description, status, author_id, org_id, assigned_dev_id, assign_status,
  assigned_at, created_at, assigned_junior_id, assigned_qa_id, assigned_senior_qa_id,
  assigned_qa_lead_id, junior_assigned_at, qa_assigned_at, senior_qa_assigned_at,
  qa_lead_assigned_at, junior_done_at, pm_id
) VALUES (
  6, 'CSS problem', 'CSS isn''t working, is it being overridden?', 'closed', 5, 4, 4, 'closed',
  '2026-03-01 21:08:11', '2026-03-01 12:31:30', 8, 9, 6,
  7, '2026-03-01 21:08:29', '2026-03-01 21:30:02', '2026-03-01 21:30:47',
  '2026-03-01 21:31:46', '2026-03-01 21:29:28', 5
)
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  description = VALUES(description),
  status = VALUES(status),
  author_id = VALUES(author_id),
  org_id = VALUES(org_id),
  assigned_dev_id = VALUES(assigned_dev_id),
  assign_status = VALUES(assign_status),
  assigned_at = VALUES(assigned_at),
  assigned_junior_id = VALUES(assigned_junior_id),
  assigned_qa_id = VALUES(assigned_qa_id),
  assigned_senior_qa_id = VALUES(assigned_senior_qa_id),
  assigned_qa_lead_id = VALUES(assigned_qa_lead_id),
  junior_assigned_at = VALUES(junior_assigned_at),
  qa_assigned_at = VALUES(qa_assigned_at),
  senior_qa_assigned_at = VALUES(senior_qa_assigned_at),
  qa_lead_assigned_at = VALUES(qa_lead_assigned_at),
  junior_done_at = VALUES(junior_done_at),
  pm_id = VALUES(pm_id);

INSERT INTO issue_labels (issue_id, label_id) VALUES
  (6, 1),
  (6, 6)
ON DUPLICATE KEY UPDATE
  label_id = VALUES(label_id);
