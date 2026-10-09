<?php
// includes/validation.php - Input Validation Functions

class Validator {

    /**
     * Validate email
     */
    public static function email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate phone number (Nigerian format)
     */
    public static function phone($phone) {
        return preg_match('/^(0|\+234)[789][01]\d{8}$/', $phone);
    }

    /**
     * Validate Nigerian phone and format to international
     */
    public static function formatPhone($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Check if it's a Nigerian number
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '0') {
            return '+234' . substr($phone, 1);
        } elseif (strlen($phone) === 13 && substr($phone, 0, 3) === '234') {
            return '+' . $phone;
        } elseif (strlen($phone) === 14 && substr($phone, 0, 4) === '0234') {
            return '+' . substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Validate password strength
     */
    public static function password($password) {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate date
     */
    public static function date($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Validate age
     */
    public static function age($dob, $minAge = null, $maxAge = null) {
        if (!self::date($dob)) {
            return false;
        }

        $dob = new DateTime($dob);
        $now = new DateTime();
        $age = $now->diff($dob)->y;

        if ($minAge !== null && $age < $minAge) {
            return false;
        }

        if ($maxAge !== null && $age > $maxAge) {
            return false;
        }

        return true;
    }

    /**
     * Validate URL
     */
    public static function url($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate IP address
     */
    public static function ip($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate integer within range
     */
    public static function int($value, $min = null, $max = null) {
        if (!filter_var($value, FILTER_VALIDATE_INT)) {
            return false;
        }

        if ($min !== null && $value < $min) {
            return false;
        }

        if ($max !== null && $value > $max) {
            return false;
        }

        return true;
    }

    /**
     * Validate float within range
     */
    public static function float($value, $min = null, $max = null) {
        if (!filter_var($value, FILTER_VALIDATE_FLOAT)) {
            return false;
        }

        if ($min !== null && $value < $min) {
            return false;
        }

        if ($max !== null && $value > $max) {
            return false;
        }

        return true;
    }

    /**
     * Validate string length
     */
    public static function string($value, $minLength = null, $maxLength = null) {
        $length = strlen($value);

        if ($minLength !== null && $length < $minLength) {
            return false;
        }

        if ($maxLength !== null && $length > $maxLength) {
            return false;
        }

        return true;
    }

    /**
     * Validate alphanumeric
     */
    public static function alphanumeric($value) {
        return ctype_alnum($value);
    }

    /**
     * Validate admission number format (STB/YYYY/0000)
     */
    public static function admissionNumber($admission) {
        return preg_match('/^STB\/\d{4}\/\d{4}$/', $admission);
    }

    /**
     * Validate employee ID format (TCH/YYYY/000)
     */
    public static function employeeId($empId) {
        return preg_match('/^TCH\/\d{4}\/\d{3}$/', $empId);
    }

    /**
     * Validate academic year format (YYYY-YYYY)
     */
    public static function academicYear($year) {
        if (!preg_match('/^\d{4}-\d{4}$/', $year)) {
            return false;
        }

        $years = explode('-', $year);
        return ($years[1] == $years[0] + 1);
    }

    /**
     * Validate file upload
     */
    public static function file($file, $allowedTypes = [], $maxSize = 5242880) {
        $errors = [];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            switch ($file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = 'File is too large';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = 'File was only partially uploaded';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errors[] = 'No file was uploaded';
                    break;
                default:
                    $errors[] = 'Upload failed';
            }

            return ['valid' => false, 'errors' => $errors];
        }

        if ($file['size'] > $maxSize) {
            $errors[] = 'File size must be less than ' . ($maxSize / 1048576) . 'MB';
        }

        if (!empty($allowedTypes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($mime, $allowedTypes) && !in_array($extension, $allowedTypes)) {
                $errors[] = 'File type not allowed';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'name' => $file['name'],
            'size' => $file['size'],
            'type' => $file['type']
        ];
    }

    /**
     * Validate image
     */
    public static function image($file, $maxSize = 5242880) {
        $allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ];

        return self::file($file, $allowedTypes, $maxSize);
    }

    /**
     * Validate PDF
     */
    public static function pdf($file, $maxSize = 10485760) {
        return self::file($file, ['application/pdf'], $maxSize);
    }

    /**
     * Validate against XSS
     */
    public static function noXSS($input) {
        $cleaned = strip_tags($input);
        return $cleaned === $input;
    }

    /**
     * Sanitize input
     */
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }

        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate form data against rules
     */
    public static function validate($data, $rules) {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            $rules = explode('|', $rule);

            foreach ($rules as $singleRule) {
                $params = [];

                if (strpos($singleRule, ':') !== false) {
                    list($singleRule, $paramString) = explode(':', $singleRule);
                    $params = explode(',', $paramString);
                }

                switch ($singleRule) {
                    case 'required':
                        if (empty($value)) {
                            $errors[$field][] = ucfirst($field) . ' is required';
                        }
                        break;

                    case 'email':
                        if (!empty($value) && !self::email($value)) {
                            $errors[$field][] = 'Invalid email address';
                        }
                        break;

                    case 'phone':
                        if (!empty($value) && !self::phone($value)) {
                            $errors[$field][] = 'Invalid phone number';
                        }
                        break;

                    case 'min':
                        $min = $params[0];
                        if (strlen($value) < $min) {
                            $errors[$field][] = ucfirst($field) . ' must be at least ' . $min . ' characters';
                        }
                        break;

                    case 'max':
                        $max = $params[0];
                        if (strlen($value) > $max) {
                            $errors[$field][] = ucfirst($field) . ' must not exceed ' . $max . ' characters';
                        }
                        break;

                    case 'numeric':
                        if (!is_numeric($value)) {
                            $errors[$field][] = ucfirst($field) . ' must be a number';
                        }
                        break;

                    case 'integer':
                        if (!filter_var($value, FILTER_VALIDATE_INT)) {
                            $errors[$field][] = ucfirst($field) . ' must be an integer';
                        }
                        break;

                    case 'date':
                        if (!self::date($value)) {
                            $errors[$field][] = 'Invalid date format';
                        }
                        break;

                    case 'admission':
                        if (!self::admissionNumber($value)) {
                            $errors[$field][] = 'Invalid admission number format (STB/YYYY/0000)';
                        }
                        break;

                    case 'academic_year':
                        if (!self::academicYear($value)) {
                            $errors[$field][] = 'Invalid academic year format (YYYY-YYYY)';
                        }
                        break;

                    case 'confirmed':
                        if ($value !== ($data[$field . '_confirmation'] ?? null)) {
                            $errors[$field][] = ucfirst($field) . ' confirmation does not match';
                        }
                        break;

                    case 'unique':
                        list($table, $column, $ignoreId) = array_pad($params, 3, null);
                        $db = Database::getInstance();

                        $query = "SELECT COUNT(*) as count FROM $table WHERE $column = ?";
                        $queryParams = [$value];

                        if ($ignoreId) {
                            $query .= " AND id != ?";
                            $queryParams[] = $ignoreId;
                        }

                        $result = $db->getRow($query, $queryParams);

                        if ($result['count'] > 0) {
                            $errors[$field][] = ucfirst($field) . ' already exists';
                        }
                        break;
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}