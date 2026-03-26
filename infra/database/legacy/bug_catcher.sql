-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 01, 2026 at 02:51 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bug_catcher`
--

-- --------------------------------------------------------

--
-- Table structure for table `contact`
--

CREATE TABLE `contact` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issues`
--

CREATE TABLE `issues` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `org_id` int(11) NOT NULL,
  `assigned_dev_id` int(11) DEFAULT NULL,
  `workflow_status` enum('unassigned','with_senior','with_junior','done_by_junior','with_qa','with_senior_qa','with_qa_lead','approved','rejected','closed') NOT NULL DEFAULT 'unassigned',
  `assigned_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_junior_id` int(11) DEFAULT NULL,
  `assigned_qa_id` int(11) DEFAULT NULL,
  `assigned_senior_qa_id` int(11) DEFAULT NULL,
  `assigned_qa_lead_id` int(11) DEFAULT NULL,
  `junior_assigned_at` datetime DEFAULT NULL,
  `qa_assigned_at` datetime DEFAULT NULL,
  `senior_qa_assigned_at` datetime DEFAULT NULL,
  `qa_lead_assigned_at` datetime DEFAULT NULL,
  `junior_done_at` datetime DEFAULT NULL,
  `pm_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issues`
--

INSERT INTO `issues` (`id`, `title`, `description`, `author_id`, `org_id`, `assigned_dev_id`, `workflow_status`, `assigned_at`, `created_at`, `assigned_junior_id`, `assigned_qa_id`, `assigned_senior_qa_id`, `assigned_qa_lead_id`, `junior_assigned_at`, `qa_assigned_at`, `senior_qa_assigned_at`, `qa_lead_assigned_at`, `junior_done_at`, `pm_id`) VALUES
(6, 'CSS problem', 'CSS isn\'t working, is it being overridden?', 5, 4, 4, 'closed', '2026-03-01 21:08:11', '2026-03-01 12:31:30', 8, 9, 6, 7, '2026-03-01 21:08:29', '2026-03-01 21:30:02', '2026-03-01 21:30:47', '2026-03-01 21:31:46', '2026-03-01 21:29:28', 5);

-- --------------------------------------------------------

--
-- Table structure for table `issue_attachments`
--

CREATE TABLE `issue_attachments` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issue_attachments`
--

INSERT INTO `issue_attachments` (`id`, `issue_id`, `file_path`, `original_name`, `mime_type`, `file_size`, `uploaded_at`) VALUES
(1, 6, 'uploads/issues/issue_6_72f65a5636b0616a.png', 'Screenshot_2026-02-26_215554.png', 'image/png', 31777, '2026-03-01 20:31:30');

-- --------------------------------------------------------

--
-- Table structure for table `issue_labels`
--

CREATE TABLE `issue_labels` (
  `issue_id` int(11) NOT NULL,
  `label_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issue_labels`
--

INSERT INTO `issue_labels` (`issue_id`, `label_id`) VALUES
(6, 1),
(6, 6);

-- --------------------------------------------------------

--
-- Table structure for table `labels`
--

CREATE TABLE `labels` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `color` varchar(20) DEFAULT '#cccccc'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `labels`
--

INSERT INTO `labels` (`id`, `name`, `description`, `color`) VALUES
(1, 'bug', 'Something is not working', '#d73a4a'),
(2, 'documentation', 'Improvements or additions to documentation', '#0075ca'),
(3, 'duplicate', 'This issue already exists', '#cfd3d7'),
(4, 'enhancement', 'New feature or request', '#a2eeef'),
(5, 'good first issue', 'Good for newcomers', '#7057ff'),
(6, 'help wanted', 'Extra attention is needed', '#008672'),
(7, 'invalid', 'This does not seem right', '#e4e669'),
(8, 'question', 'Further information is requested', '#d876e3'),
(9, 'wontfix', 'This will not be worked on', '#000000');

-- --------------------------------------------------------

--
-- Table structure for table `organizations`
--

CREATE TABLE `organizations` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `organizations`
--

INSERT INTO `organizations` (`id`, `name`, `owner_id`, `created_at`) VALUES
(4, 'Future Hope', 3, '2026-02-24 08:33:00'),
(5, 'Technologia', 3, '2026-02-24 08:40:45'),
(6, 'Umbral', 4, '2026-02-24 08:43:39');

-- --------------------------------------------------------

--
-- Table structure for table `org_members`
--

CREATE TABLE `org_members` (
  `org_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('owner','member','Project Manager','QA Lead','Senior Developer','Senior QA','Junior Developer','QA Tester') NOT NULL DEFAULT 'member',
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `org_members`
--

