<?php

/**
 * Sign-in form (Phase 7).
 *
 * Every dynamic value is escaped. The password field never receives a value
 * back, and the form re-renders with the submitted email on failure so the user
 * does not have to retype it.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var string $email
 * @var array<string, string> $errors
 * @var string|null $session_expired
 */
?>
<section class="auth-card">
    <header class="auth-card-head">
        <h1>Sign in</h1>
        <p>Use your institutional account to open the scheduler.</p>
    </header>

    <?php if ($session_expired !== null): ?>
        <p class="alert alert-warn">
            Your session ended (<?= $e($session_expired) ?>). Please sign in again.
        </p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $field => $error): ?>
                <p><?= $e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form class="auth-form" method="post" action="<?= $e($route('login.attempt')) ?>" novalidate>
        <?= $csrf_field() ?>

        <div class="field">
            <label for="email">Email address</label>
            <input
                id="email"
                name="email"
                type="email"
                value="<?= $e($email) ?>"
                autocomplete="username"
                inputmode="email"
                maxlength="150"
                required
                autofocus
            >
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                maxlength="200"
                required
            >
        </div>

        <label class="checkbox">
            <input type="checkbox" name="remember" value="1">
            <span>Keep me signed in on this browser for 30 days</span>
        </label>

        <button type="submit" class="button button-block">Sign in</button>
    </form>

    <p class="auth-hint">
        Accounts are created by the scheduling administrator. If you cannot sign in,
        contact the academic scheduling office — passwords are never sent by email.
    </p>
</section>
