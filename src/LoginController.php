<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The addresses of the admin that need no sign-in: the login screen and a sign-in with a username and password, signing in with
 * Google, signing out, and the first visit to a site with no account, which makes the first administrator. It uses the services
 * that do the work (SignIn, GoogleSignIn, FirstAdmin), each built when first needed, and leaves drawing and redirecting to the
 * site through closures, so nothing here sends output of its own.
 */
final class LoginController
{
    private ?SignIn $signIn = null;
    private ?GoogleSignIn $google = null;
    private ?FirstAdmin $firstAdmin = null;

    /**
     * @param \Closure(): array<string, mixed> $settings the site's settings, as they are now
     * @param \Closure(string): string $absoluteUrl the full address of a path of this site
     * @param \Closure(string, string, ?string, ?string, ?string, array<string, mixed>, ?array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context, actor
     * @param \Closure(string, array<string, mixed>): void $render draws a template of the admin with data
     * @param \Closure(string): void $redirect sends the visitor to a path (and ends the request)
     * @param \Closure(int, array<int, string>): void $respond sets the status of the answer and the header lines to send with it
     * @param (callable(string, array<int, mixed>, string): array{body: mixed, status: int, error: string})|null $googleHttp replaces the calls to Google (for tests)
     */
    public function __construct(
        private Auth $auth,
        private LoginThrottle $throttle,
        private UserRepository $users,
        private NotificationRepository $notifications,
        private \Closure $settings,
        private \Closure $absoluteUrl,
        private \Closure $log,
        private \Closure $render,
        private \Closure $redirect,
        private \Closure $respond,
        private $googleHttp = null
    ) {
    }

    /**
     * Answers the address `/admin/<action>` when it is one that needs no sign-in. False when it is not one, and then nothing was done.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     */
    public function handle(string $action, string $method, array $post, array $get, string $address): bool
    {
        if ($action === 'login' && !$this->auth->check() && $this->firstAdmin()->needed()) {
            $this->setup($method, $post);
            return true;
        }

        if ($action === 'login') {
            if ($this->auth->check() && $method !== 'POST') {
                ($this->redirect)('/admin');
                return true;
            }
            if ($method === 'POST') {
                $this->passwordLogin($post, $address);
                return true;
            }

            $this->renderLogin();
            return true;
        }

        if ($action === 'google-login') {
            $this->googleLogin();
            return true;
        }

        if ($action === 'google-callback') {
            $this->googleCallback($get);
            return true;
        }

        if ($action === 'logout') {
            if ($method !== 'POST') {
                // Signing out changes state, so it only happens through the CSRF-protected form.
                ($this->redirect)($this->auth->check() ? '/admin' : '/admin/login');
                return true;
            }
            ($this->log)('auth.logout', 'info', 'user', (string)($this->auth->user()['username'] ?? ''), 'User signed out.', [], null);
            $this->auth->logout();
            ($this->redirect)('/admin/login');
            return true;
        }

        return false;
    }

    /** The login screen, with a message when there is one to give. */
    public function renderLogin(string $error = ''): void
    {
        ($this->render)('@admin/login.twig', [
            'error' => $error,
            'google_auth' => $this->google()->config(),
        ]);
    }

    /** @param array<string, mixed> $post */
    private function passwordLogin(array $post, string $address): void
    {
        $outcome = $this->signIn()->password(trim((string)($post['username'] ?? '')), (string)($post['password'] ?? ''), $address);
        if ($outcome['status'] === 'blocked') {
            ($this->respond)(429, ['Retry-After: ' . $outcome['retry_after']]);
            $this->renderLogin($outcome['message']);
            return;
        }
        if ($outcome['status'] === 'ok') {
            if ($outcome['default_password']) {
                $_SESSION['security_default_password'] = true;
                $this->notifyDefaultPassword($outcome['username']);
            }
            ($this->redirect)('/admin');
            return;
        }
        $this->renderLogin($outcome['message']);
    }

