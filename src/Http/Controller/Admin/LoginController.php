<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Auth\AdminAuthenticator;
use App\Http\Auth\AuthSession;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\Session\Flash;
use App\Http\View\TemplateRenderer;

final readonly class LoginController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private AdminAuthenticator $authenticator,
        private AuthSession $auth,
        private CsrfToken $csrf,
        private Flash $flash,
    ) {}

    public function show(Request $request): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect('/admin');
        }

        return $this->form(200, '', '');
    }

    public function login(Request $request): Response
    {
        $email = $request->input('email');
        $user = $this->authenticator->attempt($email, $request->input('password'), $request->clientIp);

        if ($user === null) {
            return $this->form(422, $email, 'Neplatné přihlašovací údaje.');
        }

        $this->auth->signIn($user);

        return Response::redirect('/admin');
    }

    public function logout(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/admin/prihlaseni');
        }

        $this->authenticator->recordLogout($user, $request->clientIp);
        $this->auth->signOut();
        $this->flash->set('Byli jste odhlášeni.');

        return Response::redirect('/admin/prihlaseni');
    }

    private function form(int $status, string $email, string $error): Response
    {
        return Response::html($this->renderer->render('admin/login', [
            'title' => 'Přihlášení do administrace',
            'csrfToken' => $this->csrf->token(),
            'email' => $email,
            'error' => $error,
            'flash' => $this->flash->pull(),
        ]), $status);
    }
}
