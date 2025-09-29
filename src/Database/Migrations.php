<?php
namespace Arshline\Database;

class Migrations
{
    public static function up(): array
    {
        return [
            'forms' => "CREATE TABLE IF NOT EXISTS {prefix}x_forms (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                schema_version VARCHAR(20) NOT NULL,
                owner_id BIGINT UNSIGNED,
                status VARCHAR(20) DEFAULT 'draft',
                public_token VARCHAR(24) NULL,
                meta JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY public_token_unique (public_token)
            ) ENGINE=InnoDB;",
            // User Groups: تعریف گروه‌های کاربری
            'user_groups' => "CREATE TABLE IF NOT EXISTS {prefix}x_user_groups (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                meta JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY group_name_unique (name),
                KEY parent_idx (parent_id),
                CONSTRAINT fk_user_groups_parent FOREIGN KEY (parent_id) REFERENCES {prefix}x_user_groups(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;",
            // User Group Fields: فیلدهای سفارشی هر گروه (متغیرها)
            'user_group_fields' => "CREATE TABLE IF NOT EXISTS {prefix}x_user_group_fields (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                group_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(64) NOT NULL,
                label VARCHAR(190) NOT NULL,
                type VARCHAR(32) NOT NULL DEFAULT 'text',
                options JSON NULL,
                required TINYINT(1) DEFAULT 0,
                sort INT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (group_id) REFERENCES {prefix}x_user_groups(id) ON DELETE CASCADE,
                UNIQUE KEY group_field_unique (group_id, name),
                KEY group_idx (group_id)
            ) ENGINE=InnoDB;",
            // Group Members: اعضای هر گروه + توکن شخصی‌سازی شده
            'user_group_members' => "CREATE TABLE IF NOT EXISTS {prefix}x_user_group_members (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                group_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(190) NOT NULL,
                phone VARCHAR(32) NOT NULL,
                data JSON NULL,
                token VARCHAR(64) NULL,
                token_hash CHAR(64) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (group_id) REFERENCES {prefix}x_user_groups(id) ON DELETE CASCADE,
                KEY group_idx (group_id),
                KEY phone_idx (phone),
                UNIQUE KEY token_hash_unique (token_hash)
            ) ENGINE=InnoDB;",
            // Form-Group Access: اتصال فرم‌ها به گروه‌ها
            'form_group_access' => "CREATE TABLE IF NOT EXISTS {prefix}x_form_group_access (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_id BIGINT UNSIGNED NOT NULL,
                group_id BIGINT UNSIGNED NOT NULL,
                FOREIGN KEY (form_id) REFERENCES {prefix}x_forms(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES {prefix}x_user_groups(id) ON DELETE CASCADE,
                UNIQUE KEY form_group_unique (form_id, group_id)
            ) ENGINE=InnoDB;",
            'fields' => "CREATE TABLE IF NOT EXISTS {prefix}x_fields (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_id BIGINT UNSIGNED NOT NULL,
                sort INT UNSIGNED DEFAULT 0,
                props JSON NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (form_id) REFERENCES {prefix}x_forms(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;",
            'submissions' => "CREATE TABLE IF NOT EXISTS {prefix}x_submissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NULL,
                ip VARCHAR(45) NULL,
                status VARCHAR(20) DEFAULT 'pending',
                meta JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (form_id) REFERENCES {prefix}x_forms(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;",
            'submission_values' => "CREATE TABLE IF NOT EXISTS {prefix}x_submission_values (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                submission_id BIGINT UNSIGNED NOT NULL,
                field_id BIGINT UNSIGNED NOT NULL,
                value TEXT,
                idx INT UNSIGNED DEFAULT 0,
                FOREIGN KEY (submission_id) REFERENCES {prefix}x_submissions(id) ON DELETE CASCADE,
                FOREIGN KEY (field_id) REFERENCES {prefix}x_fields(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;",
            'audit_log' => "CREATE TABLE IF NOT EXISTS {prefix}x_audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                ip VARCHAR(45) NULL,
                action VARCHAR(64) NOT NULL,
                scope VARCHAR(32) NOT NULL,
                target_id BIGINT UNSIGNED NULL,
                before_state JSON NULL,
                after_state JSON NULL,
                undo_token VARCHAR(64) NOT NULL,
                undone TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                undone_at DATETIME NULL,
                KEY scope_action (scope, action),
                KEY target (target_id),
                UNIQUE KEY undo_token_unique (undo_token)
            ) ENGINE=InnoDB;"
        ];
    }
}
