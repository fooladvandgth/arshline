<?php

namespace Arshline\Database;

class CostTracking
{
    /**
     * Create cost tracking table for monitoring AI API usage
     */
    public static function create_table()
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'arshline_cost_tracking';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL DEFAULT 0,
            session_id varchar(50) DEFAULT NULL,
            task_type varchar(50) NOT NULL,
            task_description text DEFAULT NULL,
            model varchar(100) NOT NULL,
            input_tokens int(11) NOT NULL DEFAULT 0,
            output_tokens int(11) NOT NULL DEFAULT 0,
            total_tokens int(11) NOT NULL DEFAULT 0,
            input_cost decimal(10,6) NOT NULL DEFAULT 0.000000,
            output_cost decimal(10,6) NOT NULL DEFAULT 0.000000,
            total_cost decimal(10,6) NOT NULL DEFAULT 0.000000,
            execution_time float DEFAULT NULL,
            request_data longtext DEFAULT NULL,
            response_data longtext DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY task_type (task_type),
            KEY model (model),
            KEY created_at (created_at),
            KEY session_id (session_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create summary table for daily/monthly aggregates
        $summary_table = $wpdb->prefix . 'arshline_cost_summary';
        
        $summary_sql = "CREATE TABLE $summary_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL DEFAULT 0,
            date_period date NOT NULL,
            period_type enum('day','month','year') NOT NULL DEFAULT 'day',
            task_type varchar(50) NOT NULL DEFAULT 'all',
            model varchar(100) NOT NULL DEFAULT 'all',
            total_requests int(11) NOT NULL DEFAULT 0,
            total_input_tokens bigint(20) NOT NULL DEFAULT 0,
            total_output_tokens bigint(20) NOT NULL DEFAULT 0,
            total_tokens bigint(20) NOT NULL DEFAULT 0,
            total_cost decimal(12,6) NOT NULL DEFAULT 0.000000,
            avg_execution_time float DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_summary (user_id, date_period, period_type, task_type, model),
            KEY date_period (date_period),
            KEY period_type (period_type),
            KEY total_cost (total_cost)
        ) $charset_collate;";
        
        dbDelta($summary_sql);
    }

    /**
     * Drop cost tracking tables
     */
    public static function drop_tables()
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'arshline_cost_tracking';
        $summary_table = $wpdb->prefix . 'arshline_cost_summary';
        
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        $wpdb->query("DROP TABLE IF EXISTS $summary_table");
    }
}