-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 08, 2025 at 05:49 PM
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
-- Database: `gymnastics_scoring`
--

-- --------------------------------------------------------

--
-- Table structure for table `apparatus`
--

CREATE TABLE `apparatus` (
  `apparatus_id` int(11) NOT NULL,
  `apparatus_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `apparatus`
--

INSERT INTO `apparatus` (`apparatus_id`, `apparatus_name`) VALUES
(1, 'Floor Exercise'),
(2, 'Pommel Horse'),
(3, 'Still Rings'),
(4, 'Vault'),
(5, 'Parallel Bars'),
(6, 'Horizontal Bar');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `event_id` int(11) NOT NULL,
  `event_name` varchar(100) NOT NULL,
  `event_date` date NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `status` enum('upcoming','active','completed') DEFAULT 'upcoming',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gymnasts`
--

CREATE TABLE `gymnasts` (
  `gymnast_id` int(11) NOT NULL,
  `gymnast_name` varchar(100) NOT NULL,
  `gymnast_category` varchar(50) DEFAULT NULL,
  `team_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `judge_assignments`
--

CREATE TABLE `judge_assignments` (
  `assignment_id` int(11) NOT NULL,
  `judge_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `apparatus_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `organizations`
--

CREATE TABLE `organizations` (
  `org_id` int(11) NOT NULL,
  `org_name` varchar(100) NOT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scores`
--

CREATE TABLE `scores` (
  `score_id` int(11) NOT NULL,
  `gymnast_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `apparatus_id` int(11) NOT NULL,
  `judge_id` int(11) NOT NULL,
  `score_d1` decimal(4,2) DEFAULT 0.00,
  `score_d2` decimal(4,2) DEFAULT 0.00,
  `score_d3` decimal(4,2) DEFAULT 0.00,
  `score_d4` decimal(4,2) DEFAULT 0.00,
  `score_a1` decimal(4,2) DEFAULT 0.00,
  `score_a2` decimal(4,2) DEFAULT 0.00,
  `score_a3` decimal(4,2) DEFAULT 0.00,
  `score_e1` decimal(4,2) DEFAULT 0.00,
  `score_e2` decimal(4,2) DEFAULT 0.00,
  `score_e3` decimal(4,2) DEFAULT 0.00,
  `technical_deduction` decimal(4,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teams`
--

CREATE TABLE `teams` (
  `team_id` int(11) NOT NULL,
  `team_name` varchar(100) NOT NULL,
  `organization_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('super_admin','admin','judge','user') DEFAULT 'user',
  `organization_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `full_name`, `role`, `organization_id`, `created_at`, `is_active`) VALUES
(1, 'missaff', 'missaff@gymnastics.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'MISS AFF', 'super_admin', NULL, '2025-09-06 08:26:17', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `apparatus`
--
ALTER TABLE `apparatus`
  ADD PRIMARY KEY (`apparatus_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `gymnasts`
--
ALTER TABLE `gymnasts`
  ADD PRIMARY KEY (`gymnast_id`),
  ADD KEY `team_id` (`team_id`);

--
-- Indexes for table `judge_assignments`
--
ALTER TABLE `judge_assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `apparatus_id` (`apparatus_id`),
  ADD KEY `assigned_by` (`assigned_by`),
  ADD KEY `idx_judge_assignments_judge_event` (`judge_id`,`event_id`);

--
-- Indexes for table `organizations`
--
ALTER TABLE `organizations`
  ADD PRIMARY KEY (`org_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `scores`
--
ALTER TABLE `scores`
  ADD PRIMARY KEY (`score_id`),
  ADD UNIQUE KEY `unique_score` (`gymnast_id`,`event_id`,`apparatus_id`),
  ADD KEY `apparatus_id` (`apparatus_id`),
  ADD KEY `judge_id` (`judge_id`),
  ADD KEY `idx_scores_event_gymnast` (`event_id`,`gymnast_id`);

--
-- Indexes for table `teams`
--
ALTER TABLE `teams`
  ADD PRIMARY KEY (`team_id`),
  ADD KEY `organization_id` (`organization_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `apparatus`
--
ALTER TABLE `apparatus`
  MODIFY `apparatus_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gymnasts`
--
ALTER TABLE `gymnasts`
  MODIFY `gymnast_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `judge_assignments`
--
ALTER TABLE `judge_assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `org_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scores`
--
ALTER TABLE `scores`
  MODIFY `score_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teams`
--
ALTER TABLE `teams`
  MODIFY `team_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `gymnasts`
--
ALTER TABLE `gymnasts`
  ADD CONSTRAINT `gymnasts_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`);

--
-- Constraints for table `judge_assignments`
--
ALTER TABLE `judge_assignments`
  ADD CONSTRAINT `judge_assignments_ibfk_1` FOREIGN KEY (`judge_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `judge_assignments_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`),
  ADD CONSTRAINT `judge_assignments_ibfk_3` FOREIGN KEY (`apparatus_id`) REFERENCES `apparatus` (`apparatus_id`),
  ADD CONSTRAINT `judge_assignments_ibfk_4` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `organizations`
--
ALTER TABLE `organizations`
  ADD CONSTRAINT `organizations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `scores`
--
ALTER TABLE `scores`
  ADD CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`gymnast_id`) REFERENCES `gymnasts` (`gymnast_id`),
  ADD CONSTRAINT `scores_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`),
  ADD CONSTRAINT `scores_ibfk_3` FOREIGN KEY (`apparatus_id`) REFERENCES `apparatus` (`apparatus_id`),
  ADD CONSTRAINT `scores_ibfk_4` FOREIGN KEY (`judge_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `teams`
--
ALTER TABLE `teams`
  ADD CONSTRAINT `teams_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`org_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
