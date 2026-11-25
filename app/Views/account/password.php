<?php

/**
 * Change-password screen (AUTH-02).
 *
 * The current password is required so a borrowed session cannot be turned into
 * a permanent account takeover. Errors are rendered both as a summary (for
 * screen readers and for the "what went wrong" glance) and per field, with
 * `aria-invalid`/`aria-describedby` wiring so the message is announced with the
 * input it belongs to.
 *
 * Passwords are never repopulated, never logged and never echoed.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed> $user
 * @var array<string, string> $errors
 * @var bool $saved
 * @var array{min_length: int, max_length: int, require_symbol: bool} $policy
 */

$error = static fn (string $field): ?string => $errors[$field] ?? null;
$describedBy = static fn (string $field): string => $error($field) !== null ? ' aria-invalid="true" aria-describedby="' . $field . '-error"' : '';
?>

<section class="hero">
    <p class="eyebrow">Account · Security</p>
    <h1>Change password</h1>
    <p class="lede">
        You are signed in as <?= $e($user['email'] ?? '') ?>.
        Changing the password signs out every form already open in this browser and issues a new session.
    </p>
</section>

<?php if ($saved): ?>
    <p class="alert alert-ok" role="status">
        Password changed. Your session was refreshed for security — use the new password next time you sign in.
    </p>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <p><strong>We could not change the password.</strong></p>
        <ul class="alert-list">
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="card">
    <header class="card-head">
        <h2>Password</h2>
        <span class="badge"><?= $e($user['role_name'] ?? '') ?></span>
    </header>

    <form method="post" action="<?= $e($route('account.password.update')) ?>" novalidate>
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Current password</span>
                <input
                    type="password"
                    name="current_password"
                    id="current_password"
                    autocomplete="current-password"
                    maxlength="200"
                    required
                    autofocus
                    <?= $describedBy('current_password') ?>
                >
                <?php if ($error('current_password') !== null): ?>
                    <span class="field-hint field-hint--error" id="current_password-error"><?= $e($error('current_password')) ?></span>
                <?php endif; ?>
            </label>

            <label class="field">
                <span>New password</span>
                <input
                    type="password"
                    name="password"
                    id="password"
                    autocomplete="new-password"
                    minlength="<?= $e($policy['min_length']) ?>"
                    maxlength="<?= $e($policy['max_length']) ?>"
                    required
                    <?= $describedBy('password') ?>
                >
                <?php if ($error('password') !== null): ?>
                    <span class="field-hint field-hint--error" id="password-error"><?= $e($error('password')) ?></span>
                <?php endif; ?>
            </label>

            <label class="field">
                <span>Confirm new password</span>
                <input
                    type="password"
                    name="password_confirmation"
                    id="password_confirmation"
                    autocomplete="new-password"
                    minlength="<?= $e($policy['min_length']) ?>"
                    maxlength="<?= $e($policy['max_length']) ?>"
                    required
                >
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="button">Change password</button>
            <a class="button button-ghost" href="<?= $e($route('home')) ?>">Cancel</a>
        </div>
    </form>
</section>

<section class="card">
    <header class="card-head"><h2>Password rules</h2></header>
    <dl class="facts">
        <div>
            <dt>Length</dt>
            <dd><?= $e($policy['min_length']) ?>–<?= $e($policy['max_length']) ?> characters</dd>
        </div>
        <div>
            <dt>Letters</dt>
            <dd>Upper and lower case</dd>
        </div>
        <div>
            <dt>Numbers</dt>
            <dd>At least one digit</dd>
        </div>
        <div>
            <dt>Symbols</dt>
            <dd><?= $policy['require_symbol'] ? 'At least one symbol' : 'Optional, but recommended' ?></dd>
        </div>
    </dl>
    <p class="lede">
        Passwords are stored only as a one-way hash. Nobody — including the system administrator — can read your
        current password, and it is never sent by email.
    </p>
</section>
