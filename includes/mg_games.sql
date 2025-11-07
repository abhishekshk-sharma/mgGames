-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 09, 2025 at 07:58 AM
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
-- Database: `mg games`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `email`, `password_hash`, `created_at`) VALUES
(3, 'Don', 'manavgurnani66@gmail.com', '$2y$10$RNz/yLSiiTMoa4.QW4mzVO.KxWNKGoMx.HCphvqcFuq4FaCb1K.HC', '2025-09-22 09:08:02');

-- --------------------------------------------------------

--
-- Table structure for table `bets`
--

CREATE TABLE `bets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `game_session_id` int(11) NOT NULL,
  `game_type_id` int(11) NOT NULL,
  `bet_mode` enum('open','close') DEFAULT 'open',
  `numbers_played` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `potential_win` decimal(12,2) NOT NULL,
  `status` enum('pending','won','lost','cancelled') DEFAULT 'pending',
  `placed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `result_declared_at` timestamp NULL DEFAULT NULL,
  `game_name` varchar(255) NOT NULL DEFAULT 'Kalyan Matka',
  `open_time` time NOT NULL DEFAULT '15:30:00',
  `close_time` time NOT NULL DEFAULT '17:30:00',
  `game_type` enum('single_ank','jodi','single_patti','double_patti','triple_patti','half_sangam','full_sangam','sp_motor','dp_motor','red_bracket','digital_jodi','choice_pana','group_jodi','abr_100','abr_cut') DEFAULT 'single_ank'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bets`
--

INSERT INTO `bets` (`id`, `user_id`, `game_session_id`, `game_type_id`, `bet_mode`, `numbers_played`, `amount`, `potential_win`, `status`, `placed_at`, `result_declared_at`, `game_name`, `open_time`, `close_time`, `game_type`) VALUES
(217, 3, 26, 1, 'close', '{\"788\":100}', 100.00, 900.00, 'pending', '2025-09-24 06:23:06', NULL, 'Milano Day', '15:00:00', '17:00:00', 'double_patti'),
(218, 3, 26, 1, 'open', '{\"selected_digits\":\"1234\",\"pana_combinations\":[\"123\",\"124\",\"134\",\"234\"],\"amount_per_pana\":10,\"total_amount\":40,\"removed_panas\":[]}', 40.00, 360.00, 'pending', '2025-09-24 08:52:35', NULL, 'Milano Day', '15:00:00', '17:00:00', 'sp_motor'),
(219, 3, 27, 4, 'open', '{\"4\":10}', 10.00, 3000.00, 'pending', '2025-09-26 06:04:03', NULL, 'Mumbai Main', '12:00:00', '14:00:00', 'double_patti'),
(220, 3, 27, 5, 'open', '{\"4\":10}', 10.00, 9000.00, 'pending', '2025-09-26 12:47:47', NULL, 'Mumbai Main', '12:00:00', '14:00:00', 'triple_patti'),
(221, 3, 27, 5, 'open', '{\"4\":50}', 50.00, 45000.00, 'pending', '2025-09-26 12:48:47', NULL, 'Kalyan Matka', '15:30:00', '17:30:00', 'triple_patti'),
(222, 3, 27, 5, 'open', '{\"4\":10}', 10.00, 9000.00, 'pending', '2025-09-26 12:50:15', NULL, 'Kalyan Matka', '15:30:00', '17:30:00', 'triple_patti'),
(223, 3, 27, 1, 'open', '{\"4\":10}', 10.00, 90.00, 'pending', '2025-09-26 13:49:29', NULL, 'Mumbai Main', '12:00:00', '14:00:00', 'single_ank'),
(224, 3, 27, 1, 'open', '{\"4\":5}', 5.00, 45.00, 'pending', '2025-09-26 13:50:18', NULL, 'Kalyan Matka', '15:30:00', '17:30:00', 'single_ank'),
(225, 3, 28, 1, 'open', '{\"selected_digits\":\"2598\",\"pana_combinations\":[\"258\",\"259\",\"289\",\"589\"],\"amount_per_pana\":10,\"total_amount\":40,\"removed_panas\":[]}', 40.00, 360.00, 'pending', '2025-09-29 10:31:24', NULL, 'Mumbai Main', '12:00:00', '14:00:00', 'sp_motor'),
(226, 3, 29, 1, 'open', '{\"selected_digits\":\"1234\",\"pana_combinations\":[\"123\",\"124\",\"134\",\"234\"],\"amount_per_pana\":10,\"total_amount\":40,\"removed_panas\":[]}', 40.00, 360.00, 'pending', '2025-09-30 06:49:31', NULL, 'Rajdhani Night', '21:30:00', '23:30:00', 'sp_motor'),
(227, 3, 29, 1, 'open', '{\"selected_digits\":\"89453\",\"pana_combinations\":[\"345\",\"348\",\"349\",\"358\",\"359\",\"389\",\"458\",\"459\",\"489\",\"589\"],\"amount_per_pana\":1,\"total_amount\":10,\"removed_panas\":[]}', 10.00, 90.00, 'pending', '2025-09-30 06:50:33', NULL, 'Kalyan Matka', '15:30:00', '17:30:00', 'sp_motor'),
(228, 3, 29, 1, 'open', '{\"selected_digits\":\"42987\",\"pana_combinations\":[\"247\",\"248\",\"249\",\"278\",\"279\",\"289\",\"478\",\"479\",\"489\",\"789\"],\"amount_per_pana\":1,\"total_amount\":10,\"removed_panas\":[]}', 10.00, 90.00, 'pending', '2025-09-30 06:52:43', NULL, 'Kalyan Matka', '15:30:00', '17:30:00', 'sp_motor'),
(230, 4, 30, 1, 'open', '{\"5\":1000}', 1000.00, 9000.00, 'pending', '2025-10-06 13:00:19', NULL, 'Kalyan Matka', '09:30:00', '11:30:00', 'single_ank'),
(231, 4, 30, 1, 'close', '{\"555\":50}', 50.00, 450.00, 'pending', '2025-10-06 13:00:44', NULL, 'Time Bazar', '14:00:00', '16:00:00', 'triple_patti'),
(232, 4, 30, 1, 'open', '{\"114\":10,\"115\":10,\"118\":10,\"188\":10,\"199\":10}', 50.00, 450.00, 'pending', '2025-10-06 13:29:50', NULL, 'Time Bazar', '14:00:00', '16:00:00', 'double_patti'),
(233, 4, 30, 1, 'open', '{\"006\":100,\"005\":100,\"004\":100}', 300.00, 2700.00, 'pending', '2025-10-06 13:31:53', NULL, 'Mumbai Main', '12:00:00', '14:00:00', 'single_patti'),
(234, 4, 31, 1, 'open', '{\"986\":50,\"987\":50,\"996\":50,\"997\":50}', 200.00, 1800.00, 'pending', '2025-10-07 05:35:49', NULL, 'Mumbai Main', '12:00:00', '14:00:00', 'single_patti'),
(235, 4, 31, 1, 'open', '{\"16\":100,\"05\":100,\"06\":100}', 300.00, 2700.00, 'pending', '2025-10-07 05:36:08', NULL, 'Madhur Day', '13:45:00', '15:45:00', 'jodi'),
(236, 4, 31, 1, 'open', '{\"selected_digits\":\"1234\",\"pana_combinations\":[\"123\",\"124\",\"134\",\"234\"],\"amount_per_pana\":10,\"total_amount\":40,\"removed_panas\":[]}', 40.00, 360.00, 'pending', '2025-10-07 06:35:33', NULL, 'Mumbai Main', '12:00:00', '14:00:00', 'sp_motor'),
(237, 4, 30, 1, 'open', '{\"selected_digits\":\"1253\",\"pana_combinations\":[\"123\",\"125\",\"135\",\"235\"],\"amount_per_pana\":10,\"total_amount\":40,\"removed_panas\":[]}', 40.00, 360.00, 'pending', '2025-10-07 08:18:26', NULL, 'Milano Day', '15:00:00', '17:00:00', 'sp_motor');

-- --------------------------------------------------------

--
-- Table structure for table `deposits`
--

CREATE TABLE `deposits` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('phonepay','gpay') NOT NULL,
  `utr_number` varchar(50) NOT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deposits`
