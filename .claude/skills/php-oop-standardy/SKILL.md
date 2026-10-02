---
name: php-oop-standardy
description: Kódovací standardy projektu pro PHP 8.4 OOP bez frameworku — struktura vrstev, pojmenování, vzory (repository, value object, middleware, DI), ukázky. Načti před psaním nebo revizí PHP kódu.
---

# PHP 8.4 OOP — standardy projektu

## Základ
- `declare(strict_types=1);`, typy všude (parametry, návraty, property), žádné `mixed` bez důvodu.
- `final class` jako výchozí; `readonly` třídy pro entity/VO/DTO. Property promotion v konstruktoru.
- PHP 8.4: property hooks a asymetrická viditelnost (`public private(set)`) používej střídmě a
  jen tam, kde zjednoduší kód; `new` bez závorek v řetězení (`new Foo()->bar()`) je OK.
- Enumy místo konstant (`enum Role: string { case Admin = 'admin'; case Ctenar = 'ctenar'; }`).
- Chyby výjimkami. Vlastní výjimky dědí z `App\Domain\DomenovaVyjimka` nebo `\RuntimeException`.
- PHPStan na **level max** bez baseline. Generika přes PHPDoc (`@return list<Clanek>`).

## Vrstvy a jmenné prostory
```
App\Domain\Clanek\{Clanek, ClanekId, Slug, StavClanku, ClanekRepository(interface)}
App\Application\Clanek\{VytvorClanek, UpravClanek, SmazClanek}   // use-case služby
App\Infrastructure\Persistence\PdoClanekRepository
App\Http\Kontroler\Admin\ClanekKontroler, App\Http\Middleware\*
App\Ai\{LlmKlient, AnthropicKlient, FalesnyKlient, Priklady\*, Prompty\*.md}
```

## Vzory (zkrácené ukázky)
```php
final readonly class Slug
{
    public function __construct(public string $hodnota)
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $hodnota)) {
            throw new NeplatnyVstup('Slug smí obsahovat jen a–z, 0–9 a pomlčky.');
        }
    }
}

interface ClanekRepository
{
    public function najdiPodleSlugu(Slug $slug): ?Clanek;
    /** @return list<Clanek> */
    public function publikovane(int $limit, int $offset): array;
    public function uloz(Clanek $clanek): void;
    public function smaz(ClanekId $id): void;
}

interface Middleware
{
    public function zpracuj(Pozadavek $p, callable $dalsi): Odpoved;
}
```

## Pravidla HTTP
- Front controller `public/index.php` → `Kernel` → router → middleware → kontroler → `Odpoved`.
- Kontroler je tenký: validace vstupu (DTO) → use-case → odpověď/šablona. Žádné SQL.
- PRG po každém úspěšném POST, flash zprávy přes session.
- Mazání jen metodou POST (ne GET odkaz) + potvrzovací krok + CSRF.

## Šablony
- Čisté PHP šablony v `templates/`, rozvržení `layout.php`, žádná logika kromě cyklů a podmínek.
- Helpery: `e(string)`, `e_attr(string)`, `url(string $nazevRouty, array $parametry)`, `csrf_pole()`.

## Testovatelnost
- Závislost na čase přes `Hodiny` (rozhraní), na náhodě přes `GeneratorTokenu`.
- Žádné `new` infrastruktury uvnitř doménových tříd.
