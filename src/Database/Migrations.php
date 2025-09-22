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
                meta JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
            ) ENGINE=InnoDB;"
        ];
    }
}
