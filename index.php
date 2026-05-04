<?php

declare(strict_types=1);

$autoloadFile = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadFile)) {
    require $autoloadFile;
} else {
    $phpMailerSrcDir = __DIR__ . '/vendor/phpmailer/phpmailer/src';
    $requiredMailerFiles = [
        $phpMailerSrcDir . '/Exception.php',
        $phpMailerSrcDir . '/PHPMailer.php',
        $phpMailerSrcDir . '/SMTP.php',
    ];

    $missingMailerFiles = array_filter(
        $requiredMailerFiles,
        static fn (string $file): bool => !file_exists($file)
    );

    if ($missingMailerFiles === []) {
        require $phpMailerSrcDir . '/Exception.php';
        require $phpMailerSrcDir . '/PHPMailer.php';
        require $phpMailerSrcDir . '/SMTP.php';
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'PHPMailer is not installed. Expected Composer autoload at "' . $autoloadFile . '" or PHPMailer source files in "' . $phpMailerSrcDir . '".',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

$config = loadConfig();

header('Content-Type: application/json; charset=utf-8');
configureCors($config);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, [
        'success' => true,
        'service' => 'email-mailer',
        'message' => 'API is running.',
        'transport' => $config['transport'] ?? 'unknown',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'error' => 'Method not allowed.',
    ]);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '{}', true);
if (!is_array($payload)) {
    respond(400, [
        'success' => false,
        'error' => 'Request body must be valid JSON.',
    ]);
}

$action = strtolower(trim((string) ($payload['action'] ?? 'send-email')));
if ($action === 'health') {
    enforceSharedApiKey($config, $payload);

    respond(200, [
        'success' => true,
        'message' => 'Mailer API is healthy.',
    ]);
}

if ($action === 'complete-signup') {
    handleCompleteSignup($config, $payload);
}

if ($action === 'confirm-signup') {
    handleConfirmSignup($config, $payload);
}

if ($action === 'forgot-password') {
    handleForgotPassword($config, $payload);
}

if ($action === 'complete-password-reset') {
    handleCompletePasswordReset($config, $payload);
}

if ($action !== 'send-email') {
    respond(400, [
        'success' => false,
        'error' => 'Unsupported action. Use "send-email", "complete-signup", "confirm-signup", "forgot-password", "complete-password-reset", or "health".',
    ]);
}

enforceSharedApiKey($config, $payload);

$to = normalizeEmail($payload['to'] ?? '');
$subject = trim((string) ($payload['subject'] ?? ''));
$html = trim((string) ($payload['html'] ?? ''));
$text = trim((string) ($payload['text'] ?? ''));
$fromEmail = normalizeEmail($payload['from_email'] ?? ($config['default_from_email'] ?? ''));
$fromName = trim((string) ($payload['from_name'] ?? ($config['default_from_name'] ?? 'Serendipity')));
$replyTo = normalizeEmail($payload['reply_to'] ?? ($config['default_reply_to'] ?? ''));

if ($to === '') {
    respond(422, [
        'success' => false,
        'error' => 'Recipient email is required.',
    ]);
}

if ($subject === '') {
    respond(422, [
        'success' => false,
        'error' => 'Subject is required.',
    ]);
}

if ($html === '' && $text === '') {
    respond(422, [
        'success' => false,
        'error' => 'Either html or text content is required.',
    ]);
}

if ($fromEmail === '') {
    respond(500, [
        'success' => false,
        'error' => 'A valid default_from_email must be configured.',
    ]);
}

if ($html === '') {
    $html = nl2br(escapeHtml($text));
}

if ($text === '') {
    $text = htmlToText($html);
}

$transport = strtolower(trim((string) ($config['transport'] ?? 'smtp')));

