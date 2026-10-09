<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Ai\Examples\Example10McpServer;
use App\Http\Auth\AuthSession;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\View\TemplateRenderer;

/**
 * Administrace: AI příklad 10 – MCP server redakce (plán 011, ADR-0011). Jen informační stránka:
 * návod k připojení z Claude Code, nástroje se vstupními schématy a náhled promptu `navrhni_clanek`.
 *
 * Stránka nemá formulář ani akci (POST /admin/ai/10 končí 404), nevolá žádné AI API ani databázi –
 * schémata a prompt se skládají z {@see Example10McpServer} bez čtení článků.
 */
final readonly class McpServerController
{
    private const string LOGIN_PATH = '/admin/prihlaseni';
    private const int SCHEMA_JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private TemplateRenderer $renderer,
        private Example10McpServer $example,
        private AuthSession $auth,
        private CsrfToken $csrf,
    ) {}

    /** @throws \JsonException schéma nástroje nejde zakódovat (chyba programu, ne vstupu) */
    public function show(Request $request): Response
    {
        // Obrana do hloubky: přístup hlídá už AdminAccessMiddleware, kontrola je i zde.
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $tools = [];
        foreach ($this->example->tools() as $tool) {
            $definition = $tool->definition();
            $tools[] = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'schema' => json_encode($definition['input_schema'], self::SCHEMA_JSON_FLAGS),
            ];
        }

        return Response::html($this->renderer->render('admin/ai/mcp-server', [
            'title' => sprintf('%s – %s', $this->example->id(), $this->example->title()),
            'example' => $this->example,
            'connectCommand' => Example10McpServer::CONNECT_COMMAND,
            'listCommand' => Example10McpServer::LIST_COMMAND,
            'connectDirectory' => Example10McpServer::CONNECT_DIRECTORY,
            'usageWarning' => Example10McpServer::USAGE_WARNING,
            'tools' => $tools,
            'promptName' => Example10McpServer::PROMPT_NAME,
            'promptDescription' => $this->example->promptDescription(),
            'promptArgument' => Example10McpServer::PROMPT_ARGUMENT,
            'promptArgumentDescription' => $this->example->promptArgumentDescription(),
            'slashCommand' => sprintf('/mcp__%s__%s docker', Example10McpServer::SERVER_NAME, Example10McpServer::PROMPT_NAME),
            'demoTopic' => Example10McpServer::DEMO_TOPIC,
            'promptPreview' => $this->example->suggestArticlePrompt(Example10McpServer::DEMO_TOPIC),
            'instructions' => $this->example->instructions(),
            'csrfToken' => $this->csrf->token(),
        ]));
    }
}
