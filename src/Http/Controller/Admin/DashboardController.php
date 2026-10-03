<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Auth\AuthSession;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\View\TemplateRenderer;

final readonly class DashboardController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private AuthSession $auth,
        private CsrfToken $csrf,
    ) {}

    public function index(Request $request): Response
    {
        // Obrana do hloubky: přístup hlídá už AdminAccessMiddleware, kontrola je i zde.
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/admin/prihlaseni');
        }

        return Response::html($this->renderer->render('admin/dashboard', [
            'title' => 'Administrace',
            'userName' => $user->displayName,
            'csrfToken' => $this->csrf->token(),
        ]));
    }
}