try {
    switch ($transport) {
        case 'resend':
            sendWithResend($config, [
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'reply_to' => $replyTo,
            ]);
            break;

        case 'brevo':
            sendWithBrevo($config, [
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'reply_to' => $replyTo,
            ]);
            break;

        case 'smtp':
            sendWithSmtp($config, [
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'reply_to' => $replyTo,
            ]);
            break;

        default:
            respond(500, [
                'success' => false,
                'error' => 'Unsupported transport configured. Use "smtp", "resend", or "brevo".',
            ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Email sent successfully.',
    ]);
} catch (Throwable $exception) {
    respond(500, [
        'success' => false,
        'error' => $exception->getMessage(),
    ]);
}

function normalizeBcryptHash(string $hash): string
{
    if (strpos($hash, '$2y$') === 0) {
        return '$2a$' . substr($hash, 4);
    }

    return $hash;
}

function sendWithResend(array $config, array $message): void
{
    $apiKey = trim((string) ($config['resend_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Missing resend_api_key in application configuration.');
    }

    $body = [
        'from' => formatSender($message['from_name'], $message['from_email']),
        'to' => [$message['to']],
        'subject' => $message['subject'],
        'html' => $message['html'],
        'text' => $message['text'],
    ];

    if ($message['reply_to'] !== '') {
        $body['reply_to'] = [$message['reply_to']];
    }

    requestJson(
        'https://api.resend.com/emails',
        [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        $body
    );
}

function sendWithBrevo(array $config, array $message): void
{
    $apiKey = trim((string) ($config['brevo_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Missing brevo_api_key in application configuration.');
    }

    $body = [
        'sender' => [
            'name' => $message['from_name'],
            'email' => $message['from_email'],
        ],
        'to' => [
            [
                'email' => $message['to'],
            ]
        ],
        'subject' => $message['subject'],
        'htmlContent' => $message['html'],
        'textContent' => $message['text'],
    ];

    if ($message['reply_to'] !== '') {
        $body['replyTo'] = [
            'email' => $message['reply_to'],
        ];
    }

    requestJson(
        'https://api.brevo.com/v3/smtp/email',
        [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
        ],
        $body
    );
}

function handleCompleteSignup(array $config, array $payload): void
{
    $supabaseUrl = trim((string) ($config['supabase']['url'] ?? ''));
    $serviceRoleKey = trim((string) ($config['supabase']['service_role_key'] ?? ''));

    if ($supabaseUrl === '' || $serviceRoleKey === '') {
        respond(500, [
            'success' => false,
            'error' => 'Supabase backend is not configured. Set supabase.url and supabase.service_role_key in config.php or environment variables.',
        ]);
    }

    $employeeId = trim((string) ($payload['employeeId'] ?? ''));
    $email = normalizeEmail($payload['email'] ?? '');
    $password = trim((string) ($payload['password'] ?? ''));
    $firstName = trim((string) ($payload['firstName'] ?? ''));
    $middleName = trim((string) ($payload['middleName'] ?? ''));
    $lastName = trim((string) ($payload['lastName'] ?? ''));
    $contactNumber = trim((string) ($payload['contactNumber'] ?? ''));
    $department = trim((string) ($payload['department'] ?? ''));
    $position = trim((string) ($payload['position'] ?? ''));

    if ($employeeId === '' || $email === '' || $password === '' || $firstName === '' || $lastName === '' || $contactNumber === '' || $department === '' || $position === '') {
        respond(400, [
            'success' => false,
            'error' => 'Missing required signup fields.',
        ]);
    }

    if (!ctype_digit($employeeId)) {
        respond(400, [
            'success' => false,
            'error' => 'Employee ID must be a valid integer.',
        ]);
    }

    $existingEmployee = supabaseRequest(
        $supabaseUrl,
        $serviceRoleKey,
        '/rest/v1/employees?employee_id=eq.' . urlencode($employeeId),
        'GET'
    );
    if (!empty($existingEmployee['json']) && is_array($existingEmployee['json']) && count($existingEmployee['json']) > 0) {
        respond(409, [
            'success' => false,
            'error' => 'An account with this employee ID already exists.',
        ]);
    }

    $existingUser = supabaseRequest(
        $supabaseUrl,
        $serviceRoleKey,
        '/rest/v1/users?email=eq.' . urlencode(strtolower($email)),
        'GET'
    );
    if (!empty($existingUser['json']) && is_array($existingUser['json']) && count($existingUser['json']) > 0) {
        respond(409, [
            'success' => false,
            'error' => 'An account with this email already exists.',
        ]);
    }

    $passwordHash = normalizeBcryptHash(password_hash($password, PASSWORD_BCRYPT));

    $signupToken = generateSignupToken([
        'employeeId' => (int) $employeeId,
        'email' => strtolower($email),
        'passwordHash' => $passwordHash,
        'firstName' => $firstName,
        'middleName' => $middleName,
        'lastName' => $lastName,
        'contactNumber' => $contactNumber,
        'department' => $department,
        'position' => $position,
    ], $config['shared_api_key']);

    try {
        sendSignupVerificationEmail($config, [
            'email' => strtolower($email),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'employee_id' => (int) $employeeId,
            'token' => $signupToken,
        ]);
    } catch (Throwable $exception) {
        respond(500, [
            'success' => false,
            'error' => 'Failed to send signup verification email: ' . $exception->getMessage(),
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'A verification link has been sent to your email. Your account will be created after verification.',
    ]);
}

function handleConfirmSignup(array $config, array $payload): void
{
    $supabaseUrl = trim((string) ($config['supabase']['url'] ?? ''));
    $serviceRoleKey = trim((string) ($config['supabase']['service_role_key'] ?? ''));
    $token = trim((string) ($payload['token'] ?? ''));

    if ($supabaseUrl === '' || $serviceRoleKey === '') {
        respond(500, [
            'success' => false,
            'error' => 'Supabase backend is not configured. Set supabase.url and supabase.service_role_key in config.php or environment variables.',
        ]);
    }

    if ($token === '') {
        respond(400, [
            'success' => false,
            'error' => 'Signup verification token is required.',
        ]);
    }

    $signupData = verifySignupToken($token, $config['shared_api_key']);
    if ($signupData === null) {
        respond(400, [
            'success' => false,
            'error' => 'Invalid or expired signup verification link.',
        ]);
    }

    $employeeId = (string) ($signupData['employeeId'] ?? '');
    $email = normalizeEmail($signupData['email'] ?? '');
    $passwordHash = trim((string) ($signupData['passwordHash'] ?? ''));
    $firstName = trim((string) ($signupData['firstName'] ?? ''));
    $middleName = trim((string) ($signupData['middleName'] ?? ''));
    $lastName = trim((string) ($signupData['lastName'] ?? ''));
    $contactNumber = trim((string) ($signupData['contactNumber'] ?? ''));
    $department = trim((string) ($signupData['department'] ?? ''));
    $position = trim((string) ($signupData['position'] ?? ''));

    if ($employeeId === '' || $email === '' || $passwordHash === '' || $firstName === '' || $lastName === '' || $contactNumber === '' || $department === '' || $position === '') {
        respond(400, [
            'success' => false,
            'error' => 'Signup verification token is missing required data.',
        ]);
    }

    $existingEmployee = supabaseRequest(
        $supabaseUrl,
        $serviceRoleKey,
        '/rest/v1/employees?employee_id=eq.' . urlencode($employeeId),
        'GET'
    );
    $existingUserByEmail = supabaseRequest(
        $supabaseUrl,
        $serviceRoleKey,
        '/rest/v1/users?email=eq.' . urlencode(strtolower($email)),
        'GET'
    );
    $existingUserByEmployeeId = supabaseRequest(
        $supabaseUrl,
        $serviceRoleKey,
        '/rest/v1/users?employee_id=eq.' . urlencode($employeeId),
        'GET'
    );

    $employeeExists = !empty($existingEmployee['json']) && is_array($existingEmployee['json']) && count($existingEmployee['json']) > 0;
    $userExistsByEmail = !empty($existingUserByEmail['json']) && is_array($existingUserByEmail['json']) && count($existingUserByEmail['json']) > 0;
    $userExistsByEmployeeId = !empty($existingUserByEmployeeId['json']) && is_array($existingUserByEmployeeId['json']) && count($existingUserByEmployeeId['json']) > 0;

    if ($employeeExists && $userExistsByEmployeeId) {
        respond(200, [
            'success' => true,
            'message' => 'Your account is already verified and active.',
        ]);
    }

    if ($userExistsByEmail && !$userExistsByEmployeeId) {
        respond(409, [
            'success' => false,
            'error' => 'An account with this email already exists.',
        ]);
    }

    if ($userExistsByEmployeeId && !$employeeExists) {
        respond(409, [
            'success' => false,
            'error' => 'An account with this employee ID already exists.',
        ]);
    }

    $employeePayload = [
        'employee_id' => (int) $employeeId,
        'firstname' => $firstName,
        'middlename' => $middleName,
        'lastname' => $lastName,
        'contact_number' => $contactNumber,
        'department' => $department,
        'position' => $position,
    ];

    $employeeResponse = supabaseRequest(
        $supabaseUrl,
        $serviceRoleKey,
        '/rest/v1/employees?on_conflict=employee_id',
        'POST',
        [$employeePayload],
        [
            'Prefer: resolution=merge-duplicates,return=representation',
        ]
    );

    if ($employeeResponse['status'] < 200 || $employeeResponse['status'] >= 300) {
        respondSupabaseError($employeeResponse, 'Failed to save employee details.');
    }

    $userPayload = [
        'employee_id' => (int) $employeeId,
        'email' => $email,
        'password' => $passwordHash,
        'role' => 'employee',
    ];

    $userResponse = supabaseRequest(
        $supabaseUrl,
        $serviceRoleKey,
        '/rest/v1/users?on_conflict=employee_id',
        'POST',
        [$userPayload],
        [
            'Prefer: resolution=merge-duplicates,return=representation',
        ]
    );

    if ($userResponse['status'] < 200 || $userResponse['status'] >= 300) {
        if ($userResponse['status'] === 409 && strpos((string) $userResponse['body'], 'users_employee_id_key') !== false) {
            $existingUserByEmployeeId = supabaseRequest(
                $supabaseUrl,
                $serviceRoleKey,
                '/rest/v1/users?employee_id=eq.' . urlencode($employeeId),
                'GET'
            );

            if ($existingUserByEmployeeId['status'] === 200 && !empty($existingUserByEmployeeId['json'][0]) && is_array($existingUserByEmployeeId['json'][0])) {
                $userRow = $existingUserByEmployeeId['json'][0];
                respond(200, [
                    'success' => true,
                    'message' => 'Your account is already verified and active.',
                    'employee' => [
                        'user_id' => $userRow['user_id'] ?? null,
                        'employee_id' => $userRow['employee_id'] ?? (int) $employeeId,
                        'email' => $userRow['email'] ?? strtolower($email),
                        'firstname' => $firstName,
                        'lastname' => $lastName,
                        'middlename' => $middleName,
                        'contact_number' => $contactNumber,
                        'role' => $userRow['role'] ?? 'employee',
                    ],
                ]);
            }
        }

        respondSupabaseError($userResponse, 'Failed to create user account.');
    }

    $userRow = $userResponse['json'][0] ?? null;
    if (!is_array($userRow)) {
        respond(500, [
            'success' => false,
            'error' => 'Supabase returned an invalid user payload.',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Your account has been verified and created successfully.',
        'employee' => [
            'user_id' => $userRow['user_id'] ?? null,
            'employee_id' => $userRow['employee_id'] ?? (int) $employeeId,
            'email' => $userRow['email'] ?? strtolower($email),
            'firstname' => $firstName,
            'lastname' => $lastName,
            'middlename' => $middleName,
            'contact_number' => $contactNumber,
            'department' => $department,
            'position' => $position,
            'role' => $userRow['role'] ?? 'employee',
        ],
    ]);
}

function generateSignupToken(array $data, string $secret): string
{
    $payload = json_encode(array_merge($data, ['exp' => time() + 3600]));
    $payloadEncoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadEncoded, $secret, true)), '+/', '-_'), '=');
    return $payloadEncoded . '.' . $signature;
}

function generatePasswordResetToken(array $data, string $secret): string
{
    return generateSignupToken($data, $secret);
}

function verifySignupToken(string $token, string $secret): ?array
{
    [$payloadEncoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
    if ($payloadEncoded === '' || $signature === '') {
        return null;
    }

    $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadEncoded, $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    $payloadJson = base64_decode(strtr($payloadEncoded, '-_', '+/'));
    if ($payloadJson === false) {
        return null;
    }

    $data = json_decode($payloadJson, true);
    if (!is_array($data) || empty($data['exp']) || !is_int($data['exp']) || $data['exp'] < time()) {
        return null;
    }

    unset($data['exp']);
    return $data;
}

function verifyPasswordResetToken(string $token, string $secret): ?array
{
    return verifySignupToken($token, $secret);
}

function handleCompletePasswordReset(array $config, array $payload): void
{
    $supabaseUrl = trim((string) ($config['supabase']['url'] ?? ''));
    $serviceRoleKey = trim((string) ($config['supabase']['service_role_key'] ?? ''));

    if ($supabaseUrl === '' || $serviceRoleKey === '') {
        respond(500, [
            'success' => false,
            'error' => 'Supabase backend is not configured. Set supabase.url and supabase.service_role_key in config.php or environment variables.',
        ]);
    }

    $token = trim((string) ($payload['token'] ?? ''));
    $newPassword = trim((string) ($payload['password'] ?? ''));

    if ($token === '' || $newPassword === '') {
        respond(400, [
            'success' => false,
            'error' => 'Reset token and new password are required.',
        ]);
    }

    $resetData = verifyPasswordResetToken($token, $config['shared_api_key']);
    if ($resetData === null || empty($resetData['email'])) {
        respond(400, [
            'success' => false,
            'error' => 'Invalid or expired password reset link.',
        ]);
    }

    $email = (string) ($resetData['email'] ?? '');
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        respond(400, [
            'success' => false,
            'error' => 'Invalid password reset link.',
        ]);
    }

    $accountType = trim((string) ($resetData['account_type'] ?? ''));
    $hashedPassword = normalizeBcryptHash(password_hash($newPassword, PASSWORD_BCRYPT));
    $targetTable = '';
    $idColumn = '';
    $recordId = null;
    $updateResponse = null;

    if ($accountType === 'admin' || $accountType === '') {
        $adminResponse = supabaseRequest(
            $supabaseUrl,
            $serviceRoleKey,
            '/rest/v1/admin_credentials?email=ilike.' . urlencode($email) . '&select=admin_id',
            'GET'
        );

        if ($adminResponse['status'] === 200 && !empty($adminResponse['json'][0]) && is_array($adminResponse['json'][0])) {
            $recordId = $adminResponse['json'][0]['admin_id'] ?? null;
            if ($recordId !== null && $recordId !== '') {
                $targetTable = 'admin_credentials';
                $idColumn = 'admin_id';
                $updateResponse = supabaseRequest(
                    $supabaseUrl,
                    $serviceRoleKey,
                    '/rest/v1/admin_credentials?admin_id=eq.' . urlencode((string) $recordId),
                    'PATCH',
                    ['password' => $hashedPassword],
                    ['Prefer: return=representation']
                );
            }
        }
    }

    if ($updateResponse === null) {
        $userResponse = supabaseRequest(
            $supabaseUrl,
            $serviceRoleKey,
            '/rest/v1/users?email=ilike.' . urlencode($email) . '&select=user_id',
            'GET'
        );

        if ($userResponse['status'] === 200 && !empty($userResponse['json'][0]) && is_array($userResponse['json'][0])) {
            $recordId = $userResponse['json'][0]['user_id'] ?? null;
            if ($recordId !== null && $recordId !== '') {
                $targetTable = 'users';
                $idColumn = 'user_id';
                $updateResponse = supabaseRequest(
                    $supabaseUrl,
                    $serviceRoleKey,
                    '/rest/v1/users?user_id=eq.' . urlencode((string) $recordId),
                    'PATCH',
                    ['password' => $hashedPassword],
                    ['Prefer: return=representation']
                );
            }
        }
    }

    if ($updateResponse === null || $targetTable === '' || $idColumn === '') {
        respond(404, [
            'success' => false,
            'error' => 'No account found for this reset link.',
        ]);
    }

    if ($updateResponse['status'] < 200 || $updateResponse['status'] >= 300) {
        respondSupabaseError($updateResponse, 'Failed to update the password.');
    }

    if (!is_array($updateResponse['json']) || count($updateResponse['json']) === 0) {
        respond(500, [
            'success' => false,
            'error' => 'Password reset did not update any account record.',
        ]);
    }

    $storedPassword = $updateResponse['json'][0]['password'] ?? null;
    if ($storedPassword !== $hashedPassword) {
        $verificationResponse = supabaseRequest(
            $supabaseUrl,
            $serviceRoleKey,
            '/rest/v1/' . $targetTable . '?' . $idColumn . '=eq.' . urlencode((string) $recordId) . '&select=password',
            'GET'
        );

        if ($verificationResponse['status'] !== 200 || empty($verificationResponse['json'][0]) || !is_array($verificationResponse['json'][0]) || ($verificationResponse['json'][0]['password'] ?? null) !== $hashedPassword) {
            respond(500, [
                'success' => false,
                'error' => 'Password reset failed, password was not persisted correctly.',
            ]);
        }
    }

    error_log($targetTable . ' password updated successfully for ' . $idColumn . '=' . $recordId . ' (email=' . $email . ')');

    respond(200, [
        'success' => true,
        'message' => 'Your password has been reset successfully. You can now sign in with your new password.',
    ]);
}

function handleForgotPassword(array $config, array $payload): void
{
    $supabaseUrl = trim((string) ($config['supabase']['url'] ?? ''));
    $serviceRoleKey = trim((string) ($config['supabase']['service_role_key'] ?? ''));

    if ($supabaseUrl === '' || $serviceRoleKey === '') {
        respond(500, [
            'success' => false,
            'error' => 'Supabase backend is not configured. Set supabase.url and supabase.service_role_key in config.php or environment variables.',
        ]);
    }

    $email = (string) ($payload['email'] ?? '');
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        respond(400, [
            'success' => false,
            'error' => 'A valid email is required for password reset.',
        ]);
    }

    $adminResponse = supabaseRequest(
        $supabaseUrl,
        $serviceRoleKey,
        '/rest/v1/admin_credentials?email=ilike.' . urlencode($email) . '&select=admin_id,email',
        'GET'
    );

    $accountType = '';
    if ($adminResponse['status'] === 200 && !empty($adminResponse['json'][0]) && is_array($adminResponse['json'][0])) {
        $accountType = 'admin';
    }

    $userResponse = null;
    if ($accountType === '') {
        $userResponse = supabaseRequest(
            $supabaseUrl,
            $serviceRoleKey,
            '/rest/v1/users?email=ilike.' . urlencode($email) . '&select=user_id,email',
            'GET'
        );

        if ($userResponse['status'] === 200 && !empty($userResponse['json'][0]) && is_array($userResponse['json'][0])) {
            $accountType = 'user';
        }
    }

    if ($accountType === '') {
        // Keep the response generic for security.
        respond(200, [
            'success' => true,
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    $resetToken = generatePasswordResetToken([
        'email' => $email,
        'account_type' => $accountType,
    ], $config['shared_api_key']);

    try {
        sendPasswordResetEmail($config, $email, $resetToken, $accountType);
    } catch (Throwable $exception) {
        respond(500, [
            'success' => false,
            'error' => 'Password reset email failed to send: ' . $exception->getMessage(),
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'If an account exists for that email, a password reset link has been sent.',
    ]);
}

function generateTemporaryPassword(int $length = 12): string
{
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+';
    $password = '';
    $maxIndex = strlen($characters) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, $maxIndex)];
    }
    return $password;
}

function sendPasswordResetEmail(array $config, string $email, string $resetToken, string $accountType = 'admin'): void
{
    $frontendBaseUrl = frontendBaseUrlForAccount($config, $accountType);
    $resetPath = '/?reset_token=';
    $resetUrl = $frontendBaseUrl . $resetPath . urlencode($resetToken);
    $subject = $accountType === 'user' ? 'Serendipity Password Reset' : 'Serendipity Admin Password Reset';
    $text = "A password reset request was received for your account.\n\n"
        . "Use the link below to reset your password:\n\n"
        . "{$resetUrl}\n\n"
        . "If you did not request this change, please contact Serendipity HR immediately.\n";

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;line-height:1.6;">'
        . '<h2 style="color:#166534;margin-bottom:8px;">Password Reset Request</h2>'
        . '<p>A password reset request was received for your account.</p>'
        . '<p>Click the button below to create a new password for your account.</p>'
        . '<p><a href="' . escapeHtml($resetUrl) . '" style="display:inline-block;padding:12px 18px;background:#2f8f3a;color:#ffffff;text-decoration:none;border-radius:8px;">Reset My Password</a></p>'
        . '<p>If you did not request this, contact Serendipity HR immediately.</p>'
        . '</div>';

    $transport = strtolower(trim((string) ($config['transport'] ?? 'smtp')));
    switch ($transport) {
        case 'resend':
            sendWithResend($config, [
                'to' => $email,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')), 
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            break;

        case 'brevo':
            sendWithBrevo($config, [
                'to' => $email,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')), 
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            break;

        case 'smtp':
            sendWithSmtp($config, [
                'to' => $email,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')), 
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            break;

        default:
            throw new RuntimeException('Unsupported transport configured. Use "smtp", "resend", or "brevo".');
    }
}

function sendSignupVerificationEmail(array $config, array $data): void
{
    $frontendBaseUrl = frontendBaseUrlForAccount($config, 'user');
    $verificationUrl = $frontendBaseUrl . '/?verify_token=' . urlencode($data['token'] ?? '');
    $subject = 'Verify your Serendipity account';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;line-height:1.6;">'
        . '<h2 style="color:#166534;margin-bottom:8px;">Confirm your email address</h2>'
        . '<p>Hello ' . escapeHtml($data['first_name'] . ' ' . $data['last_name']) . ',</p>'
        . '<p>Click the button below to verify your email and complete your Serendipity signup.</p>'
        . '<p><strong>Employee ID:</strong> ' . escapeHtml((string) ($data['employee_id'] ?? '')) . '<br>'
        . '<strong>Email:</strong> ' . escapeHtml($data['email'] ?? '') . '</p>'
        . '<p><a href="' . escapeHtml($verificationUrl) . '" style="display:inline-block;padding:12px 18px;background:#2f8f3a;color:#ffffff;text-decoration:none;border-radius:8px;">Verify Email</a></p>'
        . '<p>If you did not request this signup, you can ignore this email.</p>'
        . '</div>';

    $text = "Hello {$data['first_name']} {$data['last_name']},\n\n"
        . "Click the link below to verify your email and complete your Serendipity signup.\n\n"
        . "Employee ID: {$data['employee_id']}\n"
        . "Email: {$data['email']}\n\n"
        . "Verify your email here: {$verificationUrl}\n\n"
        . "If you did not request this signup, you can ignore this message.";

    $transport = strtolower(trim((string) ($config['transport'] ?? 'smtp')));
    switch ($transport) {
        case 'resend':
            sendWithResend($config, [
                'to' => $data['email'],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')),
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            return;

        case 'brevo':
            sendWithBrevo($config, [
                'to' => $data['email'],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')),
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            return;

        case 'smtp':
            sendWithSmtp($config, [
                'to' => $data['email'],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')),
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            return;

        default:
            throw new RuntimeException('Unsupported transport configured. Use "smtp", "resend", or "brevo".');
    }
}

function sendSignupConfirmationEmail(array $config, array $employee): void
{
    $frontendBaseUrl = frontendBaseUrlForAccount($config, 'user');
    $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
    $dashboardUrl = $frontendBaseUrl !== '' ? $frontendBaseUrl : 'http://localhost:5173';
    $subject = 'Welcome to Serendipity Employee Portal';

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;line-height:1.6;">'
        . '<h2 style="color:#166534;margin-bottom:8px;">Signup complete</h2>'
        . '<p>Hello ' . escapeHtml($fullName) . ',</p>'
        . '<p>Your employee portal account has been created successfully.</p>'
        . '<p><strong>Employee ID:</strong> ' . escapeHtml((string) $employee['employee_id']) . '<br>'
        . '<strong>Email:</strong> ' . escapeHtml($employee['email']) . '</p>'
        . '<p>You can now sign in to the 👤 Serendipity Employee Portal.</p>'
        . '<p><a href="' . escapeHtml($dashboardUrl) . '" style="display:inline-block;padding:12px 18px;background:#2f8f3a;color:#ffffff;text-decoration:none;border-radius:8px;">👤 Open Employee Portal</a></p>'
        . '<p style="color:#6b7280;font-size:14px;">If you did not request this signup, please contact Serendipity HR.</p>'
        . '</div>';

    $text = "Hello {$fullName},\n\n"
        . "Your employee portal account has been created successfully.\n"
        . "Employee ID: {$employee['employee_id']}\n"
        . "Email: {$employee['email']}\n\n"
        . "👤 You can now sign in at {$dashboardUrl}\n\n"
        . "If you did not request this signup, please contact Serendipity HR.";

    $transport = strtolower(trim((string) ($config['transport'] ?? 'smtp')));

    switch ($transport) {
        case 'resend':
            sendWithResend($config, [
                'to' => $employee['email'],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')),
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            return;

        case 'brevo':
            sendWithBrevo($config, [
                'to' => $employee['email'],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')),
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            return;

        case 'smtp':
            sendWithSmtp($config, [
                'to' => $employee['email'],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'from_email' => normalizeEmail($config['default_from_email'] ?? ''),
                'from_name' => trim((string) ($config['default_from_name'] ?? 'Serendipity HR')),
                'reply_to' => normalizeEmail($config['default_reply_to'] ?? ''),
            ]);
            return;

        default:
            throw new RuntimeException('Unsupported transport configured. Use "smtp", "resend", or "brevo".');
    }
}

function supabaseRequest(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $path,
    string $method,
    ?array $body = null,
    array $extraHeaders = []
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available on this PHP host.');
    }

    $headers = array_merge([
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Content-Type: application/json',
    ], $extraHeaders);

    $ch = curl_init(rtrim($supabaseUrl, '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Supabase request failed: ' . $error);
    }

    $decoded = json_decode($response, true);

    return [
        'status' => $status,
        'body' => $response,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function respondSupabaseError(array $response, string $fallbackMessage): void
{
    $payload = $response['json'];
    $message = $fallbackMessage;

    if (is_array($payload)) {
        foreach (['message', 'error', 'details', 'hint'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                $message = $payload[$key];
                break;
            }
        }
    }

    respond(400, [
        'success' => false,
        'error' => $message,
    ]);
}

function enforceSharedApiKey(array $config, array $payload): void
{
    $sharedApiKey = trim((string) ($config['shared_api_key'] ?? ''));
    $providedApiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ($payload['api_key'] ?? '')));

    if ($sharedApiKey === '') {
        respond(500, [
            'success' => false,
            'error' => 'shared_api_key is not configured in application configuration.',
        ]);
    }

    if (!hash_equals($sharedApiKey, $providedApiKey)) {
        respond(401, [
            'success' => false,
            'error' => 'Unauthorized request.',
        ]);
    }
}

function sendWithSmtp(array $config, array $message): void
{
    $smtp = $config['smtp'] ?? [];
    $host = trim((string) ($smtp['host'] ?? 'smtp.gmail.com'));
    $port = (int) ($smtp['port'] ?? 587);
    $username = trim((string) ($smtp['username'] ?? ''));
    $password = trim((string) ($smtp['password'] ?? ''));
    $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
    $timeout = (int) ($smtp['timeout'] ?? 30);

    if ($host === '' || $port <= 0 || $username === '' || $password === '') {
        throw new RuntimeException('Missing SMTP host, port, username, or password in application configuration.');
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $port;
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->Timeout = $timeout;
        $mailer->CharSet = 'UTF-8';

        if ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        if (!empty($smtp['ehlo_host'])) {
            $mailer->Hostname = trim((string) $smtp['ehlo_host']);
        }

        $mailer->setFrom($message['from_email'], $message['from_name']);
        $mailer->addAddress($message['to']);

        if ($message['reply_to'] !== '') {
            $mailer->addReplyTo($message['reply_to']);
        }

        $mailer->isHTML(true);
        $mailer->Subject = $message['subject'];
        $mailer->Body = $message['html'];
        $mailer->AltBody = $message['text'];
        $mailer->send();
    } catch (MailException $exception) {
        throw new RuntimeException('PHPMailer SMTP error: ' . $exception->getMessage());
    }
}

function requestJson(string $url, array $headers, array $body): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available on this PHP host.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Mail provider request failed: ' . $curlError);
    }

    $decoded = json_decode($response, true);
    if ($statusCode < 200 || $statusCode >= 300) {
        $providerMessage = extractProviderError($decoded, $response);
        throw new RuntimeException('Mail provider rejected the request: ' . $providerMessage);
    }

    return is_array($decoded) ? $decoded : [];
}

function extractProviderError($decoded, string $fallback): string
{
    if (is_array($decoded)) {
        foreach (['message', 'error', 'msg'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                return $decoded[$key];
            }
        }

        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $firstError = reset($decoded['errors']);
            if (is_array($firstError) && !empty($firstError['message'])) {
                return (string) $firstError['message'];
            }
        }
    }

    return $fallback !== '' ? $fallback : 'Unknown provider error.';
}

function normalizeEmail($value): string
{
    $email = trim((string) $value);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function formatSender(string $name, string $email): string
{
    $safeName = trim($name);
    return $safeName !== '' ? sprintf('%s <%s>', $safeName, $email) : $email;
}

function htmlToText(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\r\n|\r|\n/', PHP_EOL, $text ?? '');
    return trim((string) $text);
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function frontendBaseUrlForAccount(array $config, string $accountType): string
{
    $originBaseUrl = frontendBaseUrlFromRequestOrigin($config, $accountType);
    if ($originBaseUrl !== null) {
        return $originBaseUrl;
    }

    return $accountType === 'user' ? employeeFrontendBaseUrl($config) : adminFrontendBaseUrl($config);
}

function frontendBaseUrlFromRequestOrigin(array $config, string $accountType): ?string
{
    $origin = normalizeOptionalFrontendBaseUrl($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return null;
    }

    $localUrls = $accountType === 'user'
        ? [
            $config['employee_local_frontend_base_url'] ?? '',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ]
        : [
            $config['admin_local_frontend_base_url'] ?? '',
            'http://localhost:5174',
            'http://127.0.0.1:5174',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ];

    return in_array($origin, normalizeFrontendBaseUrlList($localUrls), true) ? $origin : null;
}

function employeeFrontendBaseUrl(array $config): string
{
    return normalizeFrontendBaseUrl($config['employee_frontend_base_url'] ?? ($config['frontend_base_url'] ?? 'http://localhost:5173'));
}

function adminFrontendBaseUrl(array $config): string
{
    return normalizeFrontendBaseUrl($config['admin_frontend_base_url'] ?? ($config['frontend_base_url'] ?? 'http://localhost:5173'));
}

function normalizeFrontendBaseUrl($value): string
{
    $url = normalizeOptionalFrontendBaseUrl($value);
    return $url !== '' ? $url : 'http://localhost:5173';
}

function normalizeOptionalFrontendBaseUrl($value): string
{
    return rtrim(trim((string) $value), '/');
}

function normalizeFrontendBaseUrlList(array $values): array
{
    $urls = array_map('normalizeOptionalFrontendBaseUrl', $values);
    return array_values(array_filter($urls, static fn (string $url): bool => $url !== ''));
}

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function loadConfig(): array
{
    $fileConfig = [];
    $configFile = __DIR__ . '/config.php';

    if (file_exists($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $fileConfig = $loaded;
        }
    }

    $frontendBaseUrl = envValue('FRONTEND_BASE_URL', (string) ($fileConfig['frontend_base_url'] ?? 'http://localhost:5173'));
    $employeeFrontendBaseUrl = envValue('EMPLOYEE_FRONTEND_BASE_URL', (string) ($fileConfig['employee_frontend_base_url'] ?? $frontendBaseUrl));
    $adminFrontendBaseUrl = envValue('ADMIN_FRONTEND_BASE_URL', (string) ($fileConfig['admin_frontend_base_url'] ?? $frontendBaseUrl));
    $employeeLocalFrontendBaseUrl = envValue('EMPLOYEE_LOCAL_FRONTEND_BASE_URL', (string) ($fileConfig['employee_local_frontend_base_url'] ?? 'http://localhost:5173'));
    $adminLocalFrontendBaseUrl = envValue('ADMIN_LOCAL_FRONTEND_BASE_URL', (string) ($fileConfig['admin_local_frontend_base_url'] ?? 'http://localhost:5174'));

    return [
        'shared_api_key' => envValue('SHARED_API_KEY', (string) ($fileConfig['shared_api_key'] ?? '')),
        'transport' => strtolower(envValue('MAIL_TRANSPORT', (string) ($fileConfig['transport'] ?? 'smtp'))),
        'default_from_email' => envValue('DEFAULT_FROM_EMAIL', (string) ($fileConfig['default_from_email'] ?? '')),
        'default_from_name' => envValue('DEFAULT_FROM_NAME', (string) ($fileConfig['default_from_name'] ?? 'Serendipity HR')),
        'default_reply_to' => envValue('DEFAULT_REPLY_TO', (string) ($fileConfig['default_reply_to'] ?? '')),
        'frontend_base_url' => $frontendBaseUrl,
        'employee_frontend_base_url' => $employeeFrontendBaseUrl,
        'admin_frontend_base_url' => $adminFrontendBaseUrl,
        'employee_local_frontend_base_url' => $employeeLocalFrontendBaseUrl,
        'admin_local_frontend_base_url' => $adminLocalFrontendBaseUrl,
        'allowed_origins' => envCsvList('ALLOWED_ORIGINS', $fileConfig['allowed_origins'] ?? ['*']),
        'supabase' => [
            'url' => envValue('SUPABASE_URL', (string) (($fileConfig['supabase']['url'] ?? ''))),
            'service_role_key' => envValue('SUPABASE_SERVICE_ROLE_KEY', (string) (($fileConfig['supabase']['service_role_key'] ?? ''))),
        ],
        'smtp' => [
            'host' => envValue('SMTP_HOST', (string) (($fileConfig['smtp']['host'] ?? 'smtp.gmail.com'))),
            'port' => envInt('SMTP_PORT', (int) (($fileConfig['smtp']['port'] ?? 587))),
            'encryption' => strtolower(envValue('SMTP_ENCRYPTION', (string) (($fileConfig['smtp']['encryption'] ?? 'tls')))),
            'username' => envValue('SMTP_USERNAME', (string) (($fileConfig['smtp']['username'] ?? ''))),
            'password' => envValue('SMTP_PASSWORD', (string) (($fileConfig['smtp']['password'] ?? ''))),
            'timeout' => envInt('SMTP_TIMEOUT', (int) (($fileConfig['smtp']['timeout'] ?? 30))),
            'ehlo_host' => envValue('SMTP_EHLO_HOST', (string) (($fileConfig['smtp']['ehlo_host'] ?? 'localhost'))),
        ],
        'resend_api_key' => envValue('RESEND_API_KEY', (string) ($fileConfig['resend_api_key'] ?? '')),
        'brevo_api_key' => envValue('BREVO_API_KEY', (string) ($fileConfig['brevo_api_key'] ?? '')),
    ];
}

function configureCors(array $config): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowedOrigins = $config['allowed_origins'] ?? ['*'];

    if (!is_array($allowedOrigins) || $allowedOrigins === []) {
        $allowedOrigins = ['*'];
    }

    if (in_array('*', $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');
}

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $trimmed = trim($value);
    return $trimmed !== '' ? $trimmed : $default;
}

function envInt(string $key, int $default): int
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed !== false ? (int) $parsed : $default;
}

function envCsvList(string $key, $default): array
{
    $value = getenv($key);
    if ($value === false || trim($value) === '') {
        return is_array($default) ? array_values(array_filter(array_map('trim', $default), static fn ($item) => $item !== '')) : [];
    }

    $items = array_map('trim', explode(',', $value));
    return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
}
