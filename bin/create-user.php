<?php

declare(strict_types=1);

/**
 * SyncClass — create an account from the command line.
 *
 *     php bin/create-user.php admin@sac.edu.ph Ana Reyes --role=administrator
 *     php bin/create-user.php --email=dean.cas@sac.edu.ph --first=Maria --last=Santos --role=dean
 *
 * The password is prompted for (twice) and never accepted as an argument, so it
 * does not end up in the shell history or in `ps` output. It is validated
 * against the same policy the UI uses (config/security.php) and stored with
 * Security::hashPassword().
 *
 * Roles are created on demand, which is what makes a fresh install usable
 * before Phase 19 seeds the full demo data set. Phase 19 will replace this
 * bootstrap path for demo environments; production installs keep using this
 * command for the first administrator.
 *
 * Exit codes: 0 success, 1 validation or database error.
 */

use App\Core\Database;
use App\Core\Security;
use App\Core\Session;
use App\Helpers\Validator;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

/**
 * Read a password from the terminal with echo disabled when the platform allows
 * it. On Windows (XAMPP) `stty` is unavailable, so the fallback prints a warning
 * and reads visibly rather than failing.
 */
function prompt_password(string $label): string
{
    fwrite(STDOUT, $label);

    $hidden = false;

    if (DIRECTORY_SEPARATOR !== '\\' && function_exists('exec')) {
        @exec('stty -echo 2>/dev/null', $output, $status);
        $hidden = ($status ?? 1) === 0;
    }

    $value = fgets(STDIN);

    if ($hidden) {
        @exec('stty echo 2>/dev/null');
    }

    fwrite(STDOUT, PHP_EOL);

    if (!$hidden) {
        fwrite(STDERR, "Note: password echo could not be disabled on this platform.\n");
    }

    return trim((string) $value);
}

// ---------------------------------------------------------------------------
// Arguments: positional email/first/last plus --key=value flags.
// ---------------------------------------------------------------------------
$positional = [];
$flags = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        $flags[$key] = $value;
        continue;
    }

    $positional[] = $argument;
}

$email = strtolower(trim((string) ($flags['email'] ?? $positional[0] ?? '')));
$firstName = trim((string) ($flags['first'] ?? $positional[1] ?? ''));
$lastName = trim((string) ($flags['last'] ?? $positional[2] ?? ''));
$roleCode = strtolower(trim((string) ($flags['role'] ?? 'administrator')));

$roleNames = [
    'administrator' => 'System Administrator',
    'dean' => 'Dean',
    'secretary' => 'Department Secretary',
    'faculty' => 'Faculty',
];

if ($email === '' || $firstName === '' || $lastName === '') {
    fwrite(STDERR, "Usage: php bin/create-user.php <email> <first-name> <last-name> [--role=administrator|dean|secretary|faculty]\n");
    exit(1);
}

if (!array_key_exists($roleCode, $roleNames)) {
    fwrite(STDERR, sprintf("Unknown role \"%s\". Use one of: %s\n", $roleCode, implode(', ', array_keys($roleNames))));
    exit(1);
}

$input = Validator::make(
    ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName],
    [
        'email' => 'required|email|max:150',
        'first_name' => 'required|string|max:80',
        'last_name' => 'required|string|max:80',
    ],
    ['email' => 'Email address', 'first_name' => 'First name', 'last_name' => 'Last name']
);

if ($input->fails()) {
    foreach ($input->errors() as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }

    exit(1);
}

$password = prompt_password('Password: ');
$confirmation = prompt_password('Confirm password: ');

if ($password !== $confirmation) {
    fwrite(STDERR, "The passwords do not match.\n");
    exit(1);
}

$policy = Validator::make(
    ['password' => $password],
    ['password' => ['required', 'password']],
    ['password' => 'Password'],
    [],
    (array) ($config['security']['password'] ?? [])
);

if ($policy->fails()) {
    fwrite(STDERR, (string) $policy->firstError('password') . PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------------------
// Write the account.
// ---------------------------------------------------------------------------
$database = new Database((array) $config['database']);
$users = new UserRepository($database);
$roles = new RoleRepository($database);
$security = new Security(new Session((array) $config['app']['session']), (array) $config['security']);

try {
    if ($users->findByEmail($email) !== null) {
        fwrite(STDERR, sprintf("An account with %s already exists.\n", $email));
        exit(1);
    }

    $roleId = $roles->firstOrCreate($roleCode, $roleNames[$roleCode], null, array_search($roleCode, array_keys($roleNames), true) * 10 + 10);

    $username = preg_replace('/[^a-z0-9._-]/', '.', strstr($email, '@', true) ?: $email) ?: 'user';

    $userId = $users->create([
        'role_id' => $roleId,
        'email' => $email,
        'username' => $username,
        'password_hash' => $security->hashPassword($password),
        'first_name' => $firstName,
        'last_name' => $lastName,
    ]);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not create the account: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

printf(
    "Created user #%d\n  email:    %s\n  username: %s\n  role:     %s\nSign in at: %s/login\n",
    $userId,
    $email,
    $username,
    $roleNames[$roleCode],
    rtrim((string) ($config['app']['url'] ?? ''), '/')
);

exit(0);
