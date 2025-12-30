-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 13, 2025 at 06:43 PM
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
-- Database: `stock_exchange_db`
--

DELIMITER $$
--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `LEVENSHTEIN` (`s1` VARCHAR(255), `s2` VARCHAR(255)) RETURNS INT(11) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE s1_len, s2_len, i, j, c, c_temp, cost INT;
    DECLARE s1_char CHAR;
    DECLARE cv0, cv1 VARBINARY(256);
    
    SET s1_len = CHAR_LENGTH(s1), s2_len = CHAR_LENGTH(s2), cv1 = 0x00, j = 1, i = 1, c = 0;
    
    IF s1 = s2 THEN
        RETURN 0;
    ELSEIF s1_len = 0 THEN
        RETURN s2_len;
    ELSEIF s2_len = 0 THEN
        RETURN s1_len;
    ELSE
        WHILE j <= s2_len DO
            SET cv1 = CONCAT(cv1, UNHEX(HEX(j))), j = j + 1;
        END WHILE;
        WHILE i <= s1_len DO
            SET s1_char = SUBSTRING(s1, i, 1), c = i, cv0 = UNHEX(HEX(i)), j = 1;
            WHILE j <= s2_len DO
                SET c = c + 1;
                IF s1_char = SUBSTRING(s2, j, 1) THEN 
                    SET cost = 0; ELSE SET cost = 1;
                END IF;
                SET c_temp = CONV(HEX(SUBSTRING(cv1, j, 1)), 16, 10) + cost;
                IF c > c_temp THEN SET c = c_temp; END IF;
                SET c_temp = CONV(HEX(SUBSTRING(cv1, j+1, 1)), 16, 10) + 1;
                IF c > c_temp THEN 
                    SET c = c_temp; 
                END IF;
                SET cv0 = CONCAT(cv0, UNHEX(HEX(c))), j = j + 1;
            END WHILE;
            SET cv1 = cv0, i = i + 1;
        END WHILE;
    END IF;
    RETURN c;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `balance_sheet_reporting_formats`
--

CREATE TABLE `balance_sheet_reporting_formats` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `balance_sheet_reporting_formats`
--

