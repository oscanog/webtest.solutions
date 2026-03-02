USE bug_catcher;

INSERT INTO labels (id, name, description, color) VALUES
  (1, 'bug', 'Something is not working', '#d73a4a'),
  (2, 'documentation', 'Improvements or additions to documentation', '#0075ca'),
  (3, 'duplicate', 'This issue already exists', '#cfd3d7'),
  (4, 'enhancement', 'New feature or request', '#a2eeef'),
  (5, 'good first issue', 'Good for newcomers', '#7057ff'),
  (6, 'help wanted', 'Extra attention is needed', '#008672'),
  (7, 'invalid', 'This does not seem right', '#e4e669'),
  (8, 'question', 'Further information is requested', '#d876e3'),
  (9, 'wontfix', 'This will not be worked on', '#000000')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  color = VALUES(color);
