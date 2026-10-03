<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Http\View\TemplateRenderer;

final readonly class HomeController
{
    public function __construct(private TemplateRenderer $renderer) {}

    public function index(Request $request): Response
    {
        return Response::html($this->renderer->render('home', ['title' => 'Úvod']));
    }
}
