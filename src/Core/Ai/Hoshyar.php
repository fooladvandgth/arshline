<?php
namespace Arshline\Core\Ai;

use WP_REST_Request;

class Hoshyar
{
    public static function capabilities(): array
    {
        return Capabilities::all();
    }

    /**
     * Handle an AI intent/command in a safe, modular way.
     * Expected payloads:
     * - { command: string } (optional, future NLP)
     * - { intent: string, params?: array }
     * - { confirm_action: { action: string, params: array } }
     */
    public static function agent(array $payload): array
    {
        // Confirm flow bypasses parsing
        if (!empty($payload['confirm_action']) && is_array($payload['confirm_action'])){
            return self::execute_confirmed($payload['confirm_action']);
        }

        $intent = (string)($payload['intent'] ?? '');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        // Minimal safe intents (navigation/ui)
        if ($intent === 'help'){
            return [ 'ok' => true, 'action' => 'help', 'capabilities' => self::capabilities() ];
        }
        if ($intent === 'open_tab'){
            $tab = isset($params['tab']) ? (string)$params['tab'] : 'dashboard';
            $allowed = ['dashboard','forms','reports','users','settings'];
            if (!in_array($tab, $allowed, true)) $tab = 'dashboard';
            return [ 'ok' => true, 'action' => 'open_tab', 'tab' => $tab ];
        }
        if ($intent === 'ui' && ($params['target'] ?? '') === 'toggle_theme'){
            return [ 'ok' => true, 'action' => 'ui', 'target' => 'toggle_theme' ];
        }

        // Mutating operations are gated behind confirmation; return confirmation prompt
        if ($intent === 'form_create'){
            $title = trim((string)($params['title'] ?? 'فرم بدون عنوان'));
            return [
                'ok' => true,
                'action' => 'confirm',
                'message' => 'فرم جدید با این عنوان ساخته شود؟',
                'confirm_action' => [ 'action' => 'form_create', 'params' => [ 'title' => $title ] ],
            ];
        }
        if ($intent === 'form_delete'){
            $id = (int)($params['id'] ?? 0);
            if ($id <= 0){ return [ 'ok' => false, 'error' => 'شناسه فرم نامعتبر است' ]; }
            return [
                'ok' => true,
                'action' => 'confirm',
                'message' => 'حذف فرم انتخابی انجام شود؟',
                'confirm_action' => [ 'action' => 'form_delete', 'params' => [ 'id' => $id ] ],
            ];
        }

        return [ 'ok' => false, 'error' => 'intent ناشناخته یا پشتیبانی‌نشده' ];
    }

    protected static function execute_confirmed(array $action): array
    {
        $a = (string)($action['action'] ?? '');
        $p = is_array($action['params'] ?? null) ? $action['params'] : [];
        if ($a === 'form_create'){
            // Frontend will just open forms tab; actual creation can be implemented via existing REST
            return [ 'ok' => true, 'action' => 'open_tab', 'tab' => 'forms' ];
        }
        if ($a === 'form_delete'){
            // Placeholder: leave actual delete to existing DELETE /forms/{id} flow
            return [ 'ok' => true, 'action' => 'open_tab', 'tab' => 'forms' ];
        }
        return [ 'ok' => false, 'error' => 'confirmed_action ناشناخته' ];
    }
}
