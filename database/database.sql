-- ==========================================================
-- Ketan Complex MJHS - Unified Database Script
-- School-Based Assessment & Performance Management System
-- Single consolidated database schema, seed & initial dataset
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `ketan_complex_sba` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ketan_complex_sba`;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 1. Users Table (Administrator, Head Teacher, Teachers)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('admin', 'headteacher', 'teacher') NOT NULL DEFAULT 'teacher',
  `phone` VARCHAR(30) DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `last_login` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. Classes Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `class_name` VARCHAR(50) NOT NULL UNIQUE,
  `class_code` VARCHAR(20) DEFAULT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_classes_status` (`status`),
  INDEX `idx_classes_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. Subjects Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `subject_name` VARCHAR(100) NOT NULL,
  `subject_code` VARCHAR(20) NOT NULL UNIQUE,
  `category` ENUM('core', 'elective') NOT NULL DEFAULT 'core',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_subjects_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. Academic Years Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `academic_years`;
CREATE TABLE `academic_years` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `year_name` VARCHAR(30) NOT NULL UNIQUE,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_academic_years_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 5. Terms Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `terms`;
CREATE TABLE `terms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `academic_year_id` INT NOT NULL,
  `term_name` VARCHAR(30) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `next_term_start_date` DATE DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_terms_active` (`is_active`),
  UNIQUE KEY `unique_term_per_year` (`academic_year_id`, `term_name`),
  FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 6. Teacher Assignments Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `teacher_assignments`;
CREATE TABLE `teacher_assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `is_class_teacher` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_assignment` (`teacher_id`, `class_id`, `subject_id`, `academic_year_id`),
  FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 7. Students Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `gender` ENUM('Male', 'Female') NOT NULL,
  `date_of_birth` DATE NOT NULL,
  `class_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `parent_name` VARCHAR(100) DEFAULT NULL,
  `parent_phone` VARCHAR(30) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `admission_date` DATE DEFAULT NULL,
  `status` ENUM('active', 'inactive', 'transferred', 'graduated') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_students_class` (`class_id`),
  INDEX `idx_students_status` (`status`),
  FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 8. Assessment Submissions Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_submissions`;
CREATE TABLE `assessment_submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `status` ENUM('draft', 'submitted', 'approved', 'reopened') NOT NULL DEFAULT 'draft',
  `submitted_at` DATETIME DEFAULT NULL,
  `reviewed_by` INT DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `review_comments` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_class_subject_term` (`class_id`, `subject_id`, `academic_year_id`, `term_id`),
  FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 9. Marks Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `marks`;
CREATE TABLE `marks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `submission_id` INT DEFAULT NULL,
  `student_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `sba_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `exam_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `total_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `grade` VARCHAR(5) DEFAULT NULL,
  `remark` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('draft', 'submitted', 'approved', 'reopened') NOT NULL DEFAULT 'draft',
  `entered_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_student_mark` (`student_id`, `subject_id`, `academic_year_id`, `term_id`),
  INDEX `idx_marks_class_term` (`class_id`, `academic_year_id`, `term_id`),
  FOREIGN KEY (`submission_id`) REFERENCES `assessment_submissions` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 10. Student Term Reports Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `student_term_reports`;
CREATE TABLE `student_term_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `attendance_present` INT NOT NULL DEFAULT 0,
  `attendance_total` INT NOT NULL DEFAULT 60,
  `conduct` VARCHAR(100) NOT NULL DEFAULT 'Satisfactory',
  `attitude` VARCHAR(100) NOT NULL DEFAULT 'Hardworking',
  `interest` VARCHAR(100) NOT NULL DEFAULT 'Reading, Sports and Art',
  `class_teacher_remark` TEXT DEFAULT NULL,
  `head_teacher_remark` TEXT DEFAULT NULL,
  `class_position` INT DEFAULT NULL,
  `total_students` INT DEFAULT NULL,
  `total_marks` DECIMAL(7,2) DEFAULT NULL,
  `average_mark` DECIMAL(5,2) DEFAULT NULL,
  `overall_grade` VARCHAR(5) DEFAULT NULL,
  `promotion_status` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_student_term_report` (`student_id`, `academic_year_id`, `term_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 11. Grading Scale Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `grading_scale`;
CREATE TABLE `grading_scale` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `grade` VARCHAR(5) NOT NULL UNIQUE,
  `min_score` DECIMAL(5,2) NOT NULL,
  `max_score` DECIMAL(5,2) NOT NULL,
  `remark` VARCHAR(50) NOT NULL,
  `display_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 12. System Settings Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_key` VARCHAR(50) PRIMARY KEY,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 13. Audit Logs Table
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `username` VARCHAR(50) DEFAULT NULL,
  `role` VARCHAR(30) DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT DEFAULT NULL,
  `description` TEXT NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================