INSERT INTO `org_members` (`org_id`, `user_id`, `role`, `joined_at`) VALUES
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
(6, 5, 'member', '2026-02-24 08:46:58');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_active_org_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `created_at`, `last_active_org_id`) VALUES
(1, 'admin', 'admin@bugcatcher.com', '$2y$10$TqOyauUOHxacr2BU7LkMf.FFXuw9..mURcVDG16UEJ/a3AwIP/8.6', 'admin', '2026-02-24 06:48:27', NULL),
(2, 'user', 'user@bugcatcher.com', '$2y$10$OJ5fkoKfjOt72KQFHAb6xOlu17fOoAohbtynEfl3iI.RFa0AX.Bq2', 'user', '2026-02-24 06:48:27', NULL),
(3, 'Zen', 'zen@gmail.com', '$2y$10$fZEUrsGS9bOxxHJx2uXH.Or5r9iZOBenrnEg86aHeLuekabgXK5wu', 'user', '2026-02-24 06:58:13', 4),
(4, 'Pol', 'pol@gmail.com', '$2y$10$mrbNTwyw9wQtNkkoNTWhi.jFKrsV2h.xQNqzaE4gTGals5tMfeSYK', 'user', '2026-02-24 06:58:43', 4),
(5, 'Endy', 'endy@gmail.com', '$2y$10$lt.owHlTl0CkmO1XIOTtNOxh4hs5qk.a3pGU0kgfR36igGifxddeG', 'user', '2026-02-24 08:46:10', 4),
(6, 'Marsh', 'marsh@gmail.com', '$2y$10$oJwnRfuJzQ0CAs1Ficgu9uppmNoWC4JW8NidzI.eqbS9yGSxWMZBa', 'user', '2026-02-26 12:27:28', 4),
(7, 'Null', 'null@gmail.com', '$2y$10$0ktfEQpHAbtR9ClqKlpLAumZPEERstsOdaDHgWVvVOllo6sD0hF6.', 'user', '2026-02-26 12:29:23', 4),
(8, 'N', 'n@gmail.com', '$2y$10$yj29R1TEotpy9Vv4g8aT7O5kjcwVgi4ArlVFpzNzMlifXTzsu7Lc6', 'user', '2026-02-26 12:31:12', 4),
(9, 'Dragon', 'dragon@gmail.com', '$2y$10$CFkKQj8j65yQ.8yylX.fjuyf8RxPWemJa8s9K0iS8TExN/HS2rlpO', 'user', '2026-02-26 12:32:30', 4);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `contact`
--
ALTER TABLE `contact`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `issues`
--
ALTER TABLE `issues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `author_id` (`author_id`),
  ADD KEY `idx_issues_org` (`org_id`),
  ADD KEY `idx_issues_assigned_dev` (`assigned_dev_id`),
  ADD KEY `idx_issues_assigned_qa_id` (`assigned_qa_id`),
  ADD KEY `idx_issues_assigned_junior` (`assigned_junior_id`),
  ADD KEY `idx_issues_assigned_senior_qa` (`assigned_senior_qa_id`),
  ADD KEY `idx_issues_assigned_qa_lead` (`assigned_qa_lead_id`),
  ADD KEY `idx_issues_pm_id` (`pm_id`),
  ADD KEY `idx_issues_workflow_status` (`workflow_status`);

--
-- Indexes for table `issue_attachments`
--
ALTER TABLE `issue_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `issue_id` (`issue_id`);

--
-- Indexes for table `issue_labels`
--
ALTER TABLE `issue_labels`
  ADD PRIMARY KEY (`issue_id`,`label_id`),
  ADD KEY `label_id` (`label_id`);

--
-- Indexes for table `labels`
--
ALTER TABLE `labels`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `organizations`
--
ALTER TABLE `organizations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_org_owner` (`owner_id`);

--
-- Indexes for table `org_members`
--
ALTER TABLE `org_members`
  ADD PRIMARY KEY (`org_id`,`user_id`),
  ADD UNIQUE KEY `uniq_org_user` (`org_id`,`user_id`),
  ADD KEY `fk_org_members_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `contact`
--
ALTER TABLE `contact`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issues`
--
ALTER TABLE `issues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `issue_attachments`
--
ALTER TABLE `issue_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `labels`
--
ALTER TABLE `labels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `issues`
--
ALTER TABLE `issues`
  ADD CONSTRAINT `fk_issues_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `issues_ibfk_1` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `issue_attachments`
--
ALTER TABLE `issue_attachments`
  ADD CONSTRAINT `fk_issue_attachments_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `issue_labels`
--
ALTER TABLE `issue_labels`
  ADD CONSTRAINT `issue_labels_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `issue_labels_ibfk_2` FOREIGN KEY (`label_id`) REFERENCES `labels` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `organizations`
--
ALTER TABLE `organizations`
  ADD CONSTRAINT `fk_org_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `org_members`
--
ALTER TABLE `org_members`
  ADD CONSTRAINT `fk_org_members_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