INSERT INTO `balance_sheet_reporting_formats` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'FA', 'FIXED ASSETS', 'P0001', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(2, 'CA', 'CURRENT ASSETS', 'P0002', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(3, 'CL', 'CURRENT LIABILITIES', 'P0003', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(4, 'SF', 'SHAREHOLDERS FUNDS', 'P0004', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58');

-- --------------------------------------------------------

--
-- Table structure for table `bonds`
--

CREATE TABLE `bonds` (
  `id` int(11) NOT NULL,
  `security_id` varchar(50) NOT NULL,
  `bond_name` varchar(200) NOT NULL,
  `issuer` varchar(200) NOT NULL,
  `coupon_rate` decimal(8,4) NOT NULL,
  `price` decimal(15,2) DEFAULT NULL,
  `ytm` decimal(8,4) DEFAULT NULL,
  `issue_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `term_years` int(11) NOT NULL,
  `security_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `isin` varchar(20) DEFAULT NULL,
  `economic_sector` varchar(50) NOT NULL,
  `coupon_determiner` varchar(50) DEFAULT NULL,
  `day_count_convention` varchar(20) DEFAULT NULL,
  `cash_flow_days` int(11) DEFAULT NULL,
  `issued_amount` decimal(20,2) DEFAULT NULL,
  `cds_security_code` varchar(50) DEFAULT NULL,
  `bond_no` varchar(50) DEFAULT NULL,
  `issue_no` varchar(50) DEFAULT NULL,
  `amortization_method` varchar(50) DEFAULT NULL,
  `payment_frequency` varchar(20) NOT NULL,
  `no_of_cash_flows` int(11) DEFAULT NULL,
  `withholding_tax` decimal(5,2) DEFAULT NULL,
  `statement_narrative` text DEFAULT NULL,
  `auction_no` varchar(50) DEFAULT NULL,
  `auction_date` date DEFAULT NULL,
  `auction_price` decimal(15,2) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'USD',
  `status` enum('active','matured','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `face_value` varchar(300) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bonds`
--

INSERT INTO `bonds` (`id`, `security_id`, `bond_name`, `issuer`, `coupon_rate`, `price`, `ytm`, `issue_date`, `maturity_date`, `term_years`, `security_type`, `description`, `isin`, `economic_sector`, `coupon_determiner`, `day_count_convention`, `cash_flow_days`, `issued_amount`, `cds_security_code`, `bond_no`, `issue_no`, `amortization_method`, `payment_frequency`, `no_of_cash_flows`, `withholding_tax`, `statement_narrative`, `auction_no`, `auction_date`, `auction_price`, `type`, `currency`, `status`, `created_at`, `face_value`) VALUES
(1, '669-15.25-T271-A', 'Government Bond 669-15.25-T271-A', 'BANK OF TANZANIA', 14.0000, 100.00, 14.2000, '2025-09-05', '2048-09-23', 25, 'FXD', '25 YEARS TREASURY BOND', 'TZ19961056677', 'GOVERMENTS & PARASTATALS', 'FIXED', '', 0, 123000000.00, 'B13', '669', '272', 'bullet', 'BIANNUALLY', 0, 0.00, '', '272', '2025-09-04', 103.00, 'FIXED RATE TREASURY BOND', 'USD', 'active', '2025-09-05 09:31:43', ''),
(2, '675-15-T16-A1', ' 675 - 15% Coupon', 'BOT', 15.0000, NULL, NULL, '0000-00-00', '0000-00-00', 16, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-05 10:05:49', '1000'),
(3, '639-12.56-T12-A1', 'FIXED RATE TREASURY BOND 639 - 12.56% Coupon', 'BOT', 12.5600, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-05 18:17:04', '1000'),
(4, '666-15.75-T15-A1', 'FIXED RATE TREASURY BOND 666 - 15.75% Coupon', 'BOT', 15.7500, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-05 18:17:04', '1000'),
(5, '566-15.49-T18-A1', 'FIXED RATE TREASURY BOND 566 - 15.49% Coupon', 'BOT', 15.4900, NULL, NULL, '0000-00-00', '0000-00-00', 0, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-05 18:17:04', '1000'),
(6, '568-15.95-T2-A1', 'FIXED RATE TREASURY BOND 568 - 15.95% Coupon', 'BOT', 15.9500, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-05 18:17:04', '1000'),
(7, '573-15.95-T3-A1', 'FIXED RATE TREASURY BOND 573 - 15.95% Coupon', 'BOT', 15.9500, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-05 18:17:04', '1000'),
(8, '500-13.50-T28-A1', 'FIXED RATE TREASURY BOND 500 - 13.50% Coupon', 'BOT', 13.5000, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-05 18:17:05', '1000'),
(9, '540-15.49-T13-A1', 'FIXED RATE TREASURY BOND 540 - 15.49% Coupon', 'BOT', 15.4900, NULL, NULL, '0000-00-00', '0000-00-00', 0, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-07 20:58:26', '1000'),
(10, '498-15.49-T4-A1', 'FIXED RATE TREASURY BOND 498 - 15.49% Coupon', 'BOT', 15.4900, NULL, NULL, '0000-00-00', '0000-00-00', 0, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-07 20:58:26', '1000'),
(11, '544-15.49-T14-A1', 'FIXED RATE TREASURY BOND 544 - 15.49% Coupon', 'BOT', 15.4900, NULL, NULL, '0000-00-00', '0000-00-00', 0, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-07 20:58:26', '1000'),
(12, '563-15.49-T17-A1', 'FIXED RATE TREASURY BOND 563 - 15.49% Coupon', 'BOT', 15.4900, NULL, NULL, '0000-00-00', '0000-00-00', 0, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-07 20:58:26', '1000'),
(13, '653-12.56-T14-A1', 'FIXED RATE TREASURY BOND 653 - 12.56% Coupon', 'BOT', 12.5600, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-09-20 15:12:18', '1000'),
(14, '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', 'BOT', 13.7500, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-10-12 15:19:08', '1000'),
(15, '643-12.56-T13-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', 'BOT', 12.5600, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-10-12 15:19:08', '1000'),
(16, '527-13.50-T33-A1', 'FIXED RATE TREASURY BOND 527 - 13.50% Coupon', 'BOT', 13.5000, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-10-12 15:21:44', '1000'),
(17, '536-13.50-T36-A1', 'FIXED RATE TREASURY BOND 536 - 13.50% Coupon', 'BOT', 13.5000, NULL, NULL, '0000-00-00', '0000-00-00', 1, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-10-12 15:21:44', '1000'),
(18, '533-15.49-T11-A1', 'FIXED RATE TREASURY BOND 533 - 15.49% Coupon', 'BOT', 15.4900, NULL, NULL, '0000-00-00', '0000-00-00', 0, 'FXD', NULL, NULL, 'G', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'H', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'USD', 'active', '2025-10-12 15:21:44', '1000');

-- --------------------------------------------------------

--
-- Table structure for table `bonds_economic_sectors`
--

CREATE TABLE `bonds_economic_sectors` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bonds_economic_sectors`
--

INSERT INTO `bonds_economic_sectors` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'C', 'CORPORATES & COMPANIES', 'P0001', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(2, 'F', 'INSTITUITIONS(FOREIGN)', 'P0002', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(3, 'G', 'GOVERMENTS & PARASTATALS', 'P0003', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(4, 'I', 'INSTITUITIONS(LOCAL)', 'P0004', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(5, 'L', 'LOCAL AUTHORITIES & MUNICIPALITIES', 'P0005', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `bond_auctions`
--

CREATE TABLE `bond_auctions` (
  `id` int(11) NOT NULL,
  `auction_number` varchar(10) NOT NULL,
  `auction_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `coupon_rate` decimal(5,2) NOT NULL,
  `face_value` decimal(15,2) NOT NULL,
  `total_amount` decimal(20,2) NOT NULL,
  `status` enum('planned','active','completed','cancelled') DEFAULT 'planned',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bond_auctions`
--

INSERT INTO `bond_auctions` (`id`, `auction_number`, `auction_date`, `maturity_date`, `coupon_rate`, `face_value`, `total_amount`, `status`, `created_by`, `created_at`) VALUES
(1, 'A1', '2025-08-23', '2025-09-03', 20.00, 560000.00, 6570000.00, 'completed', 3, '2025-08-23 21:34:57');

-- --------------------------------------------------------

--
-- Table structure for table `bond_issuers`
--

CREATE TABLE `bond_issuers` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive','pending') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bond_issuers`
--

INSERT INTO `bond_issuers` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(2, 'BOT', 'BANK OF TANZANIA', 'P0002', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(3, 'CBK', 'CENTRAL BANK OF KENYA', 'P0003', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(4, 'BOU', 'BANK OF UGANDA', 'P0004', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(5, 'NMB', 'NMB BANK PLC', 'P0005', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(6, 'NBC', 'NATIONALBANK OF COMMERCE', 'P0006', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(7, 'CRDB', 'CRDB BANK PLC', 'P0007', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(8, 'AZAN', 'AZANIA BANK PLC', 'P0008', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(9, 'TCB', 'TANZANIA COMMERCIAL BANK', 'P0001', 1, '2025-09-20 15:14:23', '2025-09-20 15:14:23', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `bond_types`
--

CREATE TABLE `bond_types` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `ytm_spread` decimal(8,4) DEFAULT 0.0000,
  `ex_coupon_days` int(11) DEFAULT 1,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive','pending') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bond_types`
--

INSERT INTO `bond_types` (`id`, `code`, `description`, `ytm_spread`, `ex_coupon_days`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'COB', 'CORPORATE BOND', 0.0100, 15, 'P0001', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(2, 'FXD', 'FIXED RATE TREASURY BOND', 0.0000, 1, 'P0002', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(3, 'MTN', 'MEDIUM TERM NOTE', 0.0100, 15, 'P0003', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(4, 'MUB', 'MUNICIPAL BOND', 0.0100, 15, 'P0004', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(5, 'ZCO', 'ZERO COUPON BOND', 0.0100, 15, 'P0005', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `brokers`
--

CREATE TABLE `brokers` (
  `id` int(11) NOT NULL,
  `broker_code` varchar(20) NOT NULL,
  `broker_name` varchar(200) NOT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `priority` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `brokers`
--

INSERT INTO `brokers` (`id`, `broker_code`, `broker_name`, `license_number`, `address`, `contact_person`, `phone`, `email`, `is_active`, `created_at`, `priority`, `status`) VALUES
(1, 'MAIN', 'Main Broker (System Default)', NULL, NULL, 'System Administrator', NULL, NULL, 1, '2025-08-21 15:09:38', NULL, 'active'),
(2, 'VICT', 'VICTORY FINANCIAL SERVICES LTD', '1123545', 'P.O Box 675, Dar es Salaam, Tanzania', 'joseph juma', '0768554325', 'ceo@neovam.com', 1, '2025-08-21 15:09:38', 'P0001', 'active'),
(3, 'ZANS', 'ZAN Securities Limited', NULL, NULL, 'Operations Manager', NULL, NULL, 1, '2025-08-21 15:09:38', NULL, 'active'),
(4, 'B01', 'ARCH FINANCIAL INVESTMENT ADVISORY', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(5, 'B02', 'COMMERCIAL BANK OF AFRICA', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(6, 'B03', 'CORE SECURITIES LIMITED', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(7, 'B04', 'CRDB BANK PLC', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(8, 'B05', 'ORBIT SECURITIES COMPANY LIMITED', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(9, 'B06', 'VERTEX INTERNATIONAL SECURITIES LIMITED', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(10, 'B07', 'CORE SECURITIES LIMITED', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(11, 'B13', 'VICTORY FINANCIAL SERVICES LTD', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 0, '2025-08-21 15:09:58', NULL, 'active'),
(12, 'B15', 'EXODUS ADVISORY SERVICES LIMITED', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(13, 'B16', 'GLOBAL ALPHA CAPITAL LTD', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active'),
(14, 'B18', 'ITRUST FINANCE LIMITED', 'TBA', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', NULL, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `cds_account` varchar(50) NOT NULL,
  `client_name` varchar(200) NOT NULL,
  `client_type` enum('individual','corporate','institutional') DEFAULT 'individual',
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `custodian_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `merged_into` int(11) DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `default_brokerage_fee` decimal(10,2) DEFAULT NULL,
  `fee_type` enum('normal','liberty','this_trade') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `cds_account`, `client_name`, `client_type`, `address`, `phone`, `email`, `custodian_id`, `is_active`, `created_at`, `updated_at`, `merged_into`, `created_by`, `default_brokerage_fee`, `fee_type`) VALUES
(1, '713875', 'SILAS BUGAMI KATEMI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(2, '640702', 'BARAKA RAYMOND KIMARO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(3, '547505', 'SOLOMON STOCKBROKERS LIMITED', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(4, '574738', 'VICTORY FINANCIAL SERVICES LTD', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(5, '627905', 'NANCY FELIX KISHA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(6, '608973', 'MAURICE SIEGFRIED KUWITE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(7, '730975', 'MAGDALENA EZEKIEL  NAKOMOLWA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(8, '717705', 'TINNER ALEXANDER MOSHA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(9, '718335', 'ROSE JOHN SHAWA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(10, '723500', 'SAID ABDU TEMBA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(11, '717024', 'RAHEL FESTUS ISOKOZA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(12, '718653', 'PHILIPO DAUDI JOHN', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(13, '722440', 'REMMY JOHN KAYUMBE ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(14, '720674', 'RAMADHANI RASHIDI HAULE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(15, '717963', 'OLIVA GURTU BURA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(16, '720090', 'SAFINA HAMIM NDEMBO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(17, '717026', 'SHABANI SAIDI MNAROMA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(18, '547808', 'ANNANDUMI PAUL MEENA', 'individual', NULL, '0767676767', 'wima@gmail.com', NULL, 1, '2025-08-23 19:15:00', '2025-09-20 08:54:10', NULL, '', NULL, 'normal'),
(19, '586789', 'MARY JOHN MPONDA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(20, '640000', 'HYPERCAPITAL LIMITED', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(21, '639020', 'HIRAM ALBERT NTABUDYO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(22, '680086', 'SOSTHENES FLORIAN NYENYEMBE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(23, '654554', 'JESTA JAMES MSWIMA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(24, '732297', 'NICHOLAUS ANDREA SUWEDI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(25, '656432', 'FININTELLI COMPANY LIMITED', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(26, '638414', 'FRANCISCA JOHN NGELESHI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(27, '650897', 'FRANCISCA ALPHONCE SAMALI', 'individual', NULL, '0767676767', 'admin1@gmail.com', NULL, 1, '2025-08-23 19:15:00', '2025-09-04 20:12:07', NULL, '', NULL, 'normal'),
(28, '649413', 'JACQUELINE NGELESHI & EMMANUEL MEDA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(29, '732300', 'TUMPALE WILSON MWANKEMWA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(30, '652241', 'JOEL PERFECT KISAWANO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(31, '585999', 'BALTAZARY FRIMIN TARIMO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 19:15:00', '2025-08-23 19:15:00', NULL, '', NULL, 'normal'),
(32, '719480', 'GIVEN DIAZ CHAO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(33, '595831', 'KELVIN SILYVESTRY KOKA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(34, '601864', 'WONFAIR HOLDINGS LIMITED', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(35, '579007', 'ANNA VINCENT MUNISI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(36, '591261', 'MOTTA PALIMAKA REUBEN KYANDO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(37, '695746', 'SIMON DAVID MALIGANYA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(38, '731721', 'YUNIA MANASE MUNUO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(39, '698842', 'JANE JUMA MADUHU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(40, '724325', 'ANGELISTA EDWARD KIHAGA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(41, '551369', ' ROBERT ADERITUS KATO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(42, '135528', 'JONATHAN JOHN MBAILUKA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(43, '732107', 'PARICIO CLEOPHAS TIBANYENDA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(44, '661589', 'EVELYNE AMON RUKANDA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(45, '653313', 'PROCHES GEORGE MASSAE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(46, '717831', 'VERONIKA NICHOLUS KAZINA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(47, '729435', '232352 ITF ADRIEL  RAPHAEL  MREMA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(48, '728918', 'MELANIA RODGERS KIVAGAYE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(49, '731193', 'ONESMO MFUJEGE MWANGOMO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(50, '727760', 'MKAMI LULU KIHWELO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(51, '724848', 'FALES JOHN MSUKA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(52, '653026', 'BILLYTONY FRENK KIMAMBO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(53, '725739', 'CHARLES EPHRAIM MAJINGE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(54, '639238', 'AIWINIA STANLEY TEMBA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(55, '700105', 'TRYPHONE KAZIMBAYA CHRISANTUS', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(56, '726160', 'MARTINE ANTHONY MARTINE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(57, '723393', 'KOKERA PHINIAS PETER', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(58, '692597', 'MSAFIRI SHAFII MSAFIRI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(59, '590899', 'JOSEPH MAGWEIGA MARWA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(60, '701320', 'GEORGE GOODLUCKY MLACKY', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(61, '644938', 'HAFIDHI ATHUMANI SHABANI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(62, '591460', 'AMIN AHMAD LEMBARITI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(63, '710332', 'MOURINE MICHAEL RUTTA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(64, '658222', 'ALMAS ADAM SIZYA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(65, '651257', 'BRYTON FOCUS SUNGUYA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(66, '691411', 'ROSE JOHN VUMU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(67, '647863', 'JOSHUA SAMWEL MONGI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(68, '728018', 'JAMSI JOHN MINAZI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(69, '719667', 'SARAH JOEL MWANTIMWA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(70, '725481', 'MICHAEL ADIMU MAKUKURA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(71, '665847', 'ISAYA SIMON BURUSH', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(72, '717445', 'PRISCA LAZARO NNKO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-08-23 21:21:26', '2025-08-23 21:21:26', NULL, '', NULL, 'normal'),
(73, '76556', 'DOMINA JOHN', 'individual', NULL, '098777666655', 'domina@gmail.com', NULL, 1, '2025-09-04 19:38:30', '2025-09-04 19:38:30', NULL, '2', NULL, 'normal'),
(74, '717022', 'SCHOLASTICA DOMINIC LYIMO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:15:33', '2025-09-04 20:15:33', NULL, '', NULL, 'normal'),
(75, '649498', 'NOELA JEROBOAM SWAI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:15:33', '2025-09-04 20:15:33', NULL, '', NULL, 'normal'),
(76, '658619', 'VICTORY HOMES SOLUTIONS LIMITED', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:15:33', '2025-09-04 20:15:33', NULL, '', NULL, 'normal'),
(77, '697336', 'BERLINSON ANDREW MOSHI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:16:24', '2025-09-04 20:16:24', NULL, '', NULL, 'normal'),
(78, '704577', 'FADHILI PETRO SANGA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:17:29', '2025-09-04 20:17:29', NULL, '', NULL, 'normal'),
(79, '732272', 'GEORGE SAMSON SIKIRA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:17:29', '2025-09-04 20:17:29', NULL, '', NULL, 'normal'),
(80, '730991', 'JOHN CLAVERY MTINDO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:17:29', '2025-09-04 20:17:29', NULL, '', NULL, 'normal'),
(81, '654292', 'BENO MORRIS MALISA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:17:29', '2025-09-04 20:17:29', NULL, '', NULL, 'normal'),
(82, '705282', 'JACQUELINE JACOB TEMU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:17:29', '2025-09-04 20:17:29', NULL, '', NULL, 'normal'),
(83, '732928', 'ANGELA ANGELO MUNENI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:17:29', '2025-09-04 20:17:29', NULL, '', NULL, 'normal'),
(84, '654581', 'ANGELA ANGELO MUNENI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:17:29', '2025-09-04 20:17:29', NULL, '', NULL, 'normal'),
(85, '724349', 'MARIA SEVERINE MREMA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-04 20:17:29', '2025-09-04 20:17:29', NULL, '', NULL, 'normal'),
(86, '711241', 'KELVIN DAVID MACHUMU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:42', '2025-09-05 10:11:42', NULL, '', NULL, 'normal'),
(87, '626539', 'BARAKA PHENIAS LWAKATARE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:42', '2025-09-05 10:11:42', NULL, '', NULL, 'normal'),
(88, '686503', 'JACKSON VENANCE IDDY', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:42', '2025-09-05 10:11:42', NULL, '', NULL, 'normal'),
(89, '690189', 'MARY HAMFRAY KALLENGE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:42', '2025-09-05 10:11:42', NULL, '', NULL, 'normal'),
(90, '703077', 'CHALES RABISON SWAI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:42', '2025-09-05 10:11:42', NULL, '', NULL, 'normal'),
(91, '716246', 'VICTOR ISACK BAHII', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:43', '2025-09-05 10:11:43', NULL, '', NULL, 'normal'),
(92, '660074', 'MARCEL  GWASMA WAYDA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:43', '2025-09-05 10:11:43', NULL, '', NULL, 'normal'),
(93, '688639', 'ASHEL PAUL BONIFACE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:43', '2025-09-05 10:11:43', NULL, '', NULL, 'normal'),
(94, '523210', 'DONALD NELSON MTOWE', 'individual', NULL, '0769398980', 'abel@gmail.com', NULL, 1, '2025-09-05 10:11:43', '2025-09-20 08:52:56', NULL, '', NULL, 'normal'),
(95, '721238', 'DANIEL DESTA NUNEMO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:53', '2025-09-05 10:11:53', NULL, '', NULL, 'normal'),
(96, '627990', 'YUSTINA HENRICK MASANYONI ITF NATHA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:53', '2025-09-05 10:11:53', NULL, '', NULL, 'normal'),
(97, '619571', 'YUSTINA HENRICK MASANYONI ITF ETHAN', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:53', '2025-09-05 10:11:53', NULL, '', NULL, 'normal'),
(98, '700242', 'BAHATI HAMISI MASAKA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:53', '2025-09-05 10:11:53', NULL, '', NULL, 'normal'),
(99, '669321', 'GEORGE MSAFIRI PETER', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:53', '2025-09-05 10:11:53', NULL, '', NULL, 'normal'),
(100, '716188', 'FREEMAN BEDA MACHA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(101, '733807', 'CALVIN JOSEPH TEMBA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(102, '699049', '671391 ITF BARAKA CHANDARUBA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(103, '255720', 'EDMUND FIDELIS RUTATINA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(104, '699050', '671391 ITF GLORIOUS CHANDARUBA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(105, '727553', 'CRIPSON KAZINJA CHRISTIAN', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(106, '699051', '671391 ITF BRAIGHTON CHANDARUBA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(107, '720462', 'SAMSON PHILIPO LUDOBO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(108, '717641', '671391 ITF GLORY  CHANDARUBA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(109, '730287', 'YOHANA DEUS SALIBOKO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(110, '487764', 'BRIGHTON ROSS KINEMO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(111, '707770', 'RAMADHANI WARYOBA ALLY', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(112, '696126', 'MEHJABEEN NAUSHAD MOHAMED', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(113, '688140', 'LEONCE BWENDA RWEYEMAMU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(114, '691091', 'GIFT VICENT MASSAWE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(115, '617875', 'NYOROBI JUMA KADO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(116, '638052', 'TEOPISTER FILBERT MBWILO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 10:11:54', '2025-09-05 10:11:54', NULL, '', NULL, 'normal'),
(117, '612233', 'KENNETH ONESMO MKAMA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 18:17:04', '2025-09-05 18:17:04', NULL, '', NULL, 'normal'),
(118, '703406', 'FARAMAS COMPANY LIMITED', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 18:17:04', '2025-09-05 18:17:04', NULL, '', NULL, 'normal'),
(119, '617942', 'ANITA EMMANUEL AMANI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 18:17:04', '2025-09-05 18:17:04', NULL, '', NULL, 'normal'),
(120, '654132', 'ZAKIA HASHIMU MVOGOGO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 18:17:04', '2025-09-05 18:17:04', NULL, '', NULL, 'normal'),
(121, '647925', 'QUIVER CAPITAL LIMITED - TRUST ACCO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 18:17:04', '2025-09-05 18:17:04', NULL, '', NULL, 'normal'),
(122, '652187', 'LAURENT JOSEPH LYATUU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 18:17:04', '2025-09-05 18:17:04', NULL, '', NULL, 'normal'),
(123, '547978', 'ALOYCE ANTHONY NOMBO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 18:17:05', '2025-09-05 18:17:05', NULL, '', NULL, 'normal'),
(124, '642853', 'HILDA IGNAS KAZIMOTO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-05 18:17:05', '2025-09-05 18:17:05', NULL, '', NULL, 'normal'),
(125, '722495', 'JASMINE KISSAMO ASSENGA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 20:58:26', '2025-09-07 20:58:26', NULL, '', NULL, 'normal'),
(126, '656375', 'DINESH BHANJI RANCHHOD', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 20:58:26', '2025-09-07 20:58:26', NULL, '', NULL, 'normal'),
(127, '581794', 'MICKIDAD HAMADI CHAKINDO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 20:58:26', '2025-09-07 20:58:26', NULL, '', NULL, 'normal'),
(128, '608837', 'HAGAI RICHARD MALEKO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 20:58:26', '2025-09-07 20:58:26', NULL, '', NULL, 'normal'),
(129, '660527', 'BRIGHTON ROSS KINEMO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 20:58:26', '2025-09-07 20:58:26', NULL, '', NULL, 'normal'),
(130, '620570', 'LUKIKO YOHANA MAILA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(131, '653011', 'THOBIAS AUGUSTINO JAMES', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(132, '649798', 'SANDRA SAFARI  SAMALI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(133, '716856', 'EVANS LEONARD SAASITA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(134, '696936', 'ZEPHANIA DEMITIRIO KILAU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(135, '671308', 'ELVIS MICHAEL PAUL', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(136, '627432', 'DAMASCO PETER MWAKIPOKILE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(137, '704346', 'PRISCA ANDREW LUKOMBESO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(138, '622824', 'FUNGA PROSPER KERENGE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(139, '545262', ' YASSIN RAMADHAN SWAI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(140, '696367', 'ROWLAND NICHOLAUS MRANGO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(141, '656030', 'ZUBERI AMIRI HASHIM', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(142, '679618', 'CLAUDIO CLARENCE MAYA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(143, '80053', 'JAMES SYLVESTER KACHOLI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(144, '697576', 'DIANA AMANIELI KIKAHO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-07 21:12:46', '2025-09-07 21:12:46', NULL, '', NULL, 'normal'),
(145, '707082', 'CRAN AND CONSOLATHA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-20 15:12:18', '2025-09-20 15:12:18', NULL, '', NULL, 'normal'),
(146, '638912', 'CELINE CRAN MUNGURE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-09-20 15:12:18', '2025-09-20 15:12:18', NULL, '', NULL, 'normal'),
(147, '717069', 'ANGELA CLAUDE KADASO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(148, '717067', 'SOPHIA MAHMOUD MBILIKIRA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(149, '717033', 'MWANAISHA SALEHE WAZIRI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(150, '717056', 'TUMPE DAIMON MWAITENDA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(151, '717722', 'VIOLETH MICHAEL BURUBA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(152, '717724', 'KUNDI MASANJA KIJA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(153, '717051', 'KARIM RAMADHANI LUKARI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(154, '717062', 'FLORIAN MARO MBASA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(155, '717057', 'FREDRICK KAIZA LWAMBUGWE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(156, '717048', 'SALUM SEIF SALUM', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(157, '717677', 'DEOGRATIUS MICHAEL URIO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(158, '717682', 'MONICA CHRISTOPHER NDOTO ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(159, '717725', 'JOHN BALTHAZARY MUDENDE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(160, '717730', 'UPENDO EXAUD MMARI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(161, '717045', 'KHAIRAT OMARY ALI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(162, '717049', 'CATHERINE MENDRAD NDUNGURU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(163, '717699', 'RAMADHANI ISSA SOSSORA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(164, '717715', 'HERMAN KACHIMA MOSES', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(165, '717708', 'ADAM GEORGE MANDIA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(166, '717690', 'SENAS FREDRICK KAVISHE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(167, '717061', 'ESTHER ELIAH MWANGONO ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(168, '717068', 'KENETH SIMON LYATUU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(169, '717066', 'MARGARETH SARAH ARTHUR MWAKAPUGI ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(170, '717678', 'ROBERT LODUVO NGILORITI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(171, '717689', 'MARYEMANUELLA MARIJANI MSOFFE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(172, '717047', 'THADEO FUKUDA RWEYAMBA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(173, '717044', 'DEBORAH SULEIMAN KERENGE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(174, '717684', 'AISA RAYMOND KIMARO ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(175, '717691', 'EMMANUEL PASCHAL KALUMUNA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(176, '717679', 'CLEMENT HERRY ONING\'O', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(177, '717717', 'MICHAEL RESPICE MASAKWIYA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(178, '717054', 'ROSELYNE  JASON TINEISHEMO ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(179, '717704', 'PHILBERT FRANCIS BAGENDA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(180, '717726', 'PAULINA HERIEL MSANGA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(181, '717709', 'BAKARI MDIMU MSANGI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(182, '717050', 'SAUMU ABDALLAH KILEO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(183, '717064', 'BETTY RAPHAEL MLEWA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(184, '717059', 'TUSEKILE GODFREY MWAIPUNGU', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(185, '717728', 'HELLEN HERMAN MTENYA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(186, '717703', 'EDDYNUR HILAL SUDI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(187, '717686', 'PRUNELLA KISSA NSEKELA ', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(188, '717680', 'GODBLESS SAMWEL SWAI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(189, '626970', 'TOGOLANI ELIAMINI MRAMBA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(190, '751581', 'MOLLEN CHARLES', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(191, '746583', 'MOLLEN CHARLES', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(192, '683864', 'HAIKA AUGUST KOMBE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(193, '744230', 'GERVAS PIUS MHENGA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(194, '745498', 'CHRISTOPHER PETER MATOLLAH', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(195, '683016', 'LINDA VICTOR MSAKI', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(196, '744631', 'VERONICA BENSON CHONJO', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(197, '642530', 'SERAPHINE THOMAS BAKIRANE', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:19:08', '2025-10-12 15:19:08', NULL, '', NULL, 'normal'),
(198, '614782', 'LIQUID FUND UNIT TRUST SCHEME', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', NULL, 'normal'),
(199, '588739', 'NSSF MAIN ACCOUNT', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', NULL, 'normal'),
(200, '648140', 'MILLICENT JOHN LEONARD', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', NULL, 'normal'),
(201, '710776', 'GLORIA ANDREW KOKWIJJUKA', 'individual', NULL, NULL, NULL, NULL, 1, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', NULL, 'normal');

-- --------------------------------------------------------

--
-- Table structure for table `client_merge_log`
--

CREATE TABLE `client_merge_log` (
  `id` int(11) NOT NULL,
  `primary_client_id` int(11) NOT NULL,
  `merged_client_id` int(11) NOT NULL,
  `merged_cds_account` varchar(50) NOT NULL,
  `merged_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `company_code` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `registration_number` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `logo_path` varchar(255) DEFAULT NULL,
  `header_image_path` varchar(255) DEFAULT NULL,
  `footer_image_path` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `priority` varchar(300) DEFAULT NULL,
  `branches` varchar(300) NOT NULL,
  `division` varchar(300) NOT NULL,
  `business_type` varchar(900) NOT NULL,
  `country` varchar(80) NOT NULL,
  `nationality` varchar(1000) NOT NULL,
  `currency` varchar(200) NOT NULL,
  `exchange` varchar(300) NOT NULL,
  `language` varchar(340) NOT NULL,
  `company_name` varchar(120) NOT NULL,
  `phone` varchar(300) NOT NULL,
  `footer` varchar(300) NOT NULL,
  `header` varchar(300) NOT NULL,
  `logo` varchar(300) NOT NULL,
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `company_code`, `name`, `registration_number`, `address`, `contact_person`, `mobile`, `email`, `is_active`, `created_at`, `logo_path`, `header_image_path`, `footer_image_path`, `updated_at`, `priority`, `branches`, `division`, `business_type`, `country`, `nationality`, `currency`, `exchange`, `language`, `company_name`, `phone`, `footer`, `header`, `logo`, `status`) VALUES
(1, 'B13', 'Victory Financial Services', '12367', 'P.O Box 675, Dar es Salaam, Tanzania', 'Siegfried Kuwite', '+255769296960', 'info@vfsl.co.tz', 1, '2025-08-23 18:56:06', 'uploads/companies/logos/1_logo_1760283107.png', 'uploads/companies/headers/1_header_1756492232.png', 'uploads/companies/footers/1_footer_1756492232.png', '2025-10-12 15:31:47', 'P0001', 'Main', 'Arusha', 'Stock broker', 'Tanzania', 'Tanzanian', 'Tzs', 'usd', 'Swahili/english', 'Victory Financial Services LTD', '0767676767', 'Asset 2.png', '', '', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `coupon_determiners`
--

CREATE TABLE `coupon_determiners` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `coupon_determiners`
--

INSERT INTO `coupon_determiners` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'FIXED', 'FIXED', 'P0001', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(2, 'FLOATING', 'FLOATING', 'P0002', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active'),
(3, 'ZERO', 'ZERO', 'P0003', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `custodians`
--

CREATE TABLE `custodians` (
  `id` int(11) NOT NULL,
  `custodian_code` varchar(20) NOT NULL,
  `custodian_name` varchar(200) NOT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `priority` varchar(30) DEFAULT NULL,
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `custodians`
--

INSERT INTO `custodians` (`id`, `custodian_code`, `custodian_name`, `license_number`, `address`, `contact_person`, `phone`, `email`, `is_active`, `created_at`, `priority`, `status`) VALUES
(1, 'CENTRAL', 'Central Securities Depository', NULL, NULL, 'CSD Operations', NULL, NULL, 1, '2025-08-21 15:09:38', 'P0001', 'active'),
(2, 'CUSTODY1', 'Primary Custodian Services', NULL, NULL, 'Custody Manager', NULL, NULL, 1, '2025-08-21 15:09:38', 'P0002', 'active'),
(3, 'CB01', 'STANCHART BANK LTD', 'LOCAL COMPANY', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', 'P0003', 'active'),
(4, 'CB02', 'CRDB BANK LTD', 'LOCAL COMPANY', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@crdb.co.tz', 1, '2025-08-21 15:09:58', 'P0004', 'active'),
(5, 'CB03', 'STANBIC BANK LTD', 'LOCAL COMPANY', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', 'P0005', 'active'),
(6, 'CB04', 'NATIONAL MICROFINACE BANK', 'LOCAL COMPANY', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', 'P0006', 'active'),
(7, 'CB05', 'NMB BANK LTD', 'LOCAL COMPANY', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', 'P0007', 'active'),
(8, 'CB06', 'ABSA BANK LTD', 'LOCAL COMPANY', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', 'P0008', 'active'),
(9, 'CB07', 'IM BANK LTD', 'LOCAL COMPANY', 'Dar es Salaam', 'Contact Person', '+255222112691', 'info@vfsl.co.tz', 1, '2025-08-21 15:09:58', 'P0009', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `equities`
--

CREATE TABLE `equities` (
  `id` int(11) NOT NULL,
  `security_id` varchar(50) NOT NULL,
  `stock_name` varchar(200) NOT NULL,
  `company_name` varchar(200) NOT NULL,
  `isin_code` varchar(20) DEFAULT NULL,
  `sector` varchar(100) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'USD',
  `par_value` decimal(10,4) DEFAULT NULL,
  `listing_date` date DEFAULT NULL,
  `status` enum('active','suspended','delisted') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `share_type` varchar(100) NOT NULL,
  `market_trend` varchar(300) NOT NULL,
  `economic_sector` varchar(300) NOT NULL,
  `market_price` varchar(500) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equities`
--

INSERT INTO `equities` (`id`, `security_id`, `stock_name`, `company_name`, `isin_code`, `sector`, `currency`, `par_value`, `listing_date`, `status`, `created_at`, `share_type`, `market_trend`, `economic_sector`, `market_price`, `updated_at`) VALUES
(1, 'DCB', 'GIVEN DIAZ CHAO', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '285', '2025-09-05 13:12:11'),
(2, 'VODA', 'KELVIN SILYVESTRY KOKA', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '600', '2025-09-05 13:12:11'),
(3, 'TBL', 'WONFAIR HOLDINGS LIMITED', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '7790', '2025-09-05 13:12:11'),
(4, 'CRDB', 'MOTTA PALIMAKA REUBEN KYANDO', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '1270', '2025-09-05 13:12:11'),
(5, 'MKCB', 'SIMON DAVID MALIGANYA', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '2600', '2025-09-01 08:19:11'),
(6, 'AFRIPRISE', 'ROBERT ADERITUS KATO', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '540', '2025-09-05 13:12:11'),
(7, 'NICO', 'JONATHAN JOHN MBAILUKA', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '1700', '2025-09-01 08:19:11'),
(8, 'TPCC', 'JOSEPH MAGWEIGA MARWA', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '5200', '2025-09-05 13:12:11'),
(9, 'PAL', 'HAFIDHI ATHUMANI SHABANI', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '305', '2025-09-05 13:11:43'),
(10, 'NMB', 'BRYTON FOCUS SUNGUYA', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '8170', '2025-09-01 08:19:11'),
(11, 'DSE', 'MICHAEL ADIMU MAKUKURA', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '4950', '2025-09-05 13:12:11'),
(12, 'MBP', 'ISAYA SIMON BURUSH', '', NULL, 'General', 'USD', NULL, NULL, 'active', '2025-08-23 21:21:26', 'O', '3', 'F', '950', '2025-09-01 08:19:11');

-- --------------------------------------------------------

--
-- Table structure for table `equities_settings`
--

CREATE TABLE `equities_settings` (
  `id` int(11) NOT NULL,
  `security_id` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `isin` varchar(50) DEFAULT NULL,
  `costing_basis` varchar(20) DEFAULT 'WAUC',
  `market_price` decimal(15,2) DEFAULT 0.00,
  `valuation_price` decimal(15,2) DEFAULT 0.00,
  `share_type` varchar(10) DEFAULT '0',
  `market_segment` varchar(10) DEFAULT 'MIM',
  `economic_sector` varchar(10) DEFAULT 'F',
  `market_trend` varchar(10) DEFAULT '3',
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equities_settings`
--

INSERT INTO `equities_settings` (`id`, `security_id`, `description`, `isin`, `costing_basis`, `market_price`, `valuation_price`, `share_type`, `market_segment`, `economic_sector`, `market_trend`, `priority`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'ACA', 'ACACIA MINING PLC', 'GB00B61D2N63', 'WAUC', 4850.00, 4850.00, '0', 'MIM', 'F', '3', 'P0001', 0, '2025-08-21 15:09:58', '2025-09-05 13:43:18'),
(2, 'CRDB', 'CRDB BANK PUBLIC LIMITED COMPANY', 'TZ1996100305', 'WAUC', 1270.00, 480.00, '0', 'MIM', 'F', '3', 'P0002', 1, '2025-08-21 15:09:58', '2025-09-20 16:10:49'),
(3, 'DSE', 'DAR ES SALAAM STOCK EXCHANGE PLC', 'TZ1996102434', 'WAUC', 4950.00, 1140.00, '0', 'MIM', 'F', '3', 'P0003', 1, '2025-08-21 15:09:58', '2025-09-20 16:10:49'),
(4, 'DCB', 'DCB COMMERCIAL BANK PLC', 'TZ1996100214', 'WAUC', 285.00, 380.00, '0', 'MIM', 'F', '3', 'P0004', 1, '2025-08-21 15:09:58', '2025-09-20 16:10:49'),
(5, 'EABL', 'EAST AFRICAN BREWERIES LIMITED', 'KE0000000216', 'WAUC', 5150.00, 5150.00, '0', 'MIM', 'F', '3', 'P0005', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(6, 'JATU', 'JATU PUBLIC LIMITED COMPANY', 'TZ1996103804', 'WAUC', 250.00, 250.00, '0', 'MIM', 'F', '3', 'P0006', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(7, 'JHL', 'JUBILEE HOLDINGS LIMITED', 'KE0000000273', 'WAUC', 10100.00, 10100.00, '0', 'MIM', 'F', '3', 'P0007', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(8, 'KA', 'KENYA AIRWAYS LIMITED', 'KE0000000307', 'WAUC', 110.00, 110.00, '0', 'MIM', 'F', '3', 'P0008', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(9, 'KCB', 'KENYA COMMERCIAL BANK LIMITED', 'KE0000000315', 'WAUC', 950.00, 950.00, '0', 'MIM', 'F', '3', 'P0009', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(10, 'MBP', 'MAENDELEO BANK PUBLIC LIMITED COMPANY', 'TZ1996101683', 'WAUC', 950.00, 600.00, '0', 'MIM', 'F', '3', 'P0010', 1, '2025-08-21 15:09:58', '2025-09-18 16:57:23'),
(11, 'MKCB', 'MKOMBOZI COMMERCIAL BANK PLC', 'TZ1996101972', 'WAUC', 2600.00, 890.00, '0', 'MIM', 'F', '3', 'P0011', 1, '2025-08-21 15:09:58', '2025-09-18 16:57:23'),
(12, 'MUCOBA', 'MUCOBA BANK PLC', 'TZ1996102419', 'WAUC', 400.00, 400.00, '0', 'MIM', 'F', '3', 'P0012', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(13, 'MCB', 'MWALIMU COMMERCIAL BANK PLC', 'TZ1996102129', 'WAUC', 245.00, 500.00, '0', 'MIM', 'F', '3', 'P0013', 1, '2025-08-21 15:09:58', '2025-09-07 21:12:46'),
(14, 'NMG', 'NATION MEDIA GROUP LIMITED', 'KE0000000380', 'WAUC', 2520.00, 2520.00, '0', 'MIM', 'F', '3', 'P0014', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(15, 'NICO', 'NATIONAL INVESTMENT COMPANY LIMITED', 'TZ1996103077', 'WAUC', 1700.00, 510.00, '0', 'MIM', 'F', '3', 'P0015', 1, '2025-08-21 15:09:58', '2025-09-18 16:57:23'),
(16, 'NMB', 'NATIONAL MICROFINANCE BANK PLC', 'TZ1996100222', 'WAUC', 8170.00, 2750.00, '0', 'MIM', 'F', '3', 'P0016', 1, '2025-08-21 15:09:58', '2025-09-18 16:57:23'),
(17, 'PAL', 'PRECISION AIR SERVICES PLC', 'TZ1996101048', 'WAUC', 260.00, 470.00, '0', 'MIM', 'F', '3', 'P0017', 1, '2025-08-21 15:09:58', '2025-09-18 16:57:23'),
(18, 'SWALA', 'SWALA OIL AND GAS (TANZANIA) PLC', 'TZ1996101865', 'WAUC', 500.00, 500.00, '0', 'MIM', 'F', '3', 'P0018', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(19, 'SWIS', 'SWISSPORT TANZANIA PLC', 'TZ1996100040', 'WAUC', 3500.00, 3500.00, '0', 'MIM', 'F', '3', 'P0019', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(20, 'TOCL', 'TANGA CEMENT COMPANY LIMITED', 'TZ1996100057', 'WAUC', 1200.00, 1200.00, '0', 'MIM', 'F', '3', 'P0020', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(21, 'TBL', 'TANZANIA BREWERIES LIMITED', 'TZ1996100016', 'WAUC', 7790.00, 13200.00, '0', 'MIM', 'F', '3', 'P0021', 1, '2025-08-21 15:09:58', '2025-09-20 16:10:49'),
(22, 'TCC', 'TANZANIA CIGARETTE COMPANY LIMITED', 'TZ1996100032', 'WAUC', 16800.00, 16800.00, '0', 'MIM', 'F', '3', 'P0022', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(23, 'TPCC', 'TANZANIA PORTLAND CEMENT COMPANY LIMITED', 'TZ1996100024', 'WAUC', 5200.00, 1460.00, '0', 'MIM', 'F', '3', 'P0023', 1, '2025-08-21 15:09:58', '2025-09-20 16:10:49'),
(24, 'TIP', 'TATEPA LIMITED', 'TZ1996100065', 'WAUC', 600.00, 600.00, '0', 'MIM', 'F', '3', 'P0024', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(25, 'TCIL', 'TCCIA INVESTMENT PLC', 'TZ1996105010', 'WAUC', 170.00, 170.00, '0', 'MIM', 'F', '3', 'P0025', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(26, 'TOL', 'TOL GASES LIMITED', 'TZ1996100008', 'WAUC', 780.00, 780.00, '0', 'MIM', 'F', '3', 'P0026', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(27, 'USL', 'UCHUMI SUPERMARKET LIMITED', 'KE0000000489', 'WAUC', 30.00, 30.00, '0', 'MIM', 'F', '3', 'P0027', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(28, 'UIT', 'UNIT TRUST OF TANZANIA', '', 'WAUC', 0.00, 0.00, '0', 'MIM', 'F', '3', 'P0028', 0, '2025-08-21 15:09:58', '2025-09-05 13:42:41'),
(29, 'VODA', 'VODACOM TANZANIA PUBLIC LIMITED COMPANY', 'TZ1996102715', 'WAUC', 600.00, 850.00, '0', 'MIM', 'F', '3', 'P0029', 1, '2025-08-21 15:09:58', '2025-09-20 16:10:49'),
(30, 'YETU', 'YETU MICROFINANCE PUBLIC LIMITED COMPANY', 'TZ1996102344', 'WAUC', 600.00, 600.00, '0', 'MIM', 'F', '3', 'P0030', 1, '2025-08-21 15:09:58', '2025-08-21 15:09:58'),
(31, 'AFRIPRISE', 'AFRIPRISE COMPANY LTD', 'TZ19961056677', 'WAUC', 540.00, 560.00, '0', 'MIM', 'F', '3', 'P0001', 1, '2025-09-05 18:07:51', '2025-09-20 16:10:49');

-- --------------------------------------------------------

--
-- Table structure for table `expenses_benefits`
--

CREATE TABLE `expenses_benefits` (
  `id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `type` enum('expense','benefit') NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transaction_date` date NOT NULL,
  `bank_reference` varchar(100) DEFAULT NULL,
  `supporting_document` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expenses_benefits`
--

INSERT INTO `expenses_benefits` (`id`, `reference_number`, `type`, `category`, `description`, `amount`, `transaction_date`, `bank_reference`, `supporting_document`, `recorded_by`, `approved_by`, `status`, `created_at`, `updated_at`) VALUES
(1, 'EXP202508215930', 'expense', 'Office supplies', 'jjsdbjkbjksbjhvbs', 126000.00, '2025-08-21', '09777887667', '', 2, 2, 'approved', '2025-08-21 15:29:01', '2025-08-21 15:29:23');

-- --------------------------------------------------------

--
-- Table structure for table `fee_configurations`
--

CREATE TABLE `fee_configurations` (
  `id` int(11) NOT NULL,
  `fee_type` varchar(50) NOT NULL,
  `fee_name` varchar(100) NOT NULL,
  `rate_percentage` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `fixed_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `applies_to` enum('EQUITY','BOND','TREASURY_BILL','CORPORATE_BOND','ALL') DEFAULT 'ALL',
  `calculation_base` enum('CONSIDERATION','COMMISSION','PRICE') DEFAULT 'CONSIDERATION',
  `is_active` tinyint(1) DEFAULT 1,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_configurations`
--

INSERT INTO `fee_configurations` (`id`, `fee_type`, `fee_name`, `rate_percentage`, `fixed_amount`, `applies_to`, `calculation_base`, `is_active`, `description`, `created_at`, `updated_at`) VALUES
(1, 'BROKERAGE', 'Brokerage Commission - General', 1.7000, 0.00, 'ALL', 'CONSIDERATION', 1, 'General brokerage commission rate', '2025-08-21 15:09:17', '2025-08-21 15:09:17'),
(2, 'fixed', 'Brokerage Commission - Equities', 1.7000, 0.00, 'EQUITY', 'CONSIDERATION', 1, 'Brokerage commission rate for equities', '2025-08-21 15:09:17', '2025-10-12 16:10:53'),
(3, 'BROKERAGE', 'Brokerage Commission - Treasury Bills', 0.3000, 0.00, 'TREASURY_BILL', 'CONSIDERATION', 1, 'Brokerage commission rate for treasury bills', '2025-08-21 15:09:17', '2025-08-21 15:09:17'),
(4, 'fixed', 'Brokerage Commission - Treasury Bonds', 0.2000, 0.00, 'BOND', 'COMMISSION', 1, 'Brokerage commission rate for treasury bonds', '2025-08-21 15:09:17', '2025-10-09 17:33:06'),
(5, 'BROKERAGE', 'Brokerage Commission - Corporate Bonds', 0.0300, 0.00, 'CORPORATE_BOND', 'CONSIDERATION', 1, 'Brokerage commission rate for corporate bonds', '2025-08-21 15:09:17', '2025-08-21 15:09:17'),
(6, 'VAT', 'VAT on Brokerage', 18.0000, 0.00, 'ALL', 'COMMISSION', 1, 'Value Added Tax on brokerage commission', '2025-08-21 15:09:17', '2025-08-21 15:09:17'),
(7, 'CMSA', 'CMSA Transaction Fee', 0.1400, 0.00, 'ALL', 'CONSIDERATION', 1, 'Capital Markets and Securities Authority transaction fee', '2025-08-21 15:09:17', '2025-08-21 15:09:17'),
(8, 'DSE', 'DSE Transaction Fee', 0.1652, 0.00, 'ALL', 'CONSIDERATION', 1, 'Dar es Salaam Stock Exchange transaction fee (VAT inclusive)', '2025-08-21 15:09:17', '2025-08-21 15:09:17'),
(9, 'FIDELITY', 'Fidelity Fee', 0.0200, 0.00, 'ALL', 'CONSIDERATION', 1, 'Fidelity insurance fee', '2025-08-21 15:09:17', '2025-08-21 15:09:17'),
(10, 'CDS', 'CDS Transaction Fee', 0.0708, 0.00, 'ALL', 'CONSIDERATION', 1, 'Central Depository System transaction fee (VAT inclusive)', '2025-08-21 15:09:17', '2025-08-21 15:09:17');

-- --------------------------------------------------------

--
-- Table structure for table `fee_configuration_audit`
--

CREATE TABLE `fee_configuration_audit` (
  `id` int(11) NOT NULL,
  `fee_configuration_id` int(11) NOT NULL,
  `old_rate_percentage` decimal(8,4) DEFAULT NULL,
  `new_rate_percentage` decimal(8,4) DEFAULT NULL,
  `old_fixed_amount` decimal(10,2) DEFAULT NULL,
  `new_fixed_amount` decimal(10,2) DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `change_reason` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gl_account_formats`
--

CREATE TABLE `gl_account_formats` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `gl_account_formats`
--

INSERT INTO `gl_account_formats` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'HD', 'HEADER', 'P0001', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(2, 'VP', 'VALID POSTING', 'P0002', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(3, 'ST', 'SUB TOTAL', 'P0003', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(4, 'TT', 'SUB SUB TOTAL', 'P0004', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(5, 'GT', 'GRAND TOTAL', 'P0005', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `gl_account_types`
--

CREATE TABLE `gl_account_types` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `allocation_from` int(11) NOT NULL,
  `allocation_upto` int(11) NOT NULL,
  `closing_entry_type` varchar(50) NOT NULL,
  `socf_effects` varchar(50) DEFAULT 'SOCF-EFFECTS',
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `gl_account_types`
--

INSERT INTO `gl_account_types` (`id`, `code`, `description`, `allocation_from`, `allocation_upto`, `closing_entry_type`, `socf_effects`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'I', 'INCOME', 10000, 19999, 'TEMPORARY ACCOUNT', 'SOCF-EFFECTS', 'P0001', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(2, 'E', 'EXPENSE', 30000, 39999, 'TEMPORARY ACCOUNT', 'SOCF-EFFECTS', 'P0002', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(3, 'A', 'ASSET', 70000, 79999, 'PERMANENT ACCOUNT', 'SOCF-EFFECTS', 'P0003', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(4, 'L', 'LIABILITY', 70000, 79999, 'PERMANENT ACCOUNT', 'SOCF-EFFECTS', 'P0004', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `identity_types`
--

CREATE TABLE `identity_types` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `priority` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `identity_types`
--

INSERT INTO `identity_types` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'ID', 'NATIONAL IDENTITY CARD', '1', 1, '2025-09-05 17:24:11', '2025-09-05 17:24:11', 'active'),
(2, 'PP', 'PASSPORT', '2', 1, '2025-09-05 17:24:11', '2025-09-05 17:24:11', 'active'),
(3, 'CR', 'CERTIFICATE OF INCORPORATION/REGISTRATION', '3', 1, '2025-09-05 17:24:11', '2025-09-05 17:24:11', 'active'),
(4, 'DL', 'DRIVING LICENSE', '4', 1, '2025-09-05 17:24:11', '2025-09-05 17:24:11', 'active'),
(5, 'VI', 'VOTER IDENTITY CARD', '5', 1, '2025-09-05 17:24:11', '2025-09-05 17:24:11', 'active'),
(6, 'BC', 'BIRTH CERTIFICATE', '6', 1, '2025-09-05 17:24:11', '2025-09-05 17:24:11', 'active'),
(7, 'SD', 'STUDENT IDENTITY CARD', '7', 1, '2025-09-05 17:24:11', '2025-09-05 17:24:11', 'active'),
(8, 'WL', 'WARD LETTER', '8', 1, '2025-09-05 17:24:11', '2025-09-05 17:24:11', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `investments_costing_basis`
--

CREATE TABLE `investments_costing_basis` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1 COMMENT '1=active, 0=inactive',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `investments_costing_basis`
--

INSERT INTO `investments_costing_basis` (`id`, `code`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'FIFO', 'First In First Out', 1, '2025-09-05 13:34:48', '2025-09-05 13:34:48'),
(2, 'LIFO', 'Last In First Out', 1, '2025-09-05 13:35:34', '2025-09-05 13:35:34'),
(3, 'WAUC', 'Weighted Average Unit Cost', 1, '2025-09-05 13:36:18', '2025-09-05 13:36:18');

-- --------------------------------------------------------

--
-- Table structure for table `investment_asset_classes`
--

CREATE TABLE `investment_asset_classes` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `min_quantity` bigint(20) DEFAULT 1,
  `lot_size` bigint(20) DEFAULT 1,
  `commission_rate` decimal(8,4) DEFAULT 0.0000,
  `min_commission` decimal(10,2) DEFAULT 0.00,
  `return_commission` decimal(10,2) DEFAULT 0.00,
  `return_commission_date` date DEFAULT NULL,
  `costing_method` enum('FIFO','WAUC','LIFO') DEFAULT 'FIFO',
  `priority` varchar(10) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `per` varchar(100) NOT NULL,
  `td` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `investment_asset_classes`
--

INSERT INTO `investment_asset_classes` (`id`, `code`, `description`, `min_quantity`, `lot_size`, `commission_rate`, `min_commission`, `return_commission`, `return_commission_date`, `costing_method`, `priority`, `is_active`, `created_at`, `updated_at`, `per`, `td`) VALUES
(1, 'CASH', 'CASH', 1, 1, 0.0000, 0.00, 0.00, '2025-09-01', 'FIFO', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '1', '0'),
(2, 'EQUT', 'EQUITIES', 1, 1, 2.0600, 0.00, 0.00, '2023-10-05', 'WAUC', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '100', '3'),
(3, 'TBIL', 'TREASURY BILLS', 500000, 10000, 0.3000, 50000.00, 0.00, '2025-09-01', 'FIFO', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '100', '1'),
(4, 'TBON', 'TREASURY BONDS', 100000, 100, 0.2000, 50000.00, 0.00, '2024-06-06', 'FIFO', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '100', '1'),
(5, 'FDEP', 'FIXED TERM DEPOSITS', 1, 1, 0.0000, 0.00, 0.00, '2025-09-01', 'FIFO', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '0', '0'),
(6, 'CPAP', 'COMMERCIAL PAPER', 50000, 10000, 0.0600, 0.00, 0.00, '2025-09-01', 'WAUC', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '100', '3'),
(7, 'CBON', 'CORPORATE BONDS', 100000, 100000, 0.0300, 50000.00, 0.00, '2025-09-01', 'FIFO', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '100', '1'),
(8, 'MBON', 'MUNICIPAL BONDS', 100000, 100000, 0.0300, 50000.00, 0.00, '2025-09-01', 'FIFO', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '100', '1'),
(9, 'REIT', 'REAL ESTATE INVESTMENT TRUSTS', 100000, 100000, 0.0300, 50000.00, 0.00, '2025-09-01', 'FIFO', NULL, 1, '2025-09-05 15:55:17', '2025-09-05 15:55:17', '100', '1');

-- --------------------------------------------------------

--
-- Table structure for table `ledger_types`
--

CREATE TABLE `ledger_types` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  `gl_account` varchar(100) NOT NULL,
  `is_account_no_auto` enum('YES','NO') DEFAULT 'NO',
  `is_file_no_auto` enum('YES','NO') DEFAULT 'NO',
  `is_csdn_required` enum('YES','NO') DEFAULT 'NO',
  `is_bank_required` enum('YES','NO') DEFAULT 'NO',
  `is_joint_holder_required` enum('YES','NO') DEFAULT 'NO',
  `is_cashbook_disabled` enum('YES','NO') DEFAULT 'NO',
  `receipt_narrative` varchar(255) DEFAULT NULL,
  `payment_narrative` varchar(255) DEFAULT NULL,
  `petty_cash_narrative` varchar(255) DEFAULT NULL,
  `priority` varchar(50) DEFAULT NULL,
  `is_active` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ledger_types`
--

INSERT INTO `ledger_types` (`id`, `code`, `description`, `gl_account`, `is_account_no_auto`, `is_file_no_auto`, `is_csdn_required`, `is_bank_required`, `is_joint_holder_required`, `is_cashbook_disabled`, `receipt_narrative`, `payment_narrative`, `petty_cash_narrative`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'A', 'AGENT', '73111 - AGENTS CONTROL A/C', 'YES', 'YES', 'NO', 'NO', 'NO', 'NO', 'RETURN COMM', 'SETTLEMENT OF', 'RETURN COMM', 'P0001', 'Active', '2025-09-05 16:42:05', '2025-09-05 16:42:39', 'active'),
(2, 'B', 'BROKER', '72714 - BROKERS CONTROL A/C', 'NO', 'YES', 'NO', 'NO', 'NO', 'NO', 'SETTLEMENT OF', 'SETTLEMENT OF', 'SETTLEMENT OF', 'P0002', 'Active', '2025-09-05 16:42:05', '2025-09-05 16:42:05', 'active'),
(3, 'C', 'SUPPLIER', '73101 - SUPPLIERS CONTROL A/C', 'YES', 'YES', 'YES', 'NO', 'NO', 'NO', 'RECEIPT', 'PAYMENT', 'REIMBURSEMENT', 'P0003', 'Active', '2025-09-05 16:42:05', '2025-09-05 16:42:05', 'active'),
(4, 'D', 'CUSTOMER', '72711 - CUSTOMERS CONTROL A/C', 'YES', 'YES', 'NO', 'NO', 'NO', 'NO', 'RECEIPT', 'PAYMENT', 'REIMBURSEMENT', 'P0004', 'Active', '2025-09-05 16:42:05', '2025-09-05 16:42:05', 'active'),
(5, 'O', 'NOMINAL CLIENT', '73113 - CLIENTS CONTROL A/C', 'YES', 'YES', 'YES', 'NO', 'NO', 'NO', 'SETTLEMENT OF', 'PAYMENT ON ACCOUNT', 'SETTLEMENT OF', 'P0006', 'Active', '2025-09-05 16:42:05', '2025-09-05 16:42:05', 'active'),
(6, 'U', 'CUSTODIAN', '72114 - CUSTODIANS CONTROL A/C', 'NO', 'YES', 'NO', 'NO', 'NO', 'NO', 'SETTLEMENT OF', 'PAYMENT ON ACCOUNT', 'SETTLEMENT OF', 'P0008', 'Active', '2025-09-05 16:42:05', '2025-09-05 16:42:05', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `payment_frequencies`
--

CREATE TABLE `payment_frequencies` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `compounding_periods_per_year` int(11) NOT NULL DEFAULT 1,
  `cash_flow` decimal(15,2) DEFAULT 0.00,
  `nominal_interest_rate` decimal(8,4) DEFAULT 0.0000,
  `discounting_factor` decimal(8,4) DEFAULT 0.0000,
  `effective_annual_rate` decimal(8,4) DEFAULT 0.0000,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_frequencies`
--

INSERT INTO `payment_frequencies` (`id`, `code`, `description`, `compounding_periods_per_year`, `cash_flow`, `nominal_interest_rate`, `discounting_factor`, `effective_annual_rate`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'A', 'ANNUALLY', 1, 0.00, 0.0000, 0.8264, 10.0000, 'P0001', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(2, 'H', 'BIANNUALLY', 2, 0.00, 0.0000, 0.8227, 10.2500, 'P0002', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(3, 'Q', 'QUARTERLY', 4, 0.00, 0.0000, 0.8207, 10.3813, 'P0003', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(4, 'M', 'MONTHLY', 12, 0.00, 0.0000, 0.8194, 10.4713, 'P0004', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(5, 'T', 'BIMONTHLY', 24, 0.00, 0.0000, 0.8191, 10.4941, 'P0005', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(6, 'W', 'WEEKLY', 52, 0.00, 0.0000, 0.8189, 10.5065, 'P0006', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(7, 'K', 'BIWEEKLY', 26, 0.00, 0.0000, 0.8190, 10.4959, 'P0007', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(8, 'D', 'DAILY', 365, 0.00, 0.0000, 0.8188, 10.5156, 'P0008', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `cashbook` varchar(100) NOT NULL,
  `priority` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `utilization_percent` varchar(300) NOT NULL,
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `code`, `description`, `cashbook`, `priority`, `is_active`, `created_at`, `updated_at`, `utilization_percent`, `status`) VALUES
(1, 'BC', 'BANKERS CHEQUE', 'CRDB BANK', 'P0001', 1, '2025-09-05 16:16:09', '2025-09-05 16:16:27', '0.00', 'active'),
(2, 'BO', 'BOT DIRECT TRANSFER', 'CRDB BANK', 'P0002', 1, '2025-09-05 16:16:09', '2025-09-05 16:16:33', '0.00', 'active'),
(3, 'CA', 'CASH', 'PETTY CASH', 'P0003', 1, '2025-09-05 16:16:09', '2025-09-05 16:16:37', '0.00', 'active'),
(4, 'CH', 'CHEQUE', 'CRDB BANK', 'P0004', 1, '2025-09-05 16:16:09', '2025-09-05 16:16:41', '0.00', 'active'),
(5, 'DB', 'DIRECT BANKING', 'CRDB BANK', 'P0005', 1, '2025-09-05 16:16:09', '2025-09-05 16:16:45', '0.00', 'active'),
(6, 'DD', 'DSE DIRECT DEBIT', 'CRDB BANK', 'P0006', 1, '2025-09-05 16:16:09', '2025-09-05 16:16:49', '0.00', 'active'),
(7, 'DE', 'DIRECT DEBIT', 'CRDB BANK', 'P0007', 1, '2025-09-05 16:16:09', '2025-09-05 16:16:53', '0.00', 'active'),
(8, 'DS', 'DSE DIRECT BANKING', 'CRDB BANK', 'P0008', 1, '2025-09-05 16:16:09', '2025-09-05 16:16:59', '0.00', 'active'),
(9, 'DT', 'DIRECT TRANSFER', 'CRDB BANK', 'P0009', 1, '2025-09-05 16:16:09', '2025-09-05 16:17:03', '0.00', 'active'),
(10, 'FO', 'FOREX', 'CRDB BANK', 'P0010', 1, '2025-09-05 16:16:09', '2025-09-05 16:17:09', '0.00', 'active'),
(11, 'MO', 'MONEY ORDER', 'CRDB BANK', 'P0011', 1, '2025-09-05 16:16:09', '2025-09-05 16:17:13', '0.00', 'active'),
(12, 'TT', 'TELEGRAPHIC TRANSFER', 'CRDB BANK', 'P0012', 1, '2025-09-05 16:16:09', '2025-09-05 16:17:18', '0.00', 'active'),
(13, 'WA', 'WARRANT', 'CRDB BANK', 'P0013', 1, '2025-09-05 16:16:09', '2025-09-05 16:17:22', '0.00', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `share_market_segments`
--

CREATE TABLE `share_market_segments` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `status_id` tinyint(1) DEFAULT 1 COMMENT '1=active, 0=inactive',
  `status` varchar(20) GENERATED ALWAYS AS (case `status_id` when 1 then 'active' when 0 then 'inactive' end) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `share_market_segments`
--

INSERT INTO `share_market_segments` (`id`, `code`, `description`, `status_id`, `created_at`, `updated_at`) VALUES
(1, 'MIM', 'MAIN INVESTIMENT MARKET SEGMENT', 1, '2025-09-05 13:54:35', '2025-09-05 13:54:35'),
(2, 'EGM', 'Enterprise Growth Market', 1, '2025-09-05 14:02:09', '2025-09-05 14:02:09'),
(3, 'AIM', 'Alternative Investiment Market Segment', 1, '2025-09-05 14:03:03', '2025-09-05 14:03:03'),
(4, 'FIM', 'Fixed Income Securities Market Segment', 1, '2025-09-05 14:03:54', '2025-09-05 14:03:54');

-- --------------------------------------------------------

--
-- Table structure for table `share_market_trends`
--

CREATE TABLE `share_market_trends` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `share_market_trends`
--

INSERT INTO `share_market_trends` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, '1', 'SPECULATION', 'P0001', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(2, '2', 'SHORT TERM', 'P0002', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(3, '3', 'LONG TERM', 'P0003', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(4, '4', 'PENDING', 'P0004', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active'),
(5, '5', 'EXIT', 'P0005', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `share_types`
--

CREATE TABLE `share_types` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` varchar(100) NOT NULL,
  `priority` varchar(20) DEFAULT 'P0001',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `share_types`
--

INSERT INTO `share_types` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'O', 'ORDINARY', 'P0001', 1, '2025-08-21 15:09:57', '2025-08-29 19:12:17', 'active'),
(2, 'P', 'PREFERENCE', 'P0002', 1, '2025-08-21 15:09:57', '2025-08-29 19:12:22', 'active'),
(3, 'U', 'UNQUOTED', 'P0003', 1, '2025-08-21 15:09:57', '2025-08-21 15:09:57', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `sub_ledger_categories`
--

CREATE TABLE `sub_ledger_categories` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `classification` enum('LOCAL','FOREIGN') NOT NULL,
  `priority` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_ledger_categories`
--

INSERT INTO `sub_ledger_categories` (`id`, `code`, `description`, `classification`, `priority`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'EC', 'EAST AFRICA COMPANY', 'LOCAL', '1', 1, '2025-09-05 17:07:26', '2025-09-05 17:07:26'),
(2, 'ΕΙ', 'EAST AFRICA INDIVIDUAL', 'LOCAL', '3', 1, '2025-09-05 17:07:26', '2025-09-05 17:07:26'),
(3, 'LC', 'LOCAL COMPANY', 'LOCAL', '17', 1, '2025-09-05 17:07:26', '2025-09-05 17:07:26'),
(4, 'LI', 'LOCAL INDIVIDUAL', 'LOCAL', '9013', 1, '2025-09-05 17:07:26', '2025-09-05 17:07:26'),
(5, 'CE', 'COMPANY EMPLOYEE', 'LOCAL', '0', 1, '2025-09-05 17:07:26', '2025-09-05 17:07:26'),
(6, 'FC', 'FOREIGN COMPANY', 'FOREIGN', '0', 1, '2025-09-05 17:07:26', '2025-09-05 17:07:26'),
(7, 'FI', 'FOREIGN INDIVIDUAL', 'FOREIGN', '4', 1, '2025-09-05 17:07:26', '2025-09-05 17:07:26');

-- --------------------------------------------------------

--
-- Table structure for table `sub_ledger_groups`
--

CREATE TABLE `sub_ledger_groups` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `classification` varchar(100) NOT NULL,
  `priority` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `percentage` varchar(300) NOT NULL,
  `idm` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_ledger_groups`
--

INSERT INTO `sub_ledger_groups` (`id`, `code`, `description`, `classification`, `priority`, `is_active`, `created_at`, `updated_at`, `percentage`, `idm`) VALUES
(1, 'COMPANY', 'CORPORATE', '9021', 'P0001', 1, '2025-09-05 16:52:07', '2025-09-05 16:54:32', '99.80', 1),
(2, 'HNWI', 'HIGH NET WORTH INDIVIDUAL', '0', 'P0002', 1, '2025-09-05 16:52:07', '2025-09-05 16:54:37', '0.00', 12),
(3, 'II', 'INSTITUITIONAL INVESTOR', '0', 'P0003', 1, '2025-09-05 16:52:07', '2025-09-05 16:54:42', '0.00', 3),
(4, 'RETAIL', 'INDIVIDUAL', '18', 'P0004', 1, '2025-09-05 16:52:07', '2025-09-05 16:54:46', '0.20', 4),
(5, 'SME', 'SMALL & MEDIUM ENTERPRISE', '0', 'P0005', 1, '2025-09-05 16:52:07', '2025-09-05 16:54:52', '0.00', 5),
(6, 'VHNWI', 'VERY HIGH NET WORTH INDIVIDUAL', '0', 'P0006', 1, '2025-09-05 16:52:07', '2025-09-05 16:55:03', '0.00', 6);

-- --------------------------------------------------------

--
-- Table structure for table `sub_ledger_related_parties`
--

CREATE TABLE `sub_ledger_related_parties` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `priority` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sub_ledger_status`
--

CREATE TABLE `sub_ledger_status` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `inactivity_days_from` int(11) DEFAULT 0,
  `inactivity_days_upto` int(11) DEFAULT 0,
  `priority` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_ledger_status`
--

INSERT INTO `sub_ledger_status` (`id`, `code`, `description`, `inactivity_days_from`, `inactivity_days_upto`, `priority`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'P', 'PENDING', 0, 0, '1', 1, '2025-09-05 17:14:27', '2025-09-05 17:14:27'),
(2, 'A', 'ACTIVE', 0, 365, '2', 1, '2025-09-05 17:14:27', '2025-09-05 17:14:27'),
(3, 'I', 'INACTIVE', 366, 729, '3', 1, '2025-09-05 17:14:27', '2025-09-05 17:14:27'),
(4, 'D', 'DORMANT', 730, 999999, '4', 1, '2025-09-05 17:14:27', '2025-09-05 17:14:27'),
(5, 'F', 'FROZEN', 0, 0, '5', 1, '2025-09-05 17:14:27', '2025-09-05 17:14:27'),
(6, 'B', 'BLOCKED', 0, 0, '6', 1, '2025-09-05 17:14:27', '2025-09-05 17:14:27'),
(7, 'C', 'CLOSED', 0, 0, '7', 1, '2025-09-05 17:14:27', '2025-09-05 17:14:27');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_by`, `updated_at`) VALUES
(1, 'system_name', 'Stock Exchange Data Storage System', 'Name of the system', 2, '2025-08-29 18:19:14'),
(2, 'company_name', 'Victory Financial Services LTD', 'Company operating the exchange', 2, '2025-08-29 18:19:14'),
(3, 'receipt_prefix', 'RCP', 'Prefix for receipt numbers', 2, '2025-08-29 18:19:14'),
(4, 'invoice_prefix', 'INV', 'Prefix for invoice numbers', 2, '2025-08-29 18:19:14'),
(5, 'trade_prefix', 'TRD', 'Prefix for trade reference numbers', 2, '2025-08-29 18:19:14');

-- --------------------------------------------------------

--
-- Table structure for table `titles`
--

CREATE TABLE `titles` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `priority` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `titles`
--

INSERT INTO `titles` (`id`, `code`, `description`, `priority`, `is_active`, `created_at`, `updated_at`, `status`) VALUES
(1, 'BRG', 'BRIGADIER', '1', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(2, 'CPT', 'CAPTAIN', '2', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(3, 'COL', 'COLONEL', '3', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(4, 'CO', 'COMPANY', '4', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(5, 'CPL', 'CORPORAL', '5', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(6, 'DR', 'DOCTOR', '6', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(7, 'ENG', 'ENGINEER', '7', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(8, 'GEN', 'GENERAL', '8', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(9, 'HON', 'HONOURABLE', '9', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(10, 'LT', 'LIEUTENANT', '10', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(11, 'MAJ', 'MAJOR', '11', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(12, 'MESS', 'MESSRS', '12', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(13, 'MINOR', 'MINOR', '13', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(14, 'MISS', 'MISS', '14', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(15, 'MR', 'MR', '15', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(16, 'MRS', 'MRS', '16', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(17, 'MS', 'MS', '17', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(18, 'PROF', 'PROFESSOR', '18', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(19, 'REV', 'REVEREND', '19', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(20, 'SGT', 'SERGEANT', '20', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active'),
(21, 'SR', 'SISTER', '21', 1, '2025-09-05 17:21:40', '2025-09-05 17:21:40', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `trades`
--

CREATE TABLE `trades` (
  `id` int(11) NOT NULL,
  `trade_reference` varchar(50) NOT NULL,
  `asset_class` enum('bond','equity') NOT NULL,
  `security_id` varchar(50) NOT NULL,
  `security_name` varchar(200) NOT NULL,
  `client_cds_account` varchar(50) NOT NULL,
  `client_name` varchar(200) NOT NULL,
  `capacity` enum('principal','agency') NOT NULL,
  `broker_name` varchar(200) NOT NULL,
  `counterparty_broker` varchar(200) NOT NULL,
  `counterparty_name` varchar(200) NOT NULL,
  `counterparty_cds_account` varchar(50) DEFAULT NULL,
  `trade_side` enum('buy','sell') NOT NULL,
  `quantity` bigint(20) NOT NULL,
  `price` decimal(15,6) NOT NULL,
  `rate` decimal(15,6) NOT NULL,
  `consideration` decimal(20,2) NOT NULL,
  `trade_date` date NOT NULL,
  `settlement_date` date NOT NULL,
  `maturity_date` date DEFAULT NULL,
  `exchange_reference` varchar(50) DEFAULT NULL,
  `origin` varchar(50) DEFAULT NULL,
  `time_executed` time DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'USD',
  `status` enum('active','cancelled','settled') DEFAULT 'active',
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_value` varchar(700) DEFAULT NULL,
  `client_title` varchar(300) NOT NULL,
  `client_identity_type` varchar(300) NOT NULL,
  `custom_brokerage_fee` decimal(10,2) DEFAULT NULL,
  `final_brokerage_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `brokerage_fee_type` enum('normal','liberty','this_trade') NOT NULL DEFAULT 'normal'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trades`
--

INSERT INTO `trades` (`id`, `trade_reference`, `asset_class`, `security_id`, `security_name`, `client_cds_account`, `client_name`, `capacity`, `broker_name`, `counterparty_broker`, `counterparty_name`, `counterparty_cds_account`, `trade_side`, `quantity`, `price`, `rate`, `consideration`, `trade_date`, `settlement_date`, `maturity_date`, `exchange_reference`, `origin`, `time_executed`, `currency`, `status`, `uploaded_by`, `created_at`, `updated_at`, `total_value`, `client_title`, `client_identity_type`, `custom_brokerage_fee`, `final_brokerage_fee`, `brokerage_fee_type`) VALUES
(333, 'TRD202509181557', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '713875', 'SILAS BUGAMI KATEMI', 'principal', '', '', 'VICTORY FINANCIAL SERVICES LTD', '574738', 'buy', 500000000, 112.000000, 0.000000, 559999500.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(334, 'TRD202509182227', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '640702', 'BARAKA RAYMOND KIMARO', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '547505', 'sell', 500000000, 113.276300, 0.000000, 566381000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(335, 'TRD202509185063', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '547505', 'SOLOMON STOCKBROKERS LIMITED', 'principal', '', '', 'CRDB BANK PLC BROKERAGE', '', 'buy', 150000000, 103.937300, 0.000000, 155905950.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(336, 'TRD202509185261', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '574738', 'VICTORY FINANCIAL SERVICES LTD', 'principal', '', '', 'MAGDALENA EZEKIEL  NAKOMOLWA', '730975', 'sell', 300000000, 112.000000, 0.000000, 335999700.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(337, 'TRD202509182994', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '627905', 'NANCY FELIX KISHA', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '547505', 'sell', 500000000, 113.276300, 0.000000, 566381000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(338, 'TRD202509185806', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '608973', 'MAURICE SIEGFRIED KUWITE', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '547505', 'sell', 500000000, 113.276300, 0.000000, 566381000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(339, 'TRD202509182398', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '730975', 'MAGDALENA EZEKIEL  NAKOMOLWA', 'principal', '', '', 'VICTORY FINANCIAL SERVICES LTD', '574738', 'buy', 300000000, 112.000000, 0.000000, 335999700.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(340, 'TRD202509184083', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '717705', 'TINNER ALEXANDER MOSHA ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(341, 'TRD202509184877', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '718335', 'ROSE JOHN SHAWA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(342, 'TRD202509186506', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '723500', 'SAID ABDU TEMBA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(343, 'TRD202509184313', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '717024', 'RAHEL FESTUS ISOKOZA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(344, 'TRD202509180635', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '718653', 'PHILIPO DAUDI JOHN', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(345, 'TRD202509182912', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '722440', 'REMMY JOHN KAYUMBE ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(346, 'TRD202509185072', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '720674', 'RAMADHANI RASHIDI HAULE', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(347, 'TRD202509187920', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '717963', 'OLIVA GURTU BURA ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(348, 'TRD202509187995', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '720090', 'SAFINA HAMIM NDEMBO', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(349, 'TRD202509188665', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '717026', 'SHABANI SAIDI MNAROMA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 150000000, 100.000000, 0.000000, 150000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(350, 'TRD202509184782', 'bond', '639-12.56-T12-A1', 'Bond 639-12.56-T12-A1', '640000', 'HYPERCAPITAL LIMITED', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 116000000, 84.000000, 0.000000, 97440000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(351, 'TRD202509189689', 'bond', '666-15.75-T15-A1', 'Bond 666-15.75-T15-A1', '612233', 'KENNETH ONESMO MKAMA', 'principal', '', '', 'CRDB BANK PLC BROKERAGE', '', 'sell', 100000000, 110.000000, 0.000000, 110000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(352, 'TRD202509183667', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '547808', 'ANNANDUMI PAUL MEENA', 'principal', '', '', 'MARY JOHN MPONDA', '586789', 'buy', 100000000, 103.500000, 0.000000, 103499900.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(353, 'TRD202509185137', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '586789', 'MARY JOHN MPONDA', 'principal', '', '', 'ANNANDUMI PAUL MEENA', '547808', 'sell', 100000000, 103.500000, 0.000000, 103499900.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(354, 'TRD202509185307', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '640000', 'HYPERCAPITAL LIMITED', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 100000000, 104.053000, 0.000000, 104053000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(355, 'TRD202509189326', 'bond', '566-15.49-T18-A1', 'Bond 566-15.49-T18-A1', '703406', 'FARAMAS COMPANY LIMITED', 'principal', '', '', 'ZAN SECURITIES LIMITED', '', 'buy', 65000000, 106.000000, 0.000000, 68900000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(356, 'TRD202509189237', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '639020', 'HIRAM ALBERT NTABUDYO', 'principal', '', '', 'JESTA JAMES MSWIMA', '654554', 'buy', 50000000, 103.831700, 0.000000, 51915850.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(357, 'TRD202509185732', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '680086', 'SOSTHENES FLORIAN NYENYEMBE', 'principal', '', '', 'FININTELLI COMPANY LIMITED', '656432', 'sell', 10300000, 103.831200, 0.000000, 10694613.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(358, 'TRD202509184659', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '654554', 'JESTA JAMES MSWIMA', 'principal', '', '', 'TUMPALE WILSON MWANKEMWA', '732300', 'sell', 10000000, 103.831700, 0.000000, 10383170.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(359, 'TRD202509185256', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '732297', 'NICHOLAUS ANDREA SUWEDI', 'principal', '', '', 'SOSTHENES FLORIAN NYENYEMBE', '680086', 'buy', 50000000, 103.831200, 0.000000, 51915550.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(360, 'TRD202509185786', 'bond', '568-15.95-T2-A1', 'Bond 568-15.95-T2-A1', '617942', 'ANITA EMMANUEL AMANI', 'principal', '', '', 'ZAKIA HASHIMU MVOGOGO', '654132', 'buy', 40000000, 105.000000, 0.000000, 41999960.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(361, 'TRD202509183064', 'bond', '568-15.95-T2-A1', 'Bond 568-15.95-T2-A1', '654132', 'ZAKIA HASHIMU MVOGOGO', 'principal', '', '', 'ANITA EMMANUEL AMANI', '617942', 'sell', 40000000, 105.000000, 0.000000, 41999960.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(362, 'TRD202509188521', 'bond', '639-12.56-T12-A1', 'Bond 639-12.56-T12-A1', '647925', 'QUIVER CAPITAL LIMITED - TRUST ACCO', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 34000000, 84.000000, 0.000000, 28560000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(363, 'TRD202509187811', 'bond', '573-15.95-T3-A1', 'Bond 573-15.95-T3-A1', '652187', 'LAURENT JOSEPH LYATUU', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'sell', 30000000, 117.000000, 0.000000, 35100000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(364, 'TRD202509183117', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '656432', 'FININTELLI COMPANY LIMITED', 'principal', '', '', 'SOSTHENES FLORIAN NYENYEMBE', '680086', 'buy', 10300000, 103.831200, 0.000000, 10694613.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(365, 'TRD202509181558', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '638414', 'FRANCISCA JOHN NGELESHI', 'principal', '', '', 'JACQUELINE NGELESHI & EMMANUEL MEDA', '649413', 'sell', 25000000, 103.831200, 0.000000, 25957775.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(366, 'TRD202509187233', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '650897', 'FRANCISCA ALPHONCE SAMALI', 'principal', '', '', 'FININTELLI COMPANY LIMITED', '656432', 'sell', 25000000, 103.831800, 0.000000, 25957950.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(367, 'TRD202509185911', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '649413', 'JACQUELINE NGELESHI & EMMANUEL MEDA', 'principal', '', '', 'FRANCISCA JOHN NGELESHI', '638414', 'buy', 25000000, 103.831200, 0.000000, 25957775.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(368, 'TRD202509182820', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '732300', 'TUMPALE WILSON MWANKEMWA', 'principal', '', '', 'JESTA JAMES MSWIMA', '654554', 'buy', 10000000, 103.831700, 0.000000, 10383170.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(369, 'TRD202509181721', 'bond', '500-13.50-T28-A1', 'Bond 500-13.50-T28-A1', '547978', 'ALOYCE ANTHONY NOMBO', 'principal', '', '', 'HILDA IGNAS KAZIMOTO', '642853', 'buy', 4000000, 100.000000, 0.000000, 4000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(370, 'TRD202509183097', 'bond', '500-13.50-T28-A1', 'Bond 500-13.50-T28-A1', '642853', 'HILDA IGNAS KAZIMOTO', 'principal', '', '', 'ALOYCE ANTHONY NOMBO', '547978', 'sell', 4000000, 100.000000, 0.000000, 4000000.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(371, 'TRD202509189197', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '652241', 'JOEL PERFECT KISAWANO', 'principal', '', '', 'BALTAZARY FRIMIN TARIMO', '585999', 'sell', 3000000, 103.831200, 0.000000, 3114933.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(372, 'TRD202509185178', 'bond', '675-15-T16-A1', 'Bond 675-15-T16-A1', '585999', 'BALTAZARY FRIMIN TARIMO', 'principal', '', '', 'JOEL PERFECT KISAWANO', '652241', 'buy', 3000000, 103.831200, 0.000000, 3114933.00, '2025-08-19', '2025-08-20', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:55:51', '2025-09-18 16:55:51', NULL, '', '', NULL, 0.00, 'normal'),
(373, 'TRD202509185782', 'equity', 'DCB', 'GIVEN DIAZ CHAO', '719480', 'GIVEN DIAZ CHAO', 'principal', '', '', 'ITRUST FINANCE LIMITED', '99908', 'buy', 18780, 260.000000, 0.000000, 4882800.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 20:12:55', NULL, '', '', 1.70, 83007.60, 'this_trade'),
(374, 'TRD202509184896', 'equity', 'VODA', 'KELVIN SILYVESTRY KOKA', '595831', 'KELVIN SILYVESTRY KOKA', 'principal', '', '', 'ZAN SECURITIES LIMITED', '', 'buy', 5, 600.000000, 0.000000, 3000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(375, 'TRD202509189616', 'equity', 'TBL', 'WONFAIR HOLDINGS LIMITED', '601864', 'WONFAIR HOLDINGS LIMITED', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 10, 7700.000000, 0.000000, 77000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(376, 'TRD202509186690', 'equity', 'DCB', 'ANNA VINCENT MUNISI', '579007', 'ANNA VINCENT MUNISI', 'principal', '', '', 'ORBIT SECURITIES COMPANY LIMITED', '', 'sell', 10, 235.000000, 0.000000, 2350.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(377, 'TRD202509185088', 'equity', 'CRDB', 'MOTTA PALIMAKA REUBEN KYANDO', '591261', 'MOTTA PALIMAKA REUBEN KYANDO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 2000, 1460.000000, 0.000000, 2920000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(378, 'TRD202509182143', 'equity', 'CRDB', 'SIMON DAVID MALIGANYA', '695746', 'SIMON DAVID MALIGANYA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 1693, 1460.000000, 0.000000, 2471780.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(379, 'TRD202509182552', 'equity', 'CRDB', 'YUNIA MANASE MUNUO', '731721', 'YUNIA MANASE MUNUO', 'principal', '', '', 'VERTEX INTERNATIONAL SECURITIES LIMITED', '', 'buy', 1316, 1460.000000, 0.000000, 1921360.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(380, 'TRD202509186627', 'equity', 'CRDB', 'JANE JUMA MADUHU', '698842', 'JANE JUMA MADUHU', 'principal', '', '', 'VERTEX INTERNATIONAL SECURITIES LIMITED', '', 'buy', 20, 1460.000000, 0.000000, 29200.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(381, 'TRD202509181577', 'equity', 'CRDB', 'ANGELISTA EDWARD KIHAGA', '724325', 'ANGELISTA EDWARD KIHAGA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 1003, 1460.000000, 0.000000, 1464380.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(382, 'TRD202509180883', 'equity', 'MKCB', 'SIMON DAVID MALIGANYA', '695746', 'SIMON DAVID MALIGANYA', 'principal', '', '', 'ORBIT SECURITIES COMPANY LIMITED', '', 'buy', 490, 2600.000000, 0.000000, 1274000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 20:12:18', NULL, '', '', NULL, 0.00, ''),
(383, 'TRD202509184287', 'equity', 'AFRIPRISE', 'ROBERT ADERITUS KATO', '551369', ' ROBERT ADERITUS KATO', 'principal', '', '', 'ITRUST FINANCE LIMITED', '', 'buy', 400, 500.000000, 0.000000, 200000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(384, 'TRD202509188179', 'equity', 'NICO', 'JONATHAN JOHN MBAILUKA', '135528', 'JONATHAN JOHN MBAILUKA', 'principal', '', '', 'OPTIMA CORPORATE FINANCE', '', 'buy', 300, 1640.000000, 0.000000, 492000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(385, 'TRD202509188026', 'equity', 'CRDB', 'PARICIO CLEOPHAS TIBANYENDA', '732107', 'PARICIO CLEOPHAS TIBANYENDA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 250, 1460.000000, 0.000000, 365000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(386, 'TRD202509185228', 'equity', 'AFRIPRISE', 'EVELYNE AMON RUKANDA', '661589', 'EVELYNE AMON RUKANDA', 'principal', '', '', 'ORBIT SECURITIES COMPANY LIMITED', '', 'buy', 210, 550.000000, 0.000000, 115500.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(387, 'TRD202509182294', 'equity', 'AFRIPRISE', 'PROCHES GEORGE MASSAE', '653313', 'PROCHES GEORGE MASSAE', 'principal', '', '', 'ITRUST FINANCE LIMITED', '', 'buy', 96, 500.000000, 0.000000, 48000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(388, 'TRD202509187251', 'equity', 'TBL', 'SIMON DAVID MALIGANYA', '695746', 'SIMON DAVID MALIGANYA', 'principal', '', '', 'E.A CAPITAL LIMITED', '', 'buy', 130, 7810.000000, 0.000000, 1015300.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(389, 'TRD202509188153', 'equity', 'CRDB', 'VERONIKA NICHOLUS KAZINA', '717831', 'VERONIKA NICHOLUS KAZINA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 120, 1460.000000, 0.000000, 175200.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(390, 'TRD202509183264', 'equity', 'VODA', '232352 ITF ADRIEL  RAPHAEL  MREMA', '729435', '232352 ITF ADRIEL  RAPHAEL  MREMA', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 115, 600.000000, 0.000000, 69000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(391, 'TRD202509181621', 'equity', 'DCB', 'MELANIA RODGERS KIVAGAYE', '728918', 'MELANIA RODGERS KIVAGAYE', 'principal', '', '', 'VERTEX INTERNATIONAL SECURITIES LIMITED', '', 'buy', 100, 260.000000, 0.000000, 26000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(392, 'TRD202509181549', 'equity', 'CRDB', 'ONESMO MFUJEGE MWANGOMO', '731193', 'ONESMO MFUJEGE MWANGOMO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 100, 1460.000000, 0.000000, 146000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(393, 'TRD202509188734', 'equity', 'CRDB', 'MKAMI LULU KIHWELO', '727760', 'MKAMI LULU KIHWELO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 100, 1460.000000, 0.000000, 146000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(394, 'TRD202509183722', 'equity', 'CRDB', 'FALES JOHN MSUKA', '724848', 'FALES JOHN MSUKA', 'principal', '', '', 'VERTEX INTERNATIONAL SECURITIES LIMITED', '', 'buy', 100, 1460.000000, 0.000000, 146000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(395, 'TRD202509186954', 'equity', 'VODA', 'BILLYTONY FRENK KIMAMBO', '653026', 'BILLYTONY FRENK KIMAMBO', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 93, 600.000000, 0.000000, 55800.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(396, 'TRD202509180880', 'equity', 'VODA', 'CHARLES EPHRAIM MAJINGE', '725739', 'CHARLES EPHRAIM MAJINGE', 'principal', '', '', 'ORBIT SECURITIES COMPANY LIMITED', '', 'buy', 87, 610.000000, 0.000000, 53070.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(397, 'TRD202509184275', 'equity', 'AFRIPRISE', 'AIWINIA STANLEY TEMBA', '639238', 'AIWINIA STANLEY TEMBA', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'sell', 19, 520.000000, 0.000000, 9880.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(398, 'TRD202509188918', 'equity', 'CRDB', 'TRYPHONE KAZIMBAYA CHRISANTUS', '700105', 'TRYPHONE KAZIMBAYA CHRISANTUS', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 57, 1460.000000, 0.000000, 83220.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(399, 'TRD202509187212', 'equity', 'DCB', 'MARTINE ANTHONY MARTINE', '726160', 'MARTINE ANTHONY MARTINE', 'principal', '', '', 'ITRUST FINANCE LIMITED', '', 'buy', 9, 260.000000, 0.000000, 2340.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(400, 'TRD202509181704', 'equity', 'DCB', 'KOKERA PHINIAS PETER', '723393', 'KOKERA PHINIAS PETER', 'principal', '', '', 'ITRUST FINANCE LIMITED', '', 'buy', 10, 260.000000, 0.000000, 2600.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(401, 'TRD202509181234', 'equity', 'CRDB', 'MSAFIRI SHAFII MSAFIRI', '692597', 'MSAFIRI SHAFII MSAFIRI', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 20, 1460.000000, 0.000000, 29200.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(402, 'TRD202509188177', 'equity', 'TPCC', 'JOSEPH MAGWEIGA MARWA', '590899', 'JOSEPH MAGWEIGA MARWA', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'sell', 20, 5360.000000, 0.000000, 107200.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(403, 'TRD202509189425', 'equity', 'CRDB', 'GEORGE GOODLUCKY MLACKY', '701320', 'GEORGE GOODLUCKY MLACKY', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 20, 1460.000000, 0.000000, 29200.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(404, 'TRD202509185447', 'equity', 'PAL', 'HAFIDHI ATHUMANI SHABANI', '644938', 'HAFIDHI ATHUMANI SHABANI', 'principal', '', '', 'ORBIT SECURITIES COMPANY LIMITED', '', 'buy', 19, 260.000000, 0.000000, 4940.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(405, 'TRD202509186157', 'equity', 'DCB', 'AMIN AHMAD LEMBARITI', '591460', 'AMIN AHMAD LEMBARITI', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'buy', 18, 260.000000, 0.000000, 4680.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(406, 'TRD202509184118', 'equity', 'CRDB', 'MOURINE MICHAEL RUTTA', '710332', 'MOURINE MICHAEL RUTTA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 8, 1460.000000, 0.000000, 11680.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(407, 'TRD202509189862', 'equity', 'DCB', 'ALMAS ADAM SIZYA', '658222', 'ALMAS ADAM SIZYA', 'principal', '', '', 'ITRUST FINANCE LIMITED', '', 'buy', 10, 260.000000, 0.000000, 2600.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(408, 'TRD202509183042', 'equity', 'VODA', 'KOKERA PHINIAS PETER', '723393', 'KOKERA PHINIAS PETER', 'principal', '', '', 'ZAN SECURITIES LIMITED', '', 'buy', 10, 605.000000, 0.000000, 6050.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(409, 'TRD202509181941', 'equity', 'NMB', 'BRYTON FOCUS SUNGUYA', '651257', 'BRYTON FOCUS SUNGUYA', 'principal', '', '', 'RASILIMALI LIMITED', '', 'buy', 10, 8170.000000, 0.000000, 81700.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(410, 'TRD202509181727', 'equity', 'TPCC', 'ROSE JOHN VUMU', '691411', 'ROSE JOHN VUMU', 'principal', '', '', 'CORE SECURITIES LIMITED', '', 'buy', 10, 5360.000000, 0.000000, 53600.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(411, 'TRD202509184033', 'equity', 'AFRIPRISE', 'AMIN AHMAD LEMBARITI', '591460', 'AMIN AHMAD LEMBARITI', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 480.000000, 0.000000, 4800.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(412, 'TRD202509180855', 'equity', 'NICO', 'JOSHUA SAMWEL MONGI', '647863', 'JOSHUA SAMWEL MONGI', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'sell', 10, 1700.000000, 0.000000, 17000.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(413, 'TRD202509187265', 'equity', 'CRDB', 'MARTINE ANTHONY MARTINE', '726160', 'MARTINE ANTHONY MARTINE', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 1460.000000, 0.000000, 14600.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(414, 'TRD202509188244', 'equity', 'CRDB', 'AMIN AHMAD LEMBARITI', '591460', 'AMIN AHMAD LEMBARITI', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 1460.000000, 0.000000, 14600.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(415, 'TRD202509189905', 'equity', 'CRDB', 'JAMSI JOHN MINAZI', '728018', 'JAMSI JOHN MINAZI', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 8, 1460.000000, 0.000000, 11680.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(416, 'TRD202509181433', 'equity', 'CRDB', 'SARAH JOEL MWANTIMWA', '719667', 'SARAH JOEL MWANTIMWA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 7, 1460.000000, 0.000000, 10220.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(417, 'TRD202509184426', 'equity', 'DSE', 'MICHAEL ADIMU MAKUKURA', '725481', 'MICHAEL ADIMU MAKUKURA', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'buy', 5, 4850.000000, 0.000000, 24250.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(418, 'TRD202509183114', 'equity', 'MBP', 'ISAYA SIMON BURUSH', '665847', 'ISAYA SIMON BURUSH', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 6, 950.000000, 0.000000, 5700.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(419, 'TRD202509188841', 'equity', 'CRDB', 'PRISCA LAZARO NNKO', '717445', 'PRISCA LAZARO NNKO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 6, 1460.000000, 0.000000, 8760.00, '2025-08-19', '2025-08-22', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-18 16:57:23', '2025-09-18 16:57:23', NULL, '', '', NULL, 0.00, 'normal'),
(420, 'TRD202509204615', 'bond', '653-12.56-T14-A1', 'FIXED RATE TREASURY BOND 653 - 12.56% Coupon', '707082', 'CRAN AND CONSOLATHA', 'principal', '', '', 'CELINE CRAN MUNGURE', '638912', 'buy', 200000000, 100.000000, 0.000000, 200000000.00, '2025-07-08', '2025-07-09', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 15:12:18', '2025-09-20 15:12:50', NULL, '', '', NULL, 0.00, 'normal'),
(421, 'TRD202509206611', 'bond', '653-12.56-T14-A1', 'FIXED RATE TREASURY BOND 653 - 12.56% Coupon', '638912', 'CELINE CRAN MUNGURE', 'principal', '', '', 'CRAN AND CONSOLATHA', '707082', 'sell', 200000000, 100.000000, 0.000000, 20000000000.00, '2025-07-08', '2025-07-09', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 15:12:18', '2025-09-20 15:56:44', NULL, '', '', 0.10, 20000000.00, 'this_trade'),
(422, 'TRD202509208240', 'bond', '498-15.49-T4-A1', 'FIXED RATE TREASURY BOND 653 - 12.56% Coupon', '656432', 'FININTELLI COMPANY LIMITED', 'principal', '', '', 'VICTORY FINANCIAL SERVICES LTD', '574738', 'buy', 50000000, 112.000000, 0.000000, 56000000.00, '2025-07-08', '2025-07-09', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 15:12:18', '2025-09-20 15:12:50', NULL, '', '', NULL, 0.00, 'normal'),
(423, 'TRD202509200711', 'bond', '498-15.49-T4-A1', 'FIXED RATE TREASURY BOND 653 - 12.56% Coupon', '574738', 'VICTORY FINANCIAL SERVICES LTD', 'principal', '', '', 'FININTELLI COMPANY LIMITED', '656432', 'sell', 50000000, 112.000000, 0.000000, 56000000.00, '2025-07-08', '2025-07-09', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 15:12:18', '2025-09-20 15:12:50', NULL, '', '', NULL, 0.00, 'normal'),
(424, 'TRD202509206796', 'equity', 'CRDB', 'DANIEL DESTA NUNEMO', '721238', 'DANIEL DESTA NUNEMO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 19500, 1270.000000, 0.000000, 24765000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-10-12 15:59:52', NULL, '', '', NULL, 0.00, ''),
(425, 'TRD202509202827', 'equity', 'TBL', 'YUSTINA HENRICK MASANYONI ITF NATHA', '627990', 'YUSTINA HENRICK MASANYONI ITF NATHA', 'principal', '', '', 'E.A CAPITAL LIMITED', '', 'buy', 2000, 7800.000000, 0.000000, 15600000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-10-09 17:37:28', NULL, '', '', 1.00, 156000.00, 'this_trade'),
(426, 'TRD202509202896', 'equity', 'TBL', 'YUSTINA HENRICK MASANYONI ITF ETHAN', '619571', 'YUSTINA HENRICK MASANYONI ITF ETHAN', 'principal', '', '', 'KADOO SECURITIES COMPANY LIMITED', '', 'buy', 10, 7790.000000, 0.000000, 77900.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(427, 'TRD202509201014', 'equity', 'AFRIPRISE', 'EVELYNE AMON RUKANDA', '661589', 'EVELYNE AMON RUKANDA', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'buy', 920, 540.000000, 0.000000, 496800.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(428, 'TRD202509207561', 'equity', 'AFRIPRISE', 'BAHATI HAMISI MASAKA', '700242', 'BAHATI HAMISI MASAKA', 'principal', '', '', 'ZAN SECURITIES LIMITED', '', 'sell', 10, 515.000000, 0.000000, 5150.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(429, 'TRD202509204281', 'equity', 'CRDB', 'TRYPHONE KAZIMBAYA CHRISANTUS', '700105', 'TRYPHONE KAZIMBAYA CHRISANTUS', 'principal', '', '', 'VERTEX INTERNATIONAL SECURITIES LIMITED', '', 'buy', 185, 1270.000000, 0.000000, 234950.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-10-09 17:47:35', NULL, '', '', NULL, 0.00, 'normal'),
(430, 'TRD202509203822', 'equity', 'CRDB', 'GEORGE MSAFIRI PETER', '669321', 'GEORGE MSAFIRI PETER', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 500, 1270.000000, 0.000000, 635000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(431, 'TRD202509205336', 'equity', 'CRDB', 'FREEMAN BEDA MACHA', '716188', 'FREEMAN BEDA MACHA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 400, 1270.000000, 0.000000, 508000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(432, 'TRD202509202726', 'equity', 'CRDB', 'CALVIN JOSEPH TEMBA', '733807', 'CALVIN JOSEPH TEMBA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 300, 1270.000000, 0.000000, 381000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(433, 'TRD202509205711', 'equity', 'CRDB', '671391 ITF BARAKA CHANDARUBA', '699049', '671391 ITF BARAKA CHANDARUBA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 300, 1270.000000, 0.000000, 381000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(434, 'TRD202509204153', 'equity', 'TPCC', 'EDMUND FIDELIS RUTATINA', '255720', 'EDMUND FIDELIS RUTATINA', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'sell', 5, 5200.000000, 0.000000, 26000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(435, 'TRD202509205746', 'equity', 'CRDB', '671391 ITF GLORIOUS CHANDARUBA', '699050', '671391 ITF GLORIOUS CHANDARUBA ', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 200, 1270.000000, 0.000000, 254000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(436, 'TRD202509202533', 'equity', 'CRDB', 'CRIPSON KAZINJA CHRISTIAN', '727553', 'CRIPSON KAZINJA CHRISTIAN', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 200, 1270.000000, 0.000000, 254000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(437, 'TRD202509202103', 'equity', 'CRDB', '671391 ITF BRAIGHTON CHANDARUBA', '699051', '671391 ITF BRAIGHTON CHANDARUBA ', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 200, 1270.000000, 0.000000, 254000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(438, 'TRD202509208009', 'equity', 'CRDB', 'SAMSON PHILIPO LUDOBO', '720462', 'SAMSON PHILIPO LUDOBO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 100, 1270.000000, 0.000000, 127000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(439, 'TRD202509202686', 'equity', 'CRDB', '671391 ITF GLORY  CHANDARUBA', '717641', '671391 ITF GLORY  CHANDARUBA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 100, 1270.000000, 0.000000, 127000.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(440, 'TRD202509204226', 'equity', 'CRDB', 'VERONIKA NICHOLUS KAZINA', '717831', 'VERONIKA NICHOLUS KAZINA', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 65, 1270.000000, 0.000000, 82550.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(441, 'TRD202509205335', 'equity', 'CRDB', 'YOHANA DEUS SALIBOKO', '730287', 'YOHANA DEUS SALIBOKO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 30, 1270.000000, 0.000000, 38100.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(442, 'TRD202509202858', 'equity', 'CRDB', 'BRIGHTON ROSS KINEMO', '487764', 'BRIGHTON ROSS KINEMO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 25, 1270.000000, 0.000000, 31750.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(443, 'TRD202509203131', 'equity', 'VODA', 'RAMADHANI WARYOBA ALLY', '707770', 'RAMADHANI WARYOBA ALLY', 'principal', '', '', 'OPTIMA CORPORATE FINANCE', '', 'sell', 20, 575.000000, 0.000000, 11500.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(444, 'TRD202509201449', 'equity', 'DCB', 'MARTINE ANTHONY MARTINE', '726160', 'MARTINE ANTHONY MARTINE', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'buy', 10, 285.000000, 0.000000, 2850.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(445, 'TRD202509203535', 'equity', 'CRDB', 'MEHJABEEN NAUSHAD MOHAMED', '696126', 'MEHJABEEN NAUSHAD MOHAMED', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 20, 1270.000000, 0.000000, 25400.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(446, 'TRD202509207355', 'equity', 'CRDB', 'ONESMO MFUJEGE MWANGOMO', '731193', 'ONESMO MFUJEGE MWANGOMO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 1270.000000, 0.000000, 12700.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(447, 'TRD202509206824', 'equity', 'VODA', 'AMIN AHMAD LEMBARITI', '591460', 'AMIN AHMAD LEMBARITI', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 11, 600.000000, 0.000000, 6600.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(448, 'TRD202509209160', 'equity', 'CRDB', 'LEONCE BWENDA RWEYEMAMU', '688140', 'LEONCE BWENDA RWEYEMAMU', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 1270.000000, 0.000000, 12700.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(449, 'TRD202509206952', 'equity', 'DSE', 'MEHJABEEN NAUSHAD MOHAMED', '696126', 'MEHJABEEN NAUSHAD MOHAMED', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 10, 4950.000000, 0.000000, 49500.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(450, 'TRD202509209120', 'equity', 'DCB', 'GIFT VICENT MASSAWE', '691091', 'GIFT VICENT MASSAWE', 'principal', '', '', 'SOLOMON STOCKBROKERS LIMITED', '', 'buy', 10, 285.000000, 0.000000, 2850.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(451, 'TRD202509201956', 'equity', 'CRDB', 'NYOROBI JUMA KADO', '617875', 'NYOROBI JUMA KADO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 1270.000000, 0.000000, 12700.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(452, 'TRD202509205422', 'equity', 'AFRIPRISE', 'VERONIKA NICHOLUS KAZINA', '717831', 'VERONIKA NICHOLUS KAZINA', 'principal', '', '', 'TANZANIA SECURITIES LIMITED', '', 'buy', 10, 540.000000, 0.000000, 5400.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(453, 'TRD202509201389', 'equity', 'CRDB', 'TEOPISTER FILBERT MBWILO', '638052', 'TEOPISTER FILBERT MBWILO', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 1270.000000, 0.000000, 12700.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(454, 'TRD202509207191', 'equity', 'CRDB', 'AMIN AHMAD LEMBARITI', '591460', 'AMIN AHMAD LEMBARITI', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 1270.000000, 0.000000, 12700.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(455, 'TRD202509204658', 'equity', 'CRDB', 'GIFT VICENT MASSAWE', '691091', 'GIFT VICENT MASSAWE', 'principal', '', '', 'GLOBAL ALPHA CAPITAL LTD', '', 'buy', 10, 1270.000000, 0.000000, 12700.00, '2025-08-22', '2025-08-27', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-09-20 16:10:49', '2025-09-20 16:10:49', NULL, '', '', NULL, 0.00, 'normal'),
(456, 'TRD202510127104', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717069', 'ANGELA CLAUDE KADASO', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(457, 'TRD202510123005', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717067', 'SOPHIA MAHMOUD MBILIKIRA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(458, 'TRD202510123604', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717033', 'MWANAISHA SALEHE WAZIRI', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(459, 'TRD202510127493', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717056', 'TUMPE DAIMON MWAITENDA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(460, 'TRD202510122708', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717722', 'VIOLETH MICHAEL BURUBA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(461, 'TRD202510125367', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717724', 'KUNDI MASANJA KIJA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(462, 'TRD202510127459', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717051', 'KARIM RAMADHANI LUKARI', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(463, 'TRD202510125986', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717062', 'FLORIAN MARO MBASA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(464, 'TRD202510126269', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717057', 'FREDRICK KAIZA LWAMBUGWE', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(465, 'TRD202510124921', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717048', 'SALUM SEIF SALUM', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(466, 'TRD202510121508', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717677', 'DEOGRATIUS MICHAEL URIO', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal');
INSERT INTO `trades` (`id`, `trade_reference`, `asset_class`, `security_id`, `security_name`, `client_cds_account`, `client_name`, `capacity`, `broker_name`, `counterparty_broker`, `counterparty_name`, `counterparty_cds_account`, `trade_side`, `quantity`, `price`, `rate`, `consideration`, `trade_date`, `settlement_date`, `maturity_date`, `exchange_reference`, `origin`, `time_executed`, `currency`, `status`, `uploaded_by`, `created_at`, `updated_at`, `total_value`, `client_title`, `client_identity_type`, `custom_brokerage_fee`, `final_brokerage_fee`, `brokerage_fee_type`) VALUES
(467, 'TRD202510126576', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717682', 'MONICA CHRISTOPHER NDOTO ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(468, 'TRD202510126693', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717725', 'JOHN BALTHAZARY MUDENDE', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(469, 'TRD202510129206', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717730', 'UPENDO EXAUD MMARI', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(470, 'TRD202510126996', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717045', 'KHAIRAT OMARY ALI', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(471, 'TRD202510129205', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717049', 'CATHERINE MENDRAD NDUNGURU', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(472, 'TRD202510123132', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717699', 'RAMADHANI ISSA SOSSORA ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(473, 'TRD202510123427', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717715', 'HERMAN KACHIMA MOSES', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(474, 'TRD202510129511', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717708', 'ADAM GEORGE MANDIA ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(475, 'TRD202510127072', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717690', 'SENAS FREDRICK KAVISHE', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(476, 'TRD202510123468', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717061', 'ESTHER ELIAH MWANGONO ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(477, 'TRD202510121484', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717068', 'KENETH SIMON LYATUU', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(478, 'TRD202510129004', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717066', 'MARGARETH SARAH ARTHUR MWAKAPUGI ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(479, 'TRD202510121593', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717678', 'ROBERT LODUVO NGILORITI', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(480, 'TRD202510121300', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717689', 'MARYEMANUELLA MARIJANI MSOFFE', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(481, 'TRD202510128584', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717047', 'THADEO FUKUDA RWEYAMBA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(482, 'TRD202510120064', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717044', 'DEBORAH SULEIMAN KERENGE', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(483, 'TRD202510124761', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717684', 'AISA RAYMOND KIMARO ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(484, 'TRD202510123759', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717691', 'EMMANUEL PASCHAL KALUMUNA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(485, 'TRD202510123565', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717679', 'CLEMENT HERRY ONING\'O', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(486, 'TRD202510129042', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717717', 'MICHAEL RESPICE MASAKWIYA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(487, 'TRD202510127716', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717054', 'ROSELYNE  JASON TINEISHEMO ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(488, 'TRD202510120299', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717704', 'PHILBERT FRANCIS BAGENDA ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(489, 'TRD202510129099', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717726', 'PAULINA HERIEL MSANGA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(490, 'TRD202510123327', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717709', 'BAKARI MDIMU MSANGI', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(491, 'TRD202510124135', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717050', 'SAUMU ABDALLAH KILEO', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(492, 'TRD202510125626', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717064', 'BETTY RAPHAEL MLEWA', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(493, 'TRD202510122235', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717059', 'TUSEKILE GODFREY MWAIPUNGU', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(494, 'TRD202510123232', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717728', 'HELLEN HERMAN MTENYA ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(495, 'TRD202510121569', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717703', 'EDDYNUR HILAL SUDI', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(496, 'TRD202510124695', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717686', 'PRUNELLA KISSA NSEKELA ', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(497, 'TRD202510120310', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 686 - 13.75% Coupon', '717680', 'GODBLESS SAMWEL SWAI', 'principal', '', '', 'AZANIA BANK LIMITED', '', 'buy', 250300000, 104.057400, 0.000000, 260455672.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(498, 'TRD202510123095', 'bond', '643-12.56-T13-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '574738', 'VICTORY FINANCIAL SERVICES LTD', 'principal', '', '', 'TOGOLANI ELIAMINI MRAMBA', '626970', 'buy', 50000000, 82.000000, 0.000000, 41000000.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(499, 'TRD202510127290', 'bond', '643-12.56-T13-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '626970', 'TOGOLANI ELIAMINI MRAMBA', 'principal', '', '', 'VICTORY FINANCIAL SERVICES LTD', '574738', 'sell', 50000000, 82.000000, 0.000000, 41000000.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(500, 'TRD202510125090', 'bond', '540-15.49-T13-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '751581', 'MOLLEN CHARLES', 'principal', '', '', 'MOLLEN CHARLES', '746583', 'buy', 48300000, 100.000000, 0.000000, 48300000.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(501, 'TRD202510123825', 'bond', '540-15.49-T13-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '746583', 'MOLLEN CHARLES', 'principal', '', '', 'MOLLEN CHARLES', '751581', 'sell', 48300000, 100.000000, 0.000000, 48300000.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(502, 'TRD202510129994', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '683864', 'HAIKA AUGUST KOMBE', 'principal', '', '', 'CHRISTOPHER PETER MATOLLAH', '745498', 'buy', 16600000, 100.000000, 0.000000, 16600000.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(503, 'TRD202510125580', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '744230', 'GERVAS PIUS MHENGA', 'principal', '', '', 'HAIKA AUGUST KOMBE', '683864', 'sell', 33100000, 100.000000, 0.000000, 33100000.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(504, 'TRD202510123266', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '745498', 'CHRISTOPHER PETER MATOLLAH', 'principal', '', '', 'HAIKA AUGUST KOMBE', '683864', 'sell', 16600000, 100.000000, 0.000000, 16600000.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(505, 'TRD202510127543', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '656432', 'FININTELLI COMPANY LIMITED', 'principal', '', '', 'MOLLEN CHARLES', '746583', 'buy', 9400000, 105.000000, 0.000000, 9870000.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(506, 'TRD202510129747', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '746583', 'MOLLEN CHARLES', 'principal', '', '', 'SERAPHINE THOMAS BAKIRANE', '642530', 'sell', 500000, 105.000000, 0.000000, 524999.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(507, 'TRD202510121606', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '683016', 'LINDA VICTOR MSAKI', 'principal', '', '', 'VERONICA BENSON CHONJO', '744631', 'buy', 8300000, 100.000000, 0.000000, 8299991.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(508, 'TRD202510122377', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '744631', 'VERONICA BENSON CHONJO', 'principal', '', '', 'LINDA VICTOR MSAKI', '683016', 'sell', 8300000, 100.000000, 0.000000, 8299991.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(509, 'TRD202510124068', 'bond', '686-13.75-T17-A1', 'FIXED RATE TREASURY BOND 643 - 12.56% Coupon', '642530', 'SERAPHINE THOMAS BAKIRANE', 'principal', '', '', 'MOLLEN CHARLES', '746583', 'buy', 500000, 105.000000, 0.000000, 524999.00, '2025-10-07', '2025-10-08', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:19:08', '2025-10-12 15:19:21', NULL, '', '', NULL, 0.00, 'normal'),
(510, 'TRD202510123658', 'bond', '527-13.50-T33-A1', 'FIXED RATE TREASURY BOND 527 - 13.50% Coupon', '614782', 'LIQUID FUND UNIT TRUST SCHEME', 'principal', '', '', 'VICTORY FINANCIAL SERVICES LTD', '574738', 'buy', 21796000000, 105.266800, 0.000000, 22943929932.00, '2025-10-10', '2025-10-13', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', '', NULL, 0.00, 'normal'),
(511, 'TRD202510124643', 'bond', '527-13.50-T33-A1', 'FIXED RATE TREASURY BOND 527 - 13.50% Coupon', '574738', 'VICTORY FINANCIAL SERVICES LTD', 'principal', '', '', 'LIQUID FUND UNIT TRUST SCHEME', '614782', 'sell', 21796000000, 105.266800, 0.000000, 22943929932.00, '2025-10-10', '2025-10-13', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', '', NULL, 0.00, 'normal'),
(512, 'TRD202510126039', 'bond', '536-13.50-T36-A1', 'FIXED RATE TREASURY BOND 536 - 13.50% Coupon', '588739', 'NSSF MAIN ACCOUNT', 'principal', '', '', 'LIQUID FUND UNIT TRUST SCHEME', '614782', 'sell', 15100000000, 107.394800, 0.000000, 16216614800.00, '2025-10-10', '2025-10-13', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', '', NULL, 0.00, 'normal'),
(513, 'TRD202510125379', 'bond', '536-13.50-T36-A1', 'FIXED RATE TREASURY BOND 536 - 13.50% Coupon', '614782', 'LIQUID FUND UNIT TRUST SCHEME', 'principal', '', '', 'NSSF MAIN ACCOUNT', '588739', 'buy', 15100000000, 107.394800, 0.000000, 1621661480000.00, '2025-10-10', '2025-10-13', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:21:44', '2025-10-12 15:36:30', NULL, '', '', NULL, 99999999.99, 'normal'),
(514, 'TRD202510126534', 'bond', '533-15.49-T11-A1', 'FIXED RATE TREASURY BOND 533 - 15.49% Coupon', '608973', 'MAURICE SIEGFRIED KUWITE', 'principal', '', '', 'MILLICENT JOHN LEONARD', '648140', 'buy', 7000000, 100.000000, 0.000000, 6999993.00, '2025-10-10', '2025-10-13', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', '', NULL, 0.00, 'normal'),
(515, 'TRD202510126264', 'bond', '533-15.49-T11-A1', 'FIXED RATE TREASURY BOND 533 - 15.49% Coupon', '648140', 'MILLICENT JOHN LEONARD', 'principal', '', '', 'MAURICE SIEGFRIED KUWITE', '608973', 'sell', 7000000, 100.000000, 0.000000, 6999993.00, '2025-10-10', '2025-10-13', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', '', NULL, 0.00, 'normal'),
(516, 'TRD202510128594', 'bond', '666-15.75-T15-A1', 'FIXED RATE TREASURY BOND 533 - 15.49% Coupon', '710776', 'GLORIA ANDREW KOKWIJJUKA', 'principal', '', '', 'BRIGHTON ROSS KINEMO', '660527', 'sell', 2300000, 102.000000, 0.000000, 2345997.00, '2025-10-10', '2025-10-13', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', '', NULL, 0.00, 'normal'),
(517, 'TRD202510126527', 'bond', '666-15.75-T15-A1', 'FIXED RATE TREASURY BOND 533 - 15.49% Coupon', '660527', 'BRIGHTON ROSS KINEMO', 'principal', '', '', 'GLORIA ANDREW KOKWIJJUKA', '710776', 'buy', 2300000, 102.000000, 0.000000, 2345997.00, '2025-10-10', '2025-10-13', NULL, NULL, NULL, NULL, 'TZS', 'active', 2, '2025-10-12 15:21:44', '2025-10-12 15:21:44', NULL, '', '', NULL, 0.00, 'normal');

-- --------------------------------------------------------

--
-- Table structure for table `trade_invoices`
--

CREATE TABLE `trade_invoices` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `trade_id` int(11) NOT NULL,
  `client_cds_account` varchar(50) NOT NULL,
  `client_name` varchar(200) NOT NULL,
  `security_name` varchar(200) NOT NULL,
  `quantity` bigint(20) NOT NULL,
  `unit_price` decimal(15,6) NOT NULL,
  `gross_amount` decimal(20,2) NOT NULL,
  `fees` decimal(10,2) DEFAULT 0.00,
  `taxes` decimal(10,2) DEFAULT 0.00,
  `net_amount` decimal(20,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'USD',
  `invoice_date` date NOT NULL,
  `auto_generated` tinyint(1) DEFAULT 1,
  `generated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_receipts`
--

CREATE TABLE `trade_receipts` (
  `id` int(11) NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `trade_id` int(11) NOT NULL,
  `client_cds_account` varchar(50) NOT NULL,
  `client_name` varchar(200) NOT NULL,
  `security_name` varchar(200) NOT NULL,
  `quantity` bigint(20) NOT NULL,
  `unit_price` decimal(15,6) NOT NULL,
  `gross_amount` decimal(20,2) NOT NULL,
  `fees` decimal(10,2) DEFAULT 0.00,
  `taxes` decimal(10,2) DEFAULT 0.00,
  `net_amount` decimal(20,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'USD',
  `receipt_date` date NOT NULL,
  `auto_generated` tinyint(1) DEFAULT 1,
  `generated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `trade_type` varchar(300) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transaction_types`
--

CREATE TABLE `transaction_types` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `is_document_numbering_auto` tinyint(1) DEFAULT 0,
  `is_post_dated` tinyint(1) DEFAULT 0,
  `priority` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `utilization_count` varchar(300) NOT NULL,
  `utilization_percent` varchar(300) NOT NULL,
  `status` enum('active','inactive','','') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_types`
--

INSERT INTO `transaction_types` (`id`, `code`, `description`, `is_document_numbering_auto`, `is_post_dated`, `priority`, `is_active`, `created_at`, `updated_at`, `utilization_count`, `utilization_percent`, `status`) VALUES
(1, 'CIN', 'SUPPLIERS INVOICES', 0, 0, 'P0001', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '3', '1.64', 'active'),
(2, 'CDN', 'SUPPLIERS DEBIT NOTES', 0, 0, 'P0002', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '3', '1.64', 'active'),
(3, 'DIN', 'CUSTOMERS INVOICES', 0, 0, 'P0003', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '1', '0.55', 'active'),
(4, 'DCN', 'CUSTOMERS CREDIT NOTES', 0, 0, 'P0004', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '1', '0.55', 'active'),
(5, 'QUT', 'QUOTATIONS', 0, 0, 'P0005', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '2', '1.09', 'active'),
(6, 'POL', 'POLICIES', 0, 0, 'P0006', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(7, 'DBN', 'DEBIT NOTES', 0, 0, 'P0007', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '13', '7.10', 'active'),
(8, 'CRN', 'CREDIT NOTES', 0, 0, 'P0008', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(9, 'JVC', 'JOURNAL VOUCHERS', 0, 0, 'P0009', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '19', '10.38', 'active'),
(10, 'RCT', 'RECEIPTS', 0, 0, 'P0010', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '92', '50.27', 'active'),
(11, 'PYT', 'PAYMENTS', 0, 0, 'P0011', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '24', '13.11', 'active'),
(12, 'PET', 'PETTY CASH', 0, 0, 'P0012', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(13, 'DIP', 'DIRECT PAYMENTS', 0, 0, 'P0013', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '18', '9.84', 'active'),
(14, 'IPO', 'INITIAL PUBLIC OFFER', 0, 0, 'P0014', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(15, 'BUY', 'BUY', 0, 0, 'P0015', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '7', '3.83', 'active'),
(16, 'SEL', 'SELL', 0, 0, 'P0016', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(17, 'SPL', 'SPLITS', 0, 0, 'P0017', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(18, 'DIV', 'DIVIDENDS', 0, 0, 'P0018', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(19, 'INT', 'INTEREST', 0, 0, 'P0019', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(20, 'RGT', 'RIGHTS ISSUE', 0, 0, 'P0020', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(21, 'BON', 'BONUS', 0, 0, 'P0023', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(22, 'FAS', 'FIXED ASSET', 0, 0, 'P0026', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(23, 'PJV', 'PAYROLL JOURNALS', 0, 0, 'P0027', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active'),
(24, 'NON', 'NONE', 0, 0, 'P0028', 1, '2025-09-05 16:05:41', '2025-09-05 16:05:41', '0', '0.00', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('system_admin','trader','ceo','finance_officer') NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `mandate_enabled` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `full_name`, `is_active`, `mandate_enabled`, `created_at`, `updated_at`) VALUES
(2, 'admin', 'admin@stockexchange.com', '$2y$10$PSBGsFQ3TCxpgMLmMOrEF.0oOXBcR89R2XNWD/la.QiA49Hyc0xJG', 'system_admin', 'System Administrator', 1, 1, '2025-08-21 15:06:13', '2025-10-12 14:01:24'),
(3, 'domina@gmail.com', 'domina@gmail.com', '$2y$10$qQ9q.vBNOp./oFgJqjVA1.iguPa5dX/B6PUjptKx3zr6ycaHcQ1uy', 'trader', 'domina John', 1, 1, '2025-08-21 15:24:13', '2025-09-13 17:29:15'),
(4, 'admin2', 'admin1@gmail.com', '$2y$10$Rv517x6rTYU.V3ldNoKhRuXTGOWUYTh5cEdo.LU1z5x9IUJKPrNuO', 'finance_officer', 'admin 1', 1, 1, '2025-08-23 22:55:47', '2025-09-11 08:44:58'),
(5, 'ernest ceo', 'ernest@123.com', '$2y$10$sIrK8Gh1MQf/HunjyGcgn.0qPpO4tkfR27hNZStyubUl2f1ExavyK', 'ceo', 'ernest mswima', 1, 1, '2025-08-24 12:33:42', '2025-08-29 19:20:44'),
(6, 'ernest', 'ernestmswima@gmail.com', '$2y$10$ytyF54eCRbee3vpfG7iihON27VdhVye2S0ynBJt.JVGjUw0siwK6i', 'finance_officer', 'ernest mswima', 1, 1, '2025-09-09 15:03:55', '2025-09-09 15:04:57');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `balance_sheet_reporting_formats`
--
ALTER TABLE `balance_sheet_reporting_formats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_balance_sheet_reporting_formats_code` (`code`);

--
-- Indexes for table `bonds`
--
ALTER TABLE `bonds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `security_id` (`security_id`),
  ADD KEY `idx_security_id` (`security_id`);

--
-- Indexes for table `bonds_economic_sectors`
--
ALTER TABLE `bonds_economic_sectors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_bonds_economic_sectors_code` (`code`);

--
-- Indexes for table `bond_auctions`
--
ALTER TABLE `bond_auctions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `bond_issuers`
--
ALTER TABLE `bond_issuers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_bond_issuers_code` (`code`);

--
-- Indexes for table `bond_types`
--
ALTER TABLE `bond_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_bond_types_code` (`code`);

--
-- Indexes for table `brokers`
--
ALTER TABLE `brokers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `broker_code` (`broker_code`),
  ADD KEY `idx_broker_code` (`broker_code`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cds_account` (`cds_account`),
  ADD KEY `custodian_id` (`custodian_id`),
  ADD KEY `idx_cds_account` (`cds_account`),
  ADD KEY `merged_into` (`merged_into`),
  ADD KEY `cds_account_2` (`cds_account`);

--
-- Indexes for table `client_merge_log`
--
ALTER TABLE `client_merge_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `merged_by` (`merged_by`),
  ADD KEY `idx_primary_client` (`primary_client_id`),
  ADD KEY `idx_merged_client` (`merged_client_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_code` (`company_code`);

--
-- Indexes for table `coupon_determiners`
--
ALTER TABLE `coupon_determiners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_coupon_determiners_code` (`code`);

--
-- Indexes for table `custodians`
--
ALTER TABLE `custodians`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custodian_code` (`custodian_code`),
  ADD KEY `idx_custodian_code` (`custodian_code`);

--
-- Indexes for table `equities`
--
ALTER TABLE `equities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `security_id` (`security_id`),
  ADD KEY `idx_security_id` (`security_id`);

--
-- Indexes for table `equities_settings`
--
ALTER TABLE `equities_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`security_id`),
  ADD KEY `idx_equities_settings_code` (`security_id`),
  ADD KEY `idx_equities_settings_isin` (`isin`);

--
-- Indexes for table `expenses_benefits`
--
ALTER TABLE `expenses_benefits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_date` (`transaction_date`);

--
-- Indexes for table `fee_configurations`
--
ALTER TABLE `fee_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fee_asset` (`fee_type`,`applies_to`),
  ADD KEY `idx_fee_type` (`fee_type`),
  ADD KEY `idx_applies_to` (`applies_to`);

--
-- Indexes for table `fee_configuration_audit`
--
ALTER TABLE `fee_configuration_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fee_configuration_id` (`fee_configuration_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `gl_account_formats`
--
ALTER TABLE `gl_account_formats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_gl_account_formats_code` (`code`);

--
-- Indexes for table `gl_account_types`
--
ALTER TABLE `gl_account_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_gl_account_types_code` (`code`);

--
-- Indexes for table `identity_types`
--
ALTER TABLE `identity_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `investments_costing_basis`
--
ALTER TABLE `investments_costing_basis`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `investment_asset_classes`
--
ALTER TABLE `investment_asset_classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `ledger_types`
--
ALTER TABLE `ledger_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_frequencies`
--
ALTER TABLE `payment_frequencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_payment_frequencies_code` (`code`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `share_market_segments`
--
ALTER TABLE `share_market_segments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `share_market_trends`
--
ALTER TABLE `share_market_trends`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_share_market_trends_code` (`code`);

--
-- Indexes for table `share_types`
--
ALTER TABLE `share_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_share_types_code` (`code`);

--
-- Indexes for table `sub_ledger_categories`
--
ALTER TABLE `sub_ledger_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`),
  ADD KEY `idx_classification` (`classification`);

--
-- Indexes for table `sub_ledger_groups`
--
ALTER TABLE `sub_ledger_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`),
  ADD KEY `idx_priority` (`priority`);

--
-- Indexes for table `sub_ledger_related_parties`
--
ALTER TABLE `sub_ledger_related_parties`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `sub_ledger_status`
--
ALTER TABLE `sub_ledger_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `titles`
--
ALTER TABLE `titles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `trades`
--
ALTER TABLE `trades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trade_reference` (`trade_reference`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_trade_date` (`trade_date`),
  ADD KEY `idx_client_cds` (`client_cds_account`),
  ADD KEY `idx_security` (`security_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `client_cds_account` (`client_cds_account`),
  ADD KEY `asset_class` (`asset_class`);

--
-- Indexes for table `trade_invoices`
--
ALTER TABLE `trade_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `trade_id` (`trade_id`),
  ADD KEY `generated_by` (`generated_by`);

--
-- Indexes for table `trade_receipts`
--
ALTER TABLE `trade_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `trade_id` (`trade_id`),
  ADD KEY `generated_by` (`generated_by`);

--
-- Indexes for table `transaction_types`
--
ALTER TABLE `transaction_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_active` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `balance_sheet_reporting_formats`
--
ALTER TABLE `balance_sheet_reporting_formats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `bonds`
--
ALTER TABLE `bonds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `bonds_economic_sectors`
--
ALTER TABLE `bonds_economic_sectors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `bond_auctions`
--
ALTER TABLE `bond_auctions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `bond_issuers`
--
ALTER TABLE `bond_issuers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `bond_types`
--
ALTER TABLE `bond_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `brokers`
--
ALTER TABLE `brokers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=215;

--
-- AUTO_INCREMENT for table `client_merge_log`
--
ALTER TABLE `client_merge_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `coupon_determiners`
--
ALTER TABLE `coupon_determiners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `custodians`
--
ALTER TABLE `custodians`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `equities`
--
ALTER TABLE `equities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `equities_settings`
--
ALTER TABLE `equities_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `expenses_benefits`
--
ALTER TABLE `expenses_benefits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `fee_configurations`
--
ALTER TABLE `fee_configurations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `fee_configuration_audit`
--
ALTER TABLE `fee_configuration_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gl_account_formats`
--
ALTER TABLE `gl_account_formats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `gl_account_types`
--
ALTER TABLE `gl_account_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `identity_types`
--
ALTER TABLE `identity_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `investments_costing_basis`
--
ALTER TABLE `investments_costing_basis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `investment_asset_classes`
--
ALTER TABLE `investment_asset_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `ledger_types`
--
ALTER TABLE `ledger_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `payment_frequencies`
--
ALTER TABLE `payment_frequencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `share_market_segments`
--
ALTER TABLE `share_market_segments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `share_market_trends`
--
ALTER TABLE `share_market_trends`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `share_types`
--
ALTER TABLE `share_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sub_ledger_categories`
--
ALTER TABLE `sub_ledger_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `sub_ledger_groups`
--
ALTER TABLE `sub_ledger_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sub_ledger_related_parties`
--
ALTER TABLE `sub_ledger_related_parties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sub_ledger_status`
--
ALTER TABLE `sub_ledger_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `titles`
--
ALTER TABLE `titles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `trades`
--
ALTER TABLE `trades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=540;

--
-- AUTO_INCREMENT for table `trade_invoices`
--
ALTER TABLE `trade_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_receipts`
--
ALTER TABLE `trade_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transaction_types`
--
ALTER TABLE `transaction_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bond_auctions`
--
ALTER TABLE `bond_auctions`
  ADD CONSTRAINT `bond_auctions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `clients_ibfk_1` FOREIGN KEY (`custodian_id`) REFERENCES `custodians` (`id`),
  ADD CONSTRAINT `clients_ibfk_2` FOREIGN KEY (`merged_into`) REFERENCES `clients` (`id`);

--
-- Constraints for table `client_merge_log`
--
ALTER TABLE `client_merge_log`
  ADD CONSTRAINT `client_merge_log_ibfk_1` FOREIGN KEY (`primary_client_id`) REFERENCES `clients` (`id`),
  ADD CONSTRAINT `client_merge_log_ibfk_2` FOREIGN KEY (`merged_client_id`) REFERENCES `clients` (`id`),
  ADD CONSTRAINT `client_merge_log_ibfk_3` FOREIGN KEY (`merged_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `expenses_benefits`
--
ALTER TABLE `expenses_benefits`
  ADD CONSTRAINT `expenses_benefits_ibfk_1` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `expenses_benefits_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `fee_configuration_audit`
--
ALTER TABLE `fee_configuration_audit`
  ADD CONSTRAINT `fee_configuration_audit_ibfk_1` FOREIGN KEY (`fee_configuration_id`) REFERENCES `fee_configurations` (`id`),
  ADD CONSTRAINT `fee_configuration_audit_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `trades`
--
ALTER TABLE `trades`
  ADD CONSTRAINT `trades_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `trade_invoices`
--
ALTER TABLE `trade_invoices`
  ADD CONSTRAINT `trade_invoices_ibfk_1` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`),
  ADD CONSTRAINT `trade_invoices_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `trade_receipts`
--
ALTER TABLE `trade_receipts`
  ADD CONSTRAINT `trade_receipts_ibfk_1` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`),
  ADD CONSTRAINT `trade_receipts_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
