<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Ai\Examples\ExampleResult;

/**
 * Výsledek AI příkladu přežije jedno přesměrování (PRG) a zobrazí se jen jednou.
 * Obnovení stránky tak nikdy nespustí placené volání znovu. Data ze session jsou
 * nedůvěryhodná: poškozený JSON nebo jiný tvar se zahodí.
 */
final readonly class ExampleResultStash
{
    private const string KEY = 'ai_result';

    public function __construct(private Session $session) {}

    /**
     * Uloží výsledek i volby formuláře, se kterými vznikl (po PRG se jimi formulář předvyplní).
     *
     * @param string $article zdroj článku z formuláře (`demo`, `demo-injection` nebo ID)
     * @param string $model zvolený model ('' = výchozí / příklad volbu modelu nemá)
     */
    public function put(ExampleResult $result, string $article = '', string $model = ''): void
    {
        $this->session->set(self::KEY, json_encode(
            ['result' => $result->toArray(), 'article' => $article, 'model' => $model],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** Vytáhne a smaže výsledek; výsledek jiného příkladu nebo poškozená data → null (a smazat). */
    public function pull(string $exampleId): ?StashedExampleResult
    {
        $stored = $this->session->pull(self::KEY);
        if (!is_string($stored)) {
            return null;
        }

        try {
            $data = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !is_array($data['result'] ?? null)) {
            return null;
        }

        $article = $data['article'] ?? null;
        $model = $data['model'] ?? null;
        if (!is_string($article) || !is_string($model)) {
            return null;
        }

        $result = ExampleResult::fromArray($data['result']);
        if ($result === null || $result->exampleId !== $exampleId) {
            return null;
        }

        return new StashedExampleResult($result, $article, $model);
    }
}