    private function notifyDefaultPassword(string $username): void
    {
        try {
            $this->notifications->createIfMissing([
                'type' => 'security.default_password',
                'title' => 'Default password in use',
                'body' => $username . ' still signs in with the password shipped in content/users/users.yaml. Change it.',
                'severity' => 'error',
                'target_url' => '/admin/users-edit?id=' . (int)($this->auth->user()['id'] ?? 0),
                'context' => ['username' => $username],
            ]);
        } catch (\Throwable) {
            // Notifications must never block sign-in.
        }
    }

    /**
     * A site with no accounts: the first visit makes the first administrator.
     *
     * @param array<string, mixed> $post
     */
    private function setup(string $method, array $post): void
    {
        $payload = ['username' => '', 'display_name' => '', 'email' => ''];
        $error = '';
        if ($method === 'POST') {
            $result = $this->firstAdmin()->create($post);
            if ($result['ok']) {
                ($this->redirect)('/admin');
                return;
            }
            $payload = $result['payload'];
            $error = $result['error'];
        }
        ($this->render)('@admin/setup.twig', ['error' => $error, 'payload' => $payload]);
    }

    private function googleLogin(): void
    {
        $google = $this->google()->config();
        if (!$google['ready']) {
            $this->renderLogin('Google Sign-In is not configured yet.');
            return;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;
        ($this->redirect)($this->google()->authorizationUrl($google, $state));
    }

    /** @param array<string, mixed> $get */
    private function googleCallback(array $get): void
    {
        $sign = $this->google();
        $google = $sign->config();
        if (!$google['ready']) {
            $this->renderLogin('Google Sign-In is not configured yet.');
            return;
        }

        $state = (string)($get['state'] ?? '');
        if ($state === '' || $state !== (string)($_SESSION['google_oauth_state'] ?? '')) {
            unset($_SESSION['google_oauth_state']);
            $this->renderLogin('Google Sign-In state could not be verified.');
            return;
        }
        unset($_SESSION['google_oauth_state']);

        $code = (string)($get['code'] ?? '');
        if ($code === '') {
            $this->renderLogin('Google did not return an authorization code.');
            return;
        }

        $token = $sign->exchange($code, $google);
        if (!$token['ok']) {
            $this->renderLogin((string)$token['message']);
            return;
        }
        $profile = $sign->profile((string)$token['access_token']);
        if (!$profile['ok']) {
            $this->renderLogin((string)$profile['message']);
            return;
        }
        $accepted = $sign->accept($profile, $google);
        if (!$accepted['ok']) {
            $this->renderLogin($accepted['message']);
            return;
        }

        $email = $accepted['email'];
        $user = $this->users->findByEmail($email);
        if (!$user || !$this->users->isActive($user)) {
            $this->renderLogin('No active GnozioCMS user matches this Google account.');
            return;
        }

        $this->users->linkGoogle((int)$user['id'], $accepted['sub'], $email);
        $user = $this->users->find((int)$user['id']) ?: $user;
        $this->auth->loginUser($user);
        ($this->log)('auth.login_success', 'info', 'user', (string)($user['username'] ?? $email), 'User logged in.', [
            'method' => 'google',
            'email' => $email,
        ], null);
        ($this->redirect)('/admin');
    }

    private function signIn(): SignIn
    {
        return $this->signIn ??= new SignIn($this->auth, $this->throttle, $this->log);
    }

    private function google(): GoogleSignIn
    {
        return $this->google ??= new GoogleSignIn($this->settings, $this->absoluteUrl, $this->googleHttp);
    }

    private function firstAdmin(): FirstAdmin
    {
        return $this->firstAdmin ??= new FirstAdmin(
            $this->users,
            $this->auth,
            fn(string $action, string $level, ?string $type, ?string $id, string $message, array $context) => ($this->log)($action, $level, $type, $id, $message, $context, null)
        );
    }
}
