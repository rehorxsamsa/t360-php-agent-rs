<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiConfig;
use App\Ai\LlmCallFailed;
use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\PromptLibrary;
use App\Ai\Tools\AgentTool;
use App\Ai\Tools\ReadArticleTool;
use App\Ai\Tools\SearchArticlesTool;
use App\Ai\Tools\ToolResult;
use App\Ai\ToolCall;
use App\Domain\Ai\TokenUsage;

/**
 * 07 – Zeptej se redakce: chat nad obsahem webu s nástroji (tool use, ADR-0008).
 *
 * Smyčku řídí tento příklad, ne klient: model požádá o nástroj (`stop_reason 'tool_use'`), příklad ho vykoná
 * a výsledek pošle zpět jako `tool_result`. Nástroje jsou jen čtecí a znají jen veřejné publikované články;
 * neznámý nástroj nebo špatný vstup je `tool_result` s chybou, nikdy výjimka ani jiná akce. Limity: 5 volání
 * modelu, 3 nástroje na krok, časový rozpočet 60 s (kontroluje se před dalším krokem), výstup nástroje 8 000 znaků.
 * Text článků v `tool_result` je nedůvěryhodný (nepřímá prompt injection), proto nemá žádný dosah mimo odpověď.
 */
final readonly class Example07AskNewsroom implements ExampleDescription
{
    public const string DEMO_QUESTION = 'Co redakce píše o Dockeru?';

    private const int MAX_TOKENS = 1024;
    private const int MIN_QUESTION = 3;
    private const int MAX_QUESTION = 500;
    private const string NO_ANSWER = '(bez odpovědi)';

    /** @var array<string, AgentTool> nástroje podle jména; jediné, které model smí volat */
    private array $tools;

    public function __construct(
        private LlmClient $client,
        private PromptLibrary $prompts,
        private AiConfig $config,
        SearchArticlesTool $search,
        ReadArticleTool $read,
        private int $maxSteps = 5,
        private int $maxToolCallsPerStep = 3,
        private int $timeBudgetMs = 60000,
    ) {
        $this->tools = [$search->name() => $search, $read->name() => $read];
    }

    public function id(): string
    {
        return '07';
    }

    public function title(): string
    {
        return 'Zeptej se redakce';
    }

    public function description(): string
    {
        return 'Chat nad obsahem webu: model si sám vyhledá a přečte publikované články nástroji hledej_clanky a '
            . 'nacti_clanek (tool use, nejvýše 5 kroků, jen čtení) a odpoví se zdroji.';
    }

    /**
     * @throws InvalidExampleInput
     * @throws InvalidModelOutput
     * @throws LlmCallFailed
     * @throws AiBudgetExceeded
     */
    public function ask(string $question, ?int $userId): ExampleResult
    {
        $question = trim($question);
        $length = mb_strlen($question);
        if ($length < self::MIN_QUESTION || $length > self::MAX_QUESTION) {
            throw new InvalidExampleInput('Zadejte otázku (3–500 znaků).');
        }

        $request = new LlmRequest(
            model: $this->config->model,
            system: $this->prompts->system('07-ask-newsroom'),
            messages: [['role' => 'user', 'content' => $question]],
            maxTokens: self::MAX_TOKENS,
            exampleId: $this->id(),
            userId: $userId,
            effort: 'low',
            tools: array_values(array_map(static fn(AgentTool $tool): array => $tool->definition(), $this->tools)),
        );

        $startedAt = hrtime(true);
        $usage = new TokenUsage(0, 0);
        $cost = 0.0;
        $calls = 0;
        $stepFields = [];
        $sources = [];
        $warnings = [];
        $response = null;

        for ($step = 1; $step <= $this->maxSteps; $step++) {
            $response = $this->client->complete($request);
            $calls++;
            $usage = $usage->plus($response->usage);
            $cost += $response->costUsd ?? 0.0;

            if ($response->stopReason !== 'tool_use') {
                break;
            }

            if ($response->toolCalls === []) {
                throw new InvalidModelOutput('Model požádal o nástroj, ale žádný nezadal.');
            }

            $blocks = [];
            foreach ($this->runTools($response->toolCalls) as $index => $result) {
                $call = $response->toolCalls[$index];
                $block = ['type' => 'tool_result', 'tool_use_id' => $call->id, 'content' => $result->content];
                if ($result->isError) {
                    $block['is_error'] = true;
                }
                $blocks[] = $block;

                $stepFields[] = ['label' => sprintf('Krok %d – %s', $step, $call->name), 'value' => $result->summary];
                if ($result->sourceUrl !== null && !in_array($result->sourceUrl, $sources, true)) {
                    $sources[] = $result->sourceUrl;
                }
            }

            if ($step === $this->maxSteps) {
                $warnings[] = sprintf('Agent nedokončil odpověď v limitu %d kroků, odpověď může být neúplná.', $this->maxSteps);

                break;
            }

            if ($this->elapsedMs($startedAt) >= $this->timeBudgetMs) {
                $warnings[] = 'Agent překročil časový limit, odpověď může být neúplná.';

                break;
            }

            // Odpověď modelu se vrací beze změny (včetně bloků thinking se signature), jinak API vrátí 400.
            $messages = $request->messages;
            $messages[] = ['role' => 'assistant', 'content' => $this->assistantContent($response)];
            $messages[] = ['role' => 'user', 'content' => $blocks];
            $request = $request->withMessages($messages);
        }

        if ($response === null) {
            throw new InvalidModelOutput('Model nevrátil žádnou odpověď.');
        }

        $answer = trim($response->text);
        if ($response->stopReason !== 'tool_use') {
            if ($response->stopReason === 'refusal') {
                throw new InvalidModelOutput('Model odmítl odpovědět (refusal).');
            }

            if ($answer === '') {
                throw new InvalidModelOutput('Model vrátil prázdnou odpověď.');
            }

            if ($response->stopReason === 'max_tokens') {
                $warnings[] = 'Odpověď byla useknuta limitem max_tokens.';
            }
        }

        return new ExampleResult(
            $this->id(),
            [
                ['label' => 'Otázka', 'value' => $question],
                ['label' => 'Odpověď', 'value' => $answer === '' ? self::NO_ANSWER : $answer],
                ...$stepFields,
                ['label' => 'Zdroje', 'value' => $sources === [] ? 'Žádné – odpověď nevychází z článků.' : implode("\n", $sources)],
            ],
            $warnings,
            $response->text,
            $usage,
            $cost,
            $response->model,
            $response->provider,
            $calls,
        );
    }

    /**
     * Vykoná požadované nástroje v pořadí požadavku. Nástrojů nad limit kroku se neprovede ani jeden (chyba),
     * stejně jako neznámých; výsledek každého volání je ve stejném pořadí jako volání.
     *
     * @param list<ToolCall> $calls
     * @return list<ToolResult>
     */
    private function runTools(array $calls): array
    {
        $results = [];
        foreach ($calls as $index => $call) {
            if ($index >= $this->maxToolCallsPerStep) {
                $results[] = ToolResult::failure(sprintf('Najednou lze volat nejvýše %d nástroje.', $this->maxToolCallsPerStep));

                continue;
            }

            $tool = $this->tools[$call->name] ?? null;
            if ($tool === null) {
                $results[] = ToolResult::failure(sprintf(
                    'Neznámý nástroj %s. Dostupné jsou jen %s.',
                    mb_substr($call->name, 0, 64),
                    implode(' a ', array_keys($this->tools)),
                ));

                continue;
            }

            $results[] = $tool->run($call->input);
        }

        return $results;
    }

    /**
     * Obsah zprávy `assistant` pro další krok: surové bloky odpovědi beze změny; jen kdyby je klient nepředal,
     * složí se z textu a požadavků na nástroje.
     *
     * @return list<array<string, mixed>>
     */
    private function assistantContent(LlmResponse $response): array
    {
        if ($response->content !== []) {
            return $response->content;
        }

        $blocks = [];
        if ($response->text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $response->text];
        }
        foreach ($response->toolCalls as $call) {
            $blocks[] = ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => $call->input];
        }

        return $blocks;
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