-- DEFAULT INITIAL DATA & CONFIGURATION
-- ==========================================================

-- 1. Classes Seed
INSERT INTO `classes` (`id`, `class_name`, `class_code`, `display_order`, `status`) VALUES
(1, 'KG 1', 'KG1', 1, 'active'),
(2, 'KG 2', 'KG2', 2, 'active'),
(3, 'Basic 1', 'B1', 3, 'active'),
(4, 'Basic 2', 'B2', 4, 'active'),
(5, 'Basic 3', 'B3', 5, 'active'),
(6, 'Basic 4', 'B4', 6, 'active'),
(7, 'Basic 5', 'B5', 7, 'active'),
(8, 'Basic 6', 'B6', 8, 'active'),
(9, 'JHS 1', 'JHS1', 9, 'active'),
(10, 'JHS 2', 'JHS2', 10, 'active'),
(11, 'JHS 3', 'JHS3', 11, 'active')
ON DUPLICATE KEY UPDATE `class_name` = VALUES(`class_name`);

-- 2. Subjects Seed
INSERT INTO `subjects` (`id`, `subject_name`, `subject_code`, `category`, `status`, `display_order`) VALUES
(1, 'English Language', 'ENG', 'core', 'active', 1),
(2, 'Mathematics', 'MTH', 'core', 'active', 2),
(3, 'Integrated Science', 'SCI', 'core', 'active', 3),
(4, 'Social Studies', 'SOC', 'core', 'active', 4),
(5, 'Religious and Moral Education', 'RME', 'core', 'active', 5),
(6, 'Computing', 'COMP', 'core', 'active', 6),
(7, 'Creative Arts & Design', 'CAD', 'core', 'active', 7),
(8, 'Career Technology', 'CTE', 'core', 'active', 8),
(9, 'French', 'FRE', 'elective', 'active', 9),
(10, 'Ghanaian Language (Fante/Twi)', 'GHL', 'elective', 'active', 10),
(11, 'Physical and Health Education', 'PHE', 'elective', 'active', 11)
ON DUPLICATE KEY UPDATE `subject_name` = VALUES(`subject_name`);

-- 3. Academic Years
INSERT INTO `academic_years` (`id`, `year_name`, `is_active`, `start_date`, `end_date`) VALUES
(1, '2025/2026', 1, '2025-09-01', '2026-07-31')
ON DUPLICATE KEY UPDATE `is_active` = VALUES(`is_active`);

-- 4. Terms
INSERT INTO `terms` (`id`, `academic_year_id`, `term_name`, `is_active`, `next_term_start_date`) VALUES
(1, 1, 'Term 1', 1, '2026-01-12'),
(2, 1, 'Term 2', 0, '2026-05-04'),
(3, 1, 'Term 3', 0, '2026-09-08')
ON DUPLICATE KEY UPDATE `is_active` = VALUES(`is_active`);

-- 5. Grading Scale
INSERT INTO `grading_scale` (`id`, `grade`, `min_score`, `max_score`, `remark`, `display_order`) VALUES
(1, 'A', 80.00, 100.00, 'Excellent', 1),
(2, 'B', 70.00, 79.99, 'Very Good', 2),
(3, 'C', 60.00, 69.99, 'Good', 3),
(4, 'D', 50.00, 59.99, 'Credit', 4),
(5, 'E', 40.00, 49.99, 'Pass', 5),
(6, 'F', 0.00, 39.99, 'Fail', 6)
ON DUPLICATE KEY UPDATE `remark` = VALUES(`remark`);

-- 6. System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('school_name', 'Ketan Complex MJHS'),
('school_tagline', 'School-Based Assessment & Performance Management System'),
('school_motto', 'Knowledge, Discipline and Excellence'),
('school_address', 'P.O. Box 23, Ketan, Sekondi-Takoradi, Western Region, Ghana'),
('school_phone', '+233 (0) 31 204 5678 / +233 (0) 24 412 3456'),
('school_email', 'info@ketancomplexmjhs.edu.gh'),
('head_teacher (B.Ed, M.Ed)'),
('max_sba_score', '50'),
('max_exam_score', '50'),
('default_pass_mark', '50'),
('next_term_date', '2026-01-12')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- ==========================================================
-- INITIAL ADMINISTRATOR ACCOUNT
-- Username: COMPLEX
-- Password: VESTER442
-- ==========================================================
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `role`, `phone`, `status`) VALUES
(1, 'COMPLEX', 'admin@ketancomplexmjhs.edu.gh', '$2y$10$UCzZnWDBz89E7sOD0f6MJ.xsQBX1iDGqiMuPvsyXtrHcDm5/3zfEu', 'System Administrator', 'admin', '+233244000111', 'active')
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`), `password_hash` = VALUES(`password_hash`);

