<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The screen of a person's own second factor (/admin/second-factor, from their profile): setting it up with a QR code and a code
 * from the app, the recovery codes (shown once), new recovery codes and turning it off (each with a code), and, for the super
 * admin, clearing someone else's when they lost their phone (logged).
 */
final class SecondFactorAdmin
{
    /** The session keys of a secret being set up, and of recovery codes to show once. */
    public const SETUP = 'second_factor_setup';
    public const SHOWN = 'second_factor_codes';

    /** @param callable(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context */
    public function __construct(private SecondFactor $factor, private UserRepository $users, private $log, private string $issuer)
    {
    }

    /**
     * Carries out a submitted action for the signed-in person.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $post
     * @return string where to send the browser
     */
    public function apply(array $actor, array $post): string
    {
        $id = (int)($actor['id'] ?? 0);
        $username = (string)($actor['username'] ?? '');
        $action = (string)($post['action'] ?? '');
        $code = (string)($post['code'] ?? '');
        if ($id <= 0) {
            return '/admin/second-factor?error=account';
        }

        if ($action === 'clear') {
            $target = (int)($post['id'] ?? 0);
            $user = $this->users->find($target);
            if (($actor['role'] ?? '') !== 'superadmin' || $target === $id || !$user) {
                return '/admin/second-factor?error=forbidden';
            }
            $this->factor->disable($target);
            $this->log('users.second_factor_cleared', 'warning', 'user', (string)$target, 'Second factor cleared by the super admin.', ['username' => (string)$user['username'], 'by' => $username]);
            return '/admin/users-edit?id=' . $target . '&second_factor_cleared=1';
        }

        if ($action === 'enable') {
            $secret = (string)($_SESSION[self::SETUP] ?? '');
            $step = $secret === '' || $this->factor->enabled($id) ? null : Totp::verify($secret, $code, $this->factor->now());
            if ($step === null) {
                return '/admin/second-factor?error=code';
            }
            $_SESSION[self::SHOWN] = $this->factor->enable($id, $secret, $step);
            unset($_SESSION[self::SETUP]);
            $this->log('users.second_factor_on', 'info', 'user', (string)$id, 'Second factor turned on.', ['username' => $username]);
            return '/admin/second-factor?on=1';
        }

        if ($action === 'renew' || $action === 'disable') {
            if ($action === 'disable' && $this->factor->requiredFor($actor)) {
                return '/admin/second-factor?error=required';
            }
            if ($this->factor->verify($id, $code) === '') {
                return '/admin/second-factor?error=code';
            }
            if ($action === 'renew') {
                $_SESSION[self::SHOWN] = $this->factor->renewRecoveryCodes($id);
                $this->log('users.second_factor_recovery', 'info', 'user', (string)$id, 'New recovery codes made.', ['username' => $username]);
                return '/admin/second-factor?renewed=1';
            }
            $this->factor->disable($id);
            $this->log('users.second_factor_off', 'warning', 'user', (string)$id, 'Second factor turned off.', ['username' => $username]);
            return '/admin/second-factor?off=1';
        }

        return '/admin/second-factor';
    }

    /**
     * The data of the screen.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function screen(array $actor, array $get, bool $mustSetUp): array
    {
        $id = (int)($actor['id'] ?? 0);
        $status = $this->factor->status($id);
        $setup = null;
        if (!$status['enabled'] && $id > 0) {
            $secret = (string)($_SESSION[self::SETUP] ?? '');
            if (Totp::base32Decode($secret) === null || $secret === '') {
                $secret = $_SESSION[self::SETUP] = Totp::newSecret();
            }
            $uri = Totp::uri($secret, (string)($actor['username'] ?? ''), $this->issuer);
            try {
                $qr = QrCode::encode($uri)->svg(208, 'QR code for your authenticator app');
            } catch (\LengthException) {
                $qr = '';
            }
            $setup = ['key' => trim(chunk_split($secret, 4, ' ')), 'qr' => $qr, 'uri' => $uri];
        }
        $codes = $_SESSION[self::SHOWN] ?? [];
        unset($_SESSION[self::SHOWN]);
        $errors = [
            'code' => 'That code is not right. Use the newest code from your app (or, for new codes and turning it off, a recovery code).',
            'required' => 'Your role requires a second factor, so it cannot be turned off.',
            'forbidden' => 'Only the super admin can clear another person\'s second factor.',
            'account' => 'This account cannot have a second factor.',
        ];
        return [
            'status' => $status,
            'setup' => $setup,
            'recovery_codes' => is_array($codes) ? array_values($codes) : [],
            'required' => $this->factor->requiredFor($actor),
            'must_set_up' => $mustSetUp,
            'error' => $errors[(string)($get['error'] ?? '')] ?? '',
            'turned_on' => isset($get['on']),
            'turned_off' => isset($get['off']),
            'renewed' => isset($get['renewed']),
        ];
    }

    /** @param array<string, mixed> $context */
    private function log(string $action, string $level, ?string $type, ?string $id, string $message, array $context): void
    {
        ($this->log)($action, $level, $type, $id, $message, $context);
    }
}