--

INSERT INTO `deposits` (`id`, `user_id`, `amount`, `payment_method`, `utr_number`, `payment_proof`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES
(1, 3, 200.00, 'gpay', '123654789', 'deposit_proof_3_1758883424.png', 'pending', NULL, '2025-09-26 10:43:44', '2025-09-26 10:43:44'),
(2, 3, 200.00, 'gpay', '123654789', 'deposit_proof_3_1758883466.png', 'pending', NULL, '2025-09-26 10:44:26', '2025-09-26 10:44:26'),
(3, 3, 200.00, 'phonepay', '6948419651954', 'deposit_proof_3_1758884950.png', 'pending', NULL, '2025-09-26 11:09:10', '2025-09-26 11:09:10'),
(4, 3, 200.00, 'phonepay', '6948419651954', 'deposit_proof_3_1758884967.png', 'pending', NULL, '2025-09-26 11:09:27', '2025-09-26 11:09:27'),
(5, 3, 200.00, 'phonepay', '6948419651954', 'deposit_proof_3_1758884980.png', 'pending', NULL, '2025-09-26 11:09:40', '2025-09-26 11:09:40'),
(6, 3, 200.00, 'phonepay', '6948419651954', 'deposit_proof_3_1758885015.png', 'pending', NULL, '2025-09-26 11:10:15', '2025-09-26 11:10:15'),
(7, 3, 100.00, 'phonepay', '1225552212222', 'deposit_proof_3_1758886169.png', 'pending', NULL, '2025-09-26 11:29:29', '2025-09-26 11:29:29'),
(8, 3, 200.00, 'phonepay', '1225552212222', 'deposit_proof_3_1758886198.png', 'pending', NULL, '2025-09-26 11:29:58', '2025-09-26 11:29:58'),
(9, 3, 500.00, 'phonepay', '20122', 'deposit_proof_3_1758886223.png', 'pending', NULL, '2025-09-26 11:30:23', '2025-09-26 11:30:23'),
(10, 3, 200.00, 'gpay', '11111', 'deposit_proof_3_1758887631.png', 'pending', NULL, '2025-09-26 11:53:51', '2025-09-26 11:53:51'),
(11, 3, 200.00, 'phonepay', '789654123654', 'deposit_proof_3_1758889082.png', 'pending', NULL, '2025-09-26 12:18:02', '2025-09-26 12:18:02'),
(12, 3, 200.00, 'phonepay', '694841965195', 'deposit_proof_3_1758894601.png', 'pending', NULL, '2025-09-26 13:50:01', '2025-09-26 13:50:01'),
(13, 4, 213455.00, 'phonepay', '223323234212', 'deposit_proof_4_1759564063.png', 'approved', NULL, '2025-10-04 07:47:43', '2025-10-04 13:43:29'),
(14, 4, 100.00, 'phonepay', '123456654321', 'deposit_proof_4_1759564127.png', 'approved', NULL, '2025-10-04 07:48:47', '2025-10-04 13:44:10');

-- --------------------------------------------------------

--
-- Table structure for table `games`
--

CREATE TABLE `games` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `open_time` time NOT NULL,
  `close_time` time NOT NULL,
  `game_mode` enum('open','close') DEFAULT 'open',
  `game_type` enum('single_ank','jodi','single_patti','double_patti','triple_patti','half_sangam','full_sangam','sp_motor','dp_motor','red_bracket','digital_jodi','choice_pana','group_jodi','abr_100','abr_cut') DEFAULT 'single_ank',
  `game_type_id` int(11) DEFAULT 1,
  `result_time` time NOT NULL,
  `status` enum('active','inactive','maintenance') DEFAULT 'active',
  `min_bet` decimal(8,2) DEFAULT 5.00,
  `max_bet` decimal(10,2) DEFAULT 10000.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `games`
--

INSERT INTO `games` (`id`, `name`, `code`, `description`, `open_time`, `close_time`, `game_mode`, `game_type`, `game_type_id`, `result_time`, `status`, `min_bet`, `max_bet`, `created_at`) VALUES
(1, 'Kalyan Matka', 'KALYAN', 'Kalyan Morning Matka', '09:30:00', '11:30:00', 'open', 'single_ank', 1, '12:00:00', 'active', 5.00, 10000.00, '2025-09-12 07:39:31'),
(2, 'Mumbai Main', 'MUMBAI', 'Mumbai Main Matka', '12:00:00', '14:00:00', 'open', 'single_ank', 1, '14:30:00', 'active', 5.00, 10000.00, '2025-09-12 07:39:31'),
(3, 'Rajdhani Night', 'RAJDHANI', 'Rajdhani Night Matka', '21:30:00', '23:30:00', 'open', 'single_ank', 1, '00:00:00', 'active', 5.00, 10000.00, '2025-09-12 07:39:31'),
(4, 'Madhur Day', 'MADHUR', 'Madhur Day Matka', '13:45:00', '15:45:00', 'open', 'single_ank', 1, '16:15:00', 'active', 5.00, 10000.00, '2025-09-12 07:39:31'),
(5, 'Time Bazar', 'TIMEBAZAR', 'Time Bazar Matka', '14:00:00', '16:00:00', 'open', 'single_ank', 1, '16:30:00', 'active', 5.00, 10000.00, '2025-09-12 07:39:31'),
(6, 'Milano Day', 'MILANO', 'Milano Day Matka', '15:00:00', '17:00:00', 'open', 'single_ank', 1, '17:30:00', 'active', 5.00, 10000.00, '2025-09-12 07:39:31');

-- --------------------------------------------------------

--
-- Table structure for table `game_sessions`
--

CREATE TABLE `game_sessions` (
  `id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `open_result` varchar(10) DEFAULT NULL,
  `close_result` varchar(10) DEFAULT NULL,
  `jodi_result` varchar(10) DEFAULT NULL,
  `status` enum('open','closed','completed','cancelled') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `open_time` time DEFAULT NULL,
  `close_time` time DEFAULT NULL,
  `result_declared` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `game_sessions`
--

INSERT INTO `game_sessions` (`id`, `game_id`, `session_date`, `open_result`, `close_result`, `jodi_result`, `status`, `created_at`, `updated_at`, `open_time`, `close_time`, `result_declared`) VALUES
(15, 1, '2025-09-12', NULL, NULL, NULL, 'open', '2025-09-12 13:36:29', '2025-09-25 11:36:33', NULL, NULL, 1),
(16, 1, '2025-09-13', NULL, NULL, NULL, 'open', '2025-09-13 06:12:32', '2025-09-13 06:12:32', NULL, NULL, 0),
(17, 1, '2025-09-15', NULL, NULL, NULL, 'open', '2025-09-15 05:35:35', '2025-09-15 05:35:35', NULL, NULL, 0),
(18, 2, '2025-09-15', NULL, NULL, NULL, 'open', '2025-09-15 09:57:15', '2025-09-15 09:57:15', NULL, NULL, 0),
(19, 1, '2025-09-16', NULL, NULL, NULL, 'open', '2025-09-16 05:38:10', '2025-09-16 05:38:10', NULL, NULL, 0),
(20, 1, '2025-09-17', NULL, NULL, NULL, 'open', '2025-09-17 06:16:26', '2025-09-17 06:16:26', NULL, NULL, 0),
(21, 1, '2025-09-18', NULL, NULL, NULL, 'open', '2025-09-18 06:07:49', '2025-09-18 06:07:49', NULL, NULL, 0),
(22, 1, '2025-09-19', NULL, NULL, NULL, 'open', '2025-09-19 06:11:07', '2025-09-19 06:11:07', NULL, NULL, 0),
(23, 1, '2025-09-20', NULL, NULL, NULL, 'open', '2025-09-20 07:03:44', '2025-09-20 07:03:44', NULL, NULL, 0),
(24, 1, '2025-09-22', NULL, NULL, NULL, 'open', '2025-09-22 11:37:27', '2025-09-22 11:37:27', NULL, NULL, 0),
(25, 1, '2025-09-23', NULL, NULL, NULL, 'open', '2025-09-23 06:37:47', '2025-09-23 06:37:47', NULL, NULL, 0),
(26, 1, '2025-09-24', NULL, NULL, NULL, 'open', '2025-09-24 06:23:06', '2025-09-25 11:45:52', NULL, NULL, 0),
(27, 1, '2025-09-26', NULL, NULL, NULL, 'open', '2025-09-26 06:04:03', '2025-09-26 06:04:03', NULL, NULL, 0),
(28, 1, '2025-09-29', NULL, NULL, NULL, 'open', '2025-09-29 10:31:24', '2025-09-29 10:31:24', NULL, NULL, 0),
(29, 1, '2025-09-30', NULL, NULL, NULL, 'open', '2025-09-30 06:49:31', '2025-09-30 06:49:31', NULL, NULL, 0),
(30, 1, '2025-10-07', NULL, NULL, NULL, 'open', '2025-10-06 13:00:19', '2025-10-07 07:05:28', NULL, NULL, 0),
(31, 2, '2025-10-07', NULL, NULL, NULL, 'open', '2025-10-07 05:35:49', '2025-10-07 07:04:18', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `game_types`
--

CREATE TABLE `game_types` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `rules` text DEFAULT NULL,
  `payout_ratio` decimal(5,2) NOT NULL,
  `min_selection` int(11) DEFAULT 1,
  `max_selection` int(11) DEFAULT 1,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `game_types`
--

INSERT INTO `game_types` (`id`, `name`, `code`, `description`, `rules`, `payout_ratio`, `min_selection`, `max_selection`, `status`) VALUES
(1, 'Single Ank', 'SINGLE_ANK', 'Bet on single digit 0-9', NULL, 9.00, 1, 1, 'active'),
(2, 'Jodi', 'JODI', 'Bet on pair of digits 00-99', NULL, 90.00, 1, 1, 'active'),
(3, 'Single Patti', 'SINGLE_PATTI', 'Bet on three-digit number', NULL, 150.00, 1, 1, 'active'),
(4, 'Double Patti', 'DOUBLE_PATTI', 'Bet on two three-digit numbers', NULL, 300.00, 1, 1, 'active'),
(5, 'Triple Patti', 'TRIPLE_PATTI', 'Bet on three three-digit numbers', NULL, 900.00, 1, 1, 'active'),
(8, 'SP Motor', 'SP_MOTOR', 'Special Motor betting', NULL, 80.00, 1, 1, 'active'),
(9, 'DP Motor', 'DP_MOTOR', 'Double Panel Motor betting', NULL, 160.00, 1, 1, 'active'),
(17, 'SP Set', 'SP_SET', 'Single Patti Set betting', 'Bet on set of single patti numbers', 180.00, 1, 3, 'active'),
(18, 'DP Set', 'DP_SET', 'Double Patti Set betting', 'Bet on set of double patti numbers', 350.00, 1, 2, 'active'),
(19, 'TP Set', 'TP_SET', 'Triple Patti Set betting', 'Bet on set of triple patti numbers', 950.00, 1, 1, 'active'),
(21, 'SP', 'SP', 'Single Patti betting', 'Bet on single patti numbers', 150.00, 1, 1, 'active'),
(22, 'DP', 'DP', 'Double Patti betting', 'Bet on double patti numbers', 300.00, 1, 1, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `payouts`
--

CREATE TABLE `payouts` (
  `id` int(11) NOT NULL,
  `bet_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','processed','failed') DEFAULT 'pending',
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `id` int(11) NOT NULL,
  `game_session_id` int(11) NOT NULL,
  `result_type` enum('open','close','jodi') NOT NULL,
  `result_value` varchar(10) NOT NULL,
  `declared_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `super_admin`
--

CREATE TABLE `super_admin` (
  `id` int(11) NOT NULL,
  `sp_adm_id` varchar(12) NOT NULL,
  `username` varchar(30) NOT NULL,
  `email_id` varchar(100) NOT NULL,
  `phone_number` varchar(10) NOT NULL,
  `hash_password` varchar(150) NOT NULL,
  `referral_code` varchar(10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `super_admin`
--

INSERT INTO `super_admin` (`id`, `sp_adm_id`, `username`, `email_id`, `phone_number`, `hash_password`, `referral_code`, `created_at`) VALUES
(1, 'spadm_01', 'superadmin', 'spadmin@spadmin.com', '9846564856', '$2y$10$afPyqgkLelijtcvS75W28OD8K1y0lQHpf2B9qIkbYBGwXQ9n8./Cu', 'xcn3!fjuri', '2025-10-08 18:39:25');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('deposit','withdrawal','bet','winning','bonus','refund') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `balance_before` decimal(12,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `description` text DEFAULT NULL,
  `reference_id` varchar(50) DEFAULT NULL,
  `status` enum('pending','completed','failed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `user_id`, `type`, `amount`, `balance_before`, `balance_after`, `description`, `reference_id`, `status`, `created_at`) VALUES
(303, 3, 'bet', 100.00, 2000.00, 1900.00, 'Bet placed', NULL, 'completed', '2025-09-24 06:23:06'),
(304, 3, 'bet', 40.00, 1900.00, 1860.00, 'SP Motor Bet placed', NULL, 'completed', '2025-09-24 08:52:35'),
(305, 3, 'bet', 10.00, 1860.00, 1850.00, 'Bet placed', NULL, 'completed', '2025-09-26 06:04:03'),
(306, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 123654789', NULL, 'pending', '2025-09-26 10:43:44'),
(307, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 123654789', NULL, 'pending', '2025-09-26 10:44:26'),
(308, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 6948419651954', NULL, 'pending', '2025-09-26 11:09:10'),
(309, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 6948419651954', NULL, 'pending', '2025-09-26 11:09:27'),
(310, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 6948419651954', NULL, 'pending', '2025-09-26 11:09:40'),
(311, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 6948419651954', NULL, 'pending', '2025-09-26 11:10:15'),
(312, 3, 'deposit', 100.00, 1850.00, 1850.00, 'Deposit request - UTR: 1225552212222', NULL, 'pending', '2025-09-26 11:29:29'),
(313, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 1225552212222', NULL, 'pending', '2025-09-26 11:29:58'),
(314, 3, 'deposit', 500.00, 1850.00, 1850.00, 'Deposit request - UTR: 20122', NULL, 'pending', '2025-09-26 11:30:23'),
(315, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 11111', NULL, 'pending', '2025-09-26 11:53:51'),
(316, 3, 'deposit', 200.00, 1850.00, 1850.00, 'Deposit request - UTR: 789654123654', NULL, 'pending', '2025-09-26 12:18:02'),
(317, 3, 'bet', 10.00, 1850.00, 1840.00, 'Bet placed', NULL, 'completed', '2025-09-26 12:47:47'),
(318, 3, 'bet', 50.00, 1840.00, 1790.00, 'Bet placed', NULL, 'completed', '2025-09-26 12:48:47'),
(319, 3, 'bet', 10.00, 1790.00, 1780.00, 'Bet placed', NULL, 'completed', '2025-09-26 12:50:15'),
(320, 3, 'bet', 10.00, 1780.00, 1770.00, 'Bet placed', NULL, 'completed', '2025-09-26 13:49:29'),
(321, 3, 'deposit', 200.00, 1770.00, 1770.00, 'Deposit request - UTR: 694841965195', NULL, 'pending', '2025-09-26 13:50:01'),
(322, 3, 'bet', 5.00, 1770.00, 1765.00, 'Bet placed', NULL, 'completed', '2025-09-26 13:50:18'),
(323, 3, 'bet', 40.00, 1765.00, 1725.00, 'SP Motor Bet placed', NULL, 'completed', '2025-09-29 10:31:24'),
(324, 3, 'bet', 40.00, 1725.00, 1685.00, 'SP Motor Bet placed', NULL, 'completed', '2025-09-30 06:49:31'),
(325, 3, 'bet', 10.00, 1685.00, 1675.00, 'SP Motor Bet placed', NULL, 'completed', '2025-09-30 06:50:33'),
(326, 3, 'bet', 10.00, 1675.00, 1665.00, 'SP Motor Bet placed', NULL, 'completed', '2025-09-30 06:52:43'),
(327, 4, 'bet', 5545.00, 190000.00, 184455.00, 'Bet placed', NULL, 'completed', '2025-09-30 12:46:07'),
(328, 4, 'deposit', 213455.00, 184455.00, 184455.00, 'Deposit request - UTR: 223323234212', NULL, 'pending', '2025-10-04 07:47:43'),
(329, 4, 'deposit', 100.00, 184455.00, 184455.00, 'Deposit request - UTR: 123456654321', NULL, 'pending', '2025-10-04 07:48:47'),
(330, 4, 'deposit', 213455.00, 184455.00, 397910.00, 'Deposit approved', NULL, 'completed', '2025-10-04 13:43:29'),
(331, 4, 'deposit', 100.00, 397910.00, 398010.00, 'Deposit approved', NULL, 'completed', '2025-10-04 13:44:10'),
(332, 4, 'bet', 1000.00, 398010.00, 397010.00, 'Bet placed', NULL, 'completed', '2025-10-06 13:00:19'),
(333, 4, 'bet', 50.00, 397010.00, 396960.00, 'Bet placed', NULL, 'completed', '2025-10-06 13:00:44'),
(334, 4, 'bet', 50.00, 396960.00, 396910.00, 'Bet placed', NULL, 'completed', '2025-10-06 13:29:50'),
(335, 4, 'bet', 300.00, 396910.00, 396610.00, 'Bet placed', NULL, 'completed', '2025-10-06 13:31:53'),
(336, 4, 'bet', 200.00, 396610.00, 396410.00, 'Bet placed', NULL, 'completed', '2025-10-07 05:35:49'),
(337, 4, 'bet', 300.00, 396410.00, 396110.00, 'Bet placed', NULL, 'completed', '2025-10-07 05:36:08'),
(338, 4, 'bet', 40.00, 396110.00, 396070.00, 'SP Motor Bet placed', NULL, 'completed', '2025-10-07 06:35:33'),
(339, 4, 'bet', 40.00, 396070.00, 396030.00, 'SP Motor Bet placed', NULL, 'completed', '2025-10-07 08:18:26');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `balance` decimal(12,2) DEFAULT 0.00,
  `status` enum('active','suspended','banned') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `referral_code` varchar(20) DEFAULT NULL,
  `referred_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `phone`, `balance`, `status`, `created_at`, `updated_at`, `last_login`, `referral_code`, `referred_by`) VALUES
(3, 'manav1', 'manavgurnani66@gmail.com', '$2y$10$uuSrKZg16QqFtMK.0aBWd.BBfoOlNvdUB9FqoRRQe8cXsF5bGqPkm', 'q', 1665.00, 'active', '2025-09-23 10:42:41', '2025-10-04 10:04:41', NULL, 'AKPQA5GM', NULL),
(4, 'seema', 'manav@manav.com', '$2y$10$SI7rq2PcViBHtWvUvevoaO1.W745/ksiUJTbGmgtzKDOLey8qXSki', '7463748564', 396030.00, 'active', '2025-09-30 11:00:43', '2025-10-07 08:18:26', NULL, 'LII446RJ', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `logout_time` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `bets`
--
ALTER TABLE `bets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `game_session_id` (`game_session_id`),
  ADD KEY `game_type_id` (`game_type_id`);

--
-- Indexes for table `deposits`
--
ALTER TABLE `deposits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `games_ibfk_game_type` (`game_type_id`);

--
-- Indexes for table `game_sessions`
--
ALTER TABLE `game_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_game_session` (`game_id`,`session_date`);

--
-- Indexes for table `game_types`
--
ALTER TABLE `game_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `payouts`
--
ALTER TABLE `payouts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bet_id` (`bet_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_session_result` (`game_session_id`,`result_type`);

--
-- Indexes for table `super_admin`
--
ALTER TABLE `super_admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sp_adm_id` (`sp_adm_id`),
  ADD UNIQUE KEY `email_id` (`email_id`),
  ADD KEY `sp_adm_id_2` (`sp_adm_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `referral_code` (`referral_code`),
  ADD KEY `referred_by` (`referred_by`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `bets`
--
ALTER TABLE `bets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=238;

--
-- AUTO_INCREMENT for table `deposits`
--
ALTER TABLE `deposits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `games`
--
ALTER TABLE `games`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `game_sessions`
--
ALTER TABLE `game_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `game_types`
--
ALTER TABLE `game_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `payouts`
--
ALTER TABLE `payouts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `super_admin`
--
ALTER TABLE `super_admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=340;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bets`
--
ALTER TABLE `bets`
  ADD CONSTRAINT `bets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `bets_ibfk_2` FOREIGN KEY (`game_session_id`) REFERENCES `game_sessions` (`id`),
  ADD CONSTRAINT `bets_ibfk_3` FOREIGN KEY (`game_type_id`) REFERENCES `game_types` (`id`);

--
-- Constraints for table `deposits`
--
ALTER TABLE `deposits`
  ADD CONSTRAINT `deposits_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `games`
--
ALTER TABLE `games`
  ADD CONSTRAINT `games_ibfk_game_type` FOREIGN KEY (`game_type_id`) REFERENCES `game_types` (`id`);

--
-- Constraints for table `game_sessions`
--
ALTER TABLE `game_sessions`
  ADD CONSTRAINT `game_sessions_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`);

--
-- Constraints for table `payouts`
--
ALTER TABLE `payouts`
  ADD CONSTRAINT `payouts_ibfk_1` FOREIGN KEY (`bet_id`) REFERENCES `bets` (`id`),
  ADD CONSTRAINT `payouts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `results`
--
ALTER TABLE `results`
  ADD CONSTRAINT `results_ibfk_1` FOREIGN KEY (`game_session_id`) REFERENCES `game_sessions` (`id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
