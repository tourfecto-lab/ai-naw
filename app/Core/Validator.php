<?php

/**
 * Tourfecto - Validator Class
 * كلاس متقدم للتحقق من صحة البيانات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class Validator
{
    /**
     * @var array $errors - أخطاء التحقق
     */
    private $errors = [];

    /**
     * @var array $data - البيانات المراد التحقق منها
     */
    private $data = [];

    /**
     * @var array $rules - قواعد التحقق
     */
    private $rules = [];

    /**
     * @var array $customMessages - رسائل مخصصة
     */
    private $customMessages = [];

    /**
     * Constructor
     * @param array $customMessages
     */
    public function __construct(array $customMessages = [])
    {
        $this->customMessages = $customMessages;
    }

    /**
     * التحقق من البيانات
     * @param array $data
     * @param array $rules
     * @return array
     */
    public function validate(array $data, array $rules): array
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->errors = [];

        foreach ($rules as $field => $ruleSet) {
            $this->validateField($field, $ruleSet);
        }

        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors
        ];
    }

    /**
     * التحقق من حقل واحد
     * @param string $field
     * @param string $ruleSet
     */
    private function validateField(string $field, string $ruleSet): void
    {
        $rules = explode('|', $ruleSet);
        $value = $this->data[$field] ?? null;
        $isRequired = in_array('required', $rules);

        // التحقق من الـ required
        if ($isRequired && (is_null($value) || $value === '')) {
            $this->addError($field, 'required');
            return;
        }

        // إذا كان الحقل فارغاً وغير مطلوب، تخطي التحقق
        if ((is_null($value) || $value === '') && !$isRequired) {
            return;
        }

        foreach ($rules as $rule) {
            // تخطي قاعدة required (تم التحقق منها بالفعل)
            if ($rule === 'required') {
                continue;
            }

            // التحقق من القواعد مع معاملات
            if (strpos($rule, ':') !== false) {
                list($ruleName, $parameter) = explode(':', $rule, 2);
                $this->applyRule($field, $value, $ruleName, $parameter);
            } else {
                $this->applyRule($field, $value, $rule, null);
            }
        }
    }

    /**
     * تطبيق قاعدة التحقق
     * @param string $field
     * @param mixed $value
     * @param string $rule
     * @param mixed $parameter
     */
    private function applyRule(string $field, $value, string $rule, $parameter = null): void
    {
        $method = 'validate' . ucfirst($rule);

        if (method_exists($this, $method)) {
            if (!$this->$method($value, $parameter)) {
                $this->addError($field, $rule, $parameter);
            }
        }
    }

    /**
     * إضافة خطأ
     * @param string $field
     * @param string $rule
     * @param mixed $parameter
     */
    private function addError(string $field, string $rule, $parameter = null): void
    {
        $message = $this->getMessage($field, $rule, $parameter);

        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $message;
    }

    /**
     * الحصول على رسالة الخطأ
     * @param string $field
     * @param string $rule
     * @param mixed $parameter
     * @return string
     */
    private function getMessage(string $field, string $rule, $parameter = null): string
    {
        // رسائل مخصصة
        $key = $field . '.' . $rule;
        if (isset($this->customMessages[$key])) {
            return $this->customMessages[$key];
        }

        // رسائل افتراضية
        $messages = [
            'required' => 'حقل :field مطلوب.',
            'email' => 'حقل :field يجب أن يكون بريداً إلكترونياً صحيحاً.',
            'min' => 'حقل :field يجب أن يكون على الأقل :param.',
            'max' => 'حقل :field يجب أن يكون على الأكثر :param.',
            'between' => 'حقل :field يجب أن يكون بين :param1 و :param2.',
            'numeric' => 'حقل :field يجب أن يكون رقماً.',
            'integer' => 'حقل :field يجب أن يكون عدداً صحيحاً.',
            'string' => 'حقل :field يجب أن يكون نصاً.',
            'array' => 'حقل :field يجب أن يكون مصفوفة.',
            'boolean' => 'حقل :field يجب أن يكون منطقياً (true/false).',
            'url' => 'حقل :field يجب أن يكون رابطاً صحيحاً.',
            'ip' => 'حقل :field يجب أن يكون عنوان IP صحيحاً.',
            'date' => 'حقل :field يجب أن يكون تاريخاً صحيحاً.',
            'in' => 'حقل :field يجب أن يكون واحداً من: :param.',
            'not_in' => 'حقل :field لا يجب أن يكون واحداً من: :param.',
            'unique' => 'حقل :field موجود بالفعل في قاعدة البيانات.',
            'confirmed' => 'تأكيد حقل :field غير متطابق.',
            'regex' => 'حقل :field لا يتطابق مع النمط المطلوب.',
            'alpha' => 'حقل :field يجب أن يحتوي على أحرف فقط.',
            'alpha_num' => 'حقل :field يجب أن يحتوي على أحرف وأرقام فقط.',
            'min_length' => 'حقل :field يجب أن يحتوي على على الأقل :param أحرف.',
            'max_length' => 'حقل :field يجب أن يحتوي على على الأكثر :param أحرف.',
            'between_length' => 'حقل :field يجب أن يحتوي على بين :param1 و :param2 أحرف.',
        ];

        $message = $messages[$rule] ?? 'حقل :field غير صحيح.';

        // بعض القواعد (مثل required) بتتبعت من غير بارامتر، وكان تمرير
        // null لـ explode() بيطلع تحذير Deprecated في PHP 8.1+ بيتطبع
        // جوه الـ JSON نفسه (JSON خربان → الفرونت إند بيقول "استجابة
        // غير صالحة"). بنحوّل null لسلسلة فاضية قبل أي استخدام.
        $parameter = $parameter ?? '';
        $paramParts = $parameter !== '' ? explode(',', $parameter) : [];

        // استبدال المتغيرات
        $replacements = [
            ':field' => $field,
            ':param' => $parameter,
            ':param1' => $paramParts[0] ?? '',
            ':param2' => $paramParts[1] ?? ''
        ];

        foreach ($replacements as $key => $value) {
            $message = str_replace($key, (string) $value, $message);
        }

        return $message;
    }

    /**
     * التحقق من required
     * @param mixed $value
     * @return bool
     */
    private function validateRequired($value): bool
    {
        return !(is_null($value) || $value === '' || (is_array($value) && empty($value)));
    }

    /**
     * التحقق من email
     * @param mixed $value
     * @return bool
     */
    private function validateEmail($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * التحقق من min
     * @param mixed $value
     * @param string $parameter
     * @return bool
     */
    private function validateMin($value, string $parameter): bool
    {
        if (is_numeric($value)) {
            return floatval($value) >= floatval($parameter);
        }
        if (is_string($value)) {
            return strlen($value) >= intval($parameter);
        }
        if (is_array($value)) {
            return count($value) >= intval($parameter);
        }
        return false;
    }

    /**
     * التحقق من max
     * @param mixed $value
     * @param string $parameter
     * @return bool
     */
    private function validateMax($value, string $parameter): bool
    {
        if (is_numeric($value)) {
            return floatval($value) <= floatval($parameter);
        }
        if (is_string($value)) {
            return strlen($value) <= intval($parameter);
        }
        if (is_array($value)) {
            return count($value) <= intval($parameter);
        }
        return false;
    }

    /**
     * التحقق من between
     * @param mixed $value
     * @param string $parameter
     * @return bool
     */
    private function validateBetween($value, string $parameter): bool
    {
        $parts = explode(',', $parameter);
        if (count($parts) !== 2) {
            return false;
        }

        $min = floatval($parts[0]);
        $max = floatval($parts[1]);

        if (is_numeric($value)) {
            $value = floatval($value);
            return $value >= $min && $value <= $max;
        }
        if (is_string($value)) {
            $len = strlen($value);
            return $len >= $min && $len <= $max;
        }
        if (is_array($value)) {
            $count = count($value);
            return $count >= $min && $count <= $max;
        }
        return false;
    }

    /**
     * التحقق من numeric
     * @param mixed $value
     * @return bool
     */
    private function validateNumeric($value): bool
    {
        return is_numeric($value);
    }

    /**
     * التحقق من integer
     * @param mixed $value
     * @return bool
     */
    private function validateInteger($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * التحقق من string
     * @param mixed $value
     * @return bool
     */
    private function validateString($value): bool
    {
        return is_string($value);
    }

    /**
     * التحقق من array
     * @param mixed $value
     * @return bool
     */
    private function validateArray($value): bool
    {
        return is_array($value);
    }

    /**
     * التحقق من boolean
     * @param mixed $value
     * @return bool
     */
    private function validateBoolean($value): bool
    {
        return is_bool($value) || in_array($value, ['true', 'false', '1', '0', 1, 0], true);
    }

    /**
     * التحقق من url
     * @param mixed $value
     * @return bool
     */
    private function validateUrl($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * التحقق من ip
     * @param mixed $value
     * @return bool
     */
    private function validateIp($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * التحقق من date
     * @param mixed $value
     * @return bool
     */
    private function validateDate($value): bool
    {
        return strtotime($value) !== false;
    }

    /**
     * التحقق من in
     * @param mixed $value
     * @param string $parameter
     * @return bool
     */
    private function validateIn($value, string $parameter): bool
    {
        $allowed = explode(',', $parameter);
        return in_array($value, $allowed);
    }

    /**
     * التحقق من not_in
     * @param mixed $value
     * @param string $parameter
     * @return bool
     */
    private function validateNotIn($value, string $parameter): bool
    {
        $notAllowed = explode(',', $parameter);
        return !in_array($value, $notAllowed);
    }

    /**
     * التحقق من alpha
     * @param mixed $value
     * @return bool
     */
    private function validateAlpha($value): bool
    {
        return ctype_alpha($value);
    }

    /**
     * التحقق من alpha_num
     * @param mixed $value
     * @return bool
     */
    private function validateAlphaNum($value): bool
    {
        return ctype_alnum($value);
    }

    /**
     * التحقق من min_length
     * @param mixed $value
     * @param string $parameter
     * @return bool
     */
    private function validateMin_length($value, string $parameter): bool
    {
        return strlen($value) >= intval($parameter);
    }

    /**
     * التحقق من max_length
     * @param mixed $value
     * @param string $parameter
     * @return bool
     */
    private function validateMax_length($value, string $parameter): bool
    {
        return strlen($value) <= intval($parameter);
    }

    /**
     * التحقق من between_length
     * @param mixed $value
     * @param string $parameter
     * @return bool
     */
    private function validateBetween_length($value, string $parameter): bool
    {
        $parts = explode(',', $parameter);
        if (count($parts) !== 2) {
            return false;
        }

        $min = intval($parts[0]);
        $max = intval($parts[1]);
        $len = strlen($value);

        return $len >= $min && $len <= $max;
    }
}
