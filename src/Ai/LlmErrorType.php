<?php

declare(strict_types=1);

namespace App\Ai;

/** Druh selhání volání AI; hodnoty (snake_case) se ukládají do `ai_calls.error_type`. */
enum LlmErrorType: string
{
    case Configuration = 'configuration';
    case Authentication = 'authentication';
    case Billing = 'billing';
    case Permission = 'permission';
    case InvalidRequest = 'invalid_request';
    case RequestTooLarge = 'request_too_large';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case Overloaded = 'overloaded';
    case Timeout = 'timeout';
    case Transport = 'transport';
    case InvalidResponse = 'invalid_response';

    /** Srozumitelná česká zpráva pro uživatele; nikdy neobsahuje tajemství ani text odpovědi API. */
    public function userMessage(): string
    {
        return match ($this) {
            self::Configuration => 'AI_PROVIDER=anthropic vyžaduje ANTHROPIC_API_KEY v .env.',
            self::Authentication => 'AI odmítla API klíč (401). Zkontrolujte ANTHROPIC_API_KEY v .env.',
            self::Billing => 'AI odmítla požadavek kvůli platbě (402). Zkontrolujte kredit a platební údaje v účtu Anthropic.',
            self::Permission => 'AI klíč nemá oprávnění k tomuto požadavku (403).',
            self::InvalidRequest => 'AI odmítla požadavek jako neplatný (400/404). Zkontrolujte název modelu a délku textu.',
            self::RequestTooLarge => 'Požadavek je pro AI příliš velký (413). Zkraťte text článku.',
            self::RateLimited => 'AI hlásí překročený limit požadavků (429). Zkuste to za chvíli.',
            self::ServerError => 'Služba AI má potíže (chyba serveru 5xx). Zkuste to za chvíli.',
            self::Overloaded => 'Služba AI je přetížená (529). Zkuste to za chvíli.',
            self::Timeout => 'AI neodpověděla včas. Zkuste to znovu nebo zkraťte text.',
            self::Transport => 'Nepodařilo se spojit se službou AI. Zkontrolujte připojení k internetu.',
            self::InvalidResponse => 'Odpověď AI má neočekávaný tvar.',
        };
    }
}
