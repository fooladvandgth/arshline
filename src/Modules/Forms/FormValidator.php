<?php
namespace Arshline\Modules\Forms;

class FormValidator
{
    public static function validate(Form $form): array
    {
        $errors = [];
        if (empty($form->fields) || !is_array($form->fields)) {
            $errors[] = 'فرم باید حداقل یک فیلد داشته باشد.';
        }
        foreach ($form->fields as $field) {
            if (empty($field['type']) || empty($field['label'])) {
                $errors[] = 'هر فیلد باید نوع و برچسب داشته باشد.';
            }
        }
        return $errors;
    }
}
