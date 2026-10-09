<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Output;
use App\Mcp\NewsroomMcpServer;

/**
 * mcp:server – MCP server redakce přes STDIO (příklad 10). Spouští ho Claude Code, ne člověk.
 *
 * Standardní výstup patří výhradně protokolu (řádky JSON-RPC), proto příkaz nepíše nic přes `Output::line()`:
 * informace a chyby jdou jen na stderr. Po zavření vstupu server skončí s kódem 0.
 */
final readonly class McpServerCommand implements Command
{
    private const string USAGE = 'Použití: php bin/konzole mcp:server (MCP server redakce přes STDIO, spouští ho Claude Code – návod na /admin/ai/10)';
    private const string RUNNING = 'MCP server redakce běží na STDIO, ukončíte ho zavřením vstupu.';

    public function __construct(private NewsroomMcpServer $server) {}

    public function run(array $arguments, Output $output): int
    {
        if ($arguments !== []) {
            $output->error(self::USAGE);

            return 1;
        }

        $output->error(self::RUNNING);

        return $this->server->serve(STDIN, STDOUT);
    }
}
