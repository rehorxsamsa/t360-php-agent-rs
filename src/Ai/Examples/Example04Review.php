<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiConfig;
use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\PromptData;
use App\Ai\PromptLibrary;

/** 04 – Kontrola před publikací: seznam nálezů a demo obrany proti prompt injection (LLM01). */
final readonly class Example04Review implements AiExample
{
    private const int MAX_TOKENS = 1500;
    private const int MAX_FINDINGS = 20;

    /** @var list<string> */
    private const array TYPES = ['tone', 'personal_data', 'factual_risk', 'prompt_injection'];

    /** @var list<string> */
    private const array SEVERITIES = ['low', 'medium', 'high'];

    private const array TYPE_LABELS = [
        'tone' => 'tón',
        'personal_data' => 'osobní údaje',
        'factual_risk' => 'faktické riziko',
        'prompt_injection' => 'prompt injection',
    ];

    private const array SEVERITY_LABELS = [
        'low' => 'nízká',
        'medium' => 'střední',
        'high' => 'vysoká',
    ];

    public function __construct(
        private LlmClient $client,
        private PromptLibrary $prompts,
        private AiConfig $config,
    ) {}

    public function id(): string
    {
        return '04';
    }

    public function title(): string
    {
        return 'Kontrola před publikací';
    }

    public function description(): string
    {
        return 'Najde problémy v tónu, osobní údaje a faktická rizika. Ukázkový článek s vloženým pokynem předvádí obranu proti prompt injection.';
    }

    public function modelChoices(): array
    {
        return [];
    }

    public function run(ArticleSnapshot $article, ExampleContext $context): ExampleResult
    {
        $request = new LlmRequest(
            model: $this->config->model,
            system: $this->prompts->system('04-review'),
            messages: [['role' => 'user', 'content' => PromptData::article($article) . "\n\nÚkol: zkontroluj článek před publikací."]],
            maxTokens: self::MAX_TOKENS,
            exampleId: $this->id(),
            userId: $context->userId,
            effort: 'low',
            jsonSchema: self::schema(),
        );

        $outcome = new StructuredCall($this->client)->run($request, $this->validate(...));

        $fields = [['label' => 'Shrnutí', 'value' => FieldRules::stringValue($outcome->data, 'summary')]];
        $findings = is_array($outcome->data['findings'] ?? null) ? $outcome->data['findings'] : [];
        foreach (array_values($findings) as $index => $finding) {
            $finding = is_array($finding) ? $finding : [];
            $type = FieldRules::stringValue($finding, 'type');
            $severity = FieldRules::stringValue($finding, 'severity');
            $fields[] = [
                'label' => sprintf(
                    'Nález %d – %s, %s',
                    $index + 1,
                    self::TYPE_LABELS[$type] ?? $type,
                    self::SEVERITY_LABELS[$severity] ?? $severity,
                ),
                'value' => sprintf('„%s“ – %s', FieldRules::stringValue($finding, 'quote'), FieldRules::stringValue($finding, 'note')),
            ];
        }

        if ($findings === []) {
            $fields[] = ['label' => 'Nálezy', 'value' => 'Bez nálezů.'];
        }

        $last = $outcome->last();

        return new ExampleResult(
            $this->id(),
            $fields,
            [],
            $outcome->rawOutput(),
            $outcome->usage(),
            $outcome->costUsd(),
            $last->model,
            $last->provider,
            $outcome->calls(),
        );
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'Shrnutí kontroly, nejvýše 500 znaků'],
                'findings' => [
                    'type' => 'array',
                    'description' => 'Nálezy (nejvýše 20); prázdný seznam, když nic nenajdeš',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => self::TYPES],
                            'severity' => ['type' => 'string', 'enum' => self::SEVERITIES],
                            'quote' => ['type' => 'string', 'description' => 'Citace z článku, nejvýše 200 znaků'],
                            'note' => ['type' => 'string', 'description' => 'Vysvětlení česky, nejvýše 300 znaků'],
                        ],
                        'required' => ['type', 'severity', 'quote', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'findings'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private function validate(array $data): array
    {
        $summaryError = FieldRules::string($data, 'summary', 1, 500);
        $errors = $summaryError === null ? [] : [$summaryError];

        $findings = $data['findings'] ?? null;
        if (!is_array($findings) || !array_is_list($findings)) {
            return [...$errors, 'findings: musí být seznam nálezů'];
        }

        if (count($findings) > self::MAX_FINDINGS) {
            $errors[] = sprintf('findings: nejvýše %d nálezů (je %d)', self::MAX_FINDINGS, count($findings));
        }

        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) {
                $errors[] = sprintf('findings[%d]: musí být objekt', $index);
                continue;
            }

            foreach ([
                FieldRules::oneOf($finding, 'type', self::TYPES),
                FieldRules::oneOf($finding, 'severity', self::SEVERITIES),
                FieldRules::string($finding, 'quote', 0, 200),
                FieldRules::string($finding, 'note', 0, 300),
            ] as $error) {
                if ($error !== null) {
                    $errors[] = sprintf('findings[%d].%s', $index, $error);
                }
            }
        }

        return $errors;
    }
}
