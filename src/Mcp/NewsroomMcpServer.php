<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Ai\Examples\Example10McpServer;
use App\Ai\Examples\InvalidExampleInput;
use Mcp\Exception\PromptGetException;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Transport\StdioTransport;

/**
 * Adaptér MCP serveru redakce na oficiální SDK `mcp/sdk` (ADR-0011). Je to jediné místo v `src/`, které SDK
 * zná: obsah serveru (nástroje, prompt, instrukce) dodává `Example10McpServer` bez závislosti na SDK, adaptér
 * ho jen zaregistruje a obsluhuje JSON-RPC přes proudy (STDIO). Zprávy čte a odpovědi píše SDK; na standardní
 * výstup se nic jiného nepíše, protože by rozbilo protokol (logy jdou jen na stderr).
 */
final readonly class NewsroomMcpServer
{
    /** Nejdelší přijatý řádek vstupu (1 MiB); delší řádek SDK zahodí. */
    public const int MAX_LINE_BYTES = 1_048_576;

    public function __construct(private Example10McpServer $example) {}

    /** Sestaví server z nástrojů a promptu příkladu 10. Nic nečte ani nepíše, dokud se nespustí. */
    public function build(): Server
    {
        $builder = Server::builder()
            ->setServerInfo(Example10McpServer::SERVER_NAME, Example10McpServer::SERVER_VERSION)
            ->setInstructions($this->example->instructions());

        // Všechny nástroje jsou čtecí a pracují jen s veřejnými daty redakce.
        $annotations = new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false);
        foreach ($this->example->tools() as $tool) {
            $definition = $tool->definition();
            // Schéma nástroje je JSON Schema typu object (jiné konstruktor Tool odmítne výjimkou).
            /** @var array{type: 'object', properties: array<string, mixed>|\stdClass, required: array<string>|null} $schema */
            $schema = $definition['input_schema'];
            $builder->add(
                new Tool($definition['name'], null, $schema, $definition['description'], $annotations),
                $this->toolHandler($definition['name']),
            );
        }

        $topic = new PromptArgument(Example10McpServer::PROMPT_ARGUMENT, $this->example->promptArgumentDescription(), true);
        $builder->add(
            new Prompt(Example10McpServer::PROMPT_NAME, null, $this->example->promptDescription(), [$topic]),
            $this->promptHandler(),
        );

        return $builder->build();
    }

    /**
     * Obslouží protokol nad předanými proudy (řádek = jedna JSON-RPC zpráva) a po vyčerpání vstupu vrátí 0.
     * Proudy po skončení nezavírá, to je věc volajícího (proces je zavře sám).
     *
     * @param resource $input
     * @param resource $output
     */
    public function serve(mixed $input, mixed $output): int
    {
        // Transport SDK by na konci oba proudy zavřel. Zavírání vynecháme (konec relace transport ohlásí už při
        // dočtení vstupu), aby šel výstup přečíst (testy) a proudy patřily volajícímu.
        $transport = new class ($input, $output, maxLineBytes: self::MAX_LINE_BYTES) extends StdioTransport {
            public function close(): void {}
        };

        return $this->build()->run($transport);
    }

    /** Převede volání nástroje na obsah serveru; chyba nástroje je výsledek s `isError`, ne výjimka. */
    private function toolHandler(string $name): ToolHandlerInterface
    {
        return new class ($this->example, $name) implements ToolHandlerInterface {
            public function __construct(
                private readonly Example10McpServer $example,
                private readonly string $name,
            ) {}

            public function execute(array $arguments, ClientGateway $gateway): CallToolResult
            {
                $result = $this->example->callTool($this->name, $arguments);
                $content = [new TextContent($result->content)];
                // Obrana do hloubky: za obsah článků jde druhý blok, že jde o data. Chyby upozornění nepotřebují.
                if (!$result->isError && in_array($this->name, Example10McpServer::CONTENT_NOTICE_TOOLS, true)) {
                    $content[] = new TextContent(Example10McpServer::CONTENT_NOTICE);
                }

                return $result->isError ? CallToolResult::error($content) : CallToolResult::success($content);
            }
        };
    }

    /** Převede `prompts/get` na text promptu; neplatné téma je chyba promptu s českou zprávou. */
    private function promptHandler(): PromptHandlerInterface
    {
        return new class ($this->example) implements PromptHandlerInterface {
            public function __construct(private readonly Example10McpServer $example) {}

            /** @return list<PromptMessage> */
            public function get(array $arguments, ClientGateway $gateway): array
            {
                $topic = $arguments[Example10McpServer::PROMPT_ARGUMENT] ?? null;

                try {
                    $text = $this->example->suggestArticlePrompt(is_string($topic) ? $topic : '');
                } catch (InvalidExampleInput $exception) {
                    throw new PromptGetException($exception->getMessage(), 0, $exception);
                } catch (\Throwable $exception) {
                    error_log(sprintf('MCP server redakce, prompt: %s: %s', $exception::class, $exception->getMessage()));

                    throw new PromptGetException(Example10McpServer::INTERNAL_ERROR, 0, $exception);
                }

                return [new PromptMessage(Role::User, new TextContent($text))];
            }
        };
    }
}
