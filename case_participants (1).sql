-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 19, 2025 at 06:34 AM
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
-- Database: `brgy_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `case_participants`
--

CREATE TABLE `case_participants` (
  `participant_id` int(11) NOT NULL,
  `case_number` varchar(50) NOT NULL,
  `role` enum('Complainant','Respondent') NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix_name` varchar(10) DEFAULT NULL,
  `action_taken` varchar(50) NOT NULL,
  `remarks` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `case_participants`
--

INSERT INTO `case_participants` (`participant_id`, `case_number`, `role`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `action_taken`, `remarks`) VALUES
(7, '2025-01', 'Complainant', 'Jay', 'Acop', 'Cabulay', '', 'Appearance', 'appeard'),
(8, '2025-01', 'Respondent', 'VERGIE', 'DADULLA', 'ACEDILLO', '', 'Appearance', 'appeard'),
(9, '2025-01', 'Respondent', 'NETCHE', 'PERIFERIO', 'ABALES', '', 'Appearance', 'appeard');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `case_participants`
--
ALTER TABLE `case_participants`
  ADD PRIMARY KEY (`participant_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `case_participants`
--
ALTER TABLE `case_participants`
  MODIFY `participant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
