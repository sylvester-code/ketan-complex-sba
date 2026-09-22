-- ==========================================================
-- KETAN M/A B COMPLEX - Migration v2 (MariaDB compatible)
-- Adds: signature column to users, class_teacher columns to classes,
--       class_teachers junction table, updates school_name setting.
-- Run this ONCE on existing installations to upgrade the schema.
-- ==========================================================

-- 1. Add signature column to users (if not already present)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `signature` VARCHAR(255) DEFAULT NULL AFTER `phone`;

-- 2. Add class teacher columns to classes (if not already present)
ALTER TABLE `classes`
    ADD COLUMN IF NOT EXISTS `class_teacher_id` INT DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `class_teacher_assigned_at` DATETIME DEFAULT NULL AFTER `class_teacher_id`;

-- 3. Create class_teachers junction table (if not already present)
CREATE TABLE IF NOT EXISTS `class_teachers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `class_id` INT NOT NULL,
  `teacher_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `assigned_at` DATETIME NOT NULL,
  UNIQUE KEY `unique_class_year` (`class_id`, `academic_year_id`),
  FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Update school_name setting to new branding
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
VALUES ('school_name', 'KETAN M/A B COMPLEX')
ON DUPLICATE KEY UPDATE `setting_value` = 'KETAN M/A B COMPLEX';

SELECT 'Migration v2 applied successfully.' AS status;
