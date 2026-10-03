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
- Enumy místo konstant (`enum Role: string { case Admin = 'admin'; case Reader = 'reader'; }`).
- Chyby výjimkami. Vlastní výjimky dědí z `App\Domain\DomainException` nebo `\RuntimeException`.
- PHPStan na **level max** bez baseline. Generika přes PHPDoc (`@return list<Article>`).
- Identifikátory jsou anglicky (ADR-0003); česky jen texty UI, URL, komentáře a zamčené kontrakty.

## Vrstvy a jmenné prostory
```
App\Domain\Article\{Article, ArticleId, Slug, ArticleStatus, ArticleRepository(interface)}
App\Application\Article\{CreateArticle, UpdateArticle, DeleteArticle}   // use-case služby
App\Infrastructure\Persistence\PdoArticleRepository
App\Http\Controller\Admin\ArticleController, App\Http\Middleware\*
App\Ai\{LlmClient, AnthropicClient, FakeLlmClient, Examples\*, Prompts\*.md}
```

## Vzory (zkrácené ukázky)
```php
final readonly class Slug
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            throw new InvalidInput('Slug smí obsahovat jen a–z, 0–9 a pomlčky.');
        }
    }
}

interface ArticleRepository
{
    public function findBySlug(Slug $slug): ?Article;
    /** @return list<Article> */
    public function published(int $limit, int $offset): array;
    public function save(Article $article): void;
    public function delete(ArticleId $id): void;
}

interface Middleware
{
    public function process(Request $request, callable $next): Response;
}
```

## Pravidla HTTP
- Front controller `public/index.php` → `Kernel` → router → middleware → controller → `Response`.
- Controller je tenký: validace vstupu (DTO) → use-case → odpověď/šablona. Žádné SQL.
- PRG po každém úspěšném POST, flash zprávy přes session.
- Mazání jen metodou POST (ne GET odkaz) + potvrzovací krok + CSRF.

## Šablony
- Čisté PHP šablony v `templates/`, rozvržení `layout.php`, žádná logika kromě cyklů a podmínek.
- Helpery: `e(string)`, `e_attr(string)`, `url(string $routeName, array $parameters)`, `csrf_field()`.

## Testovatelnost
- Závislost na čase přes `Clock` (rozhraní), na náhodě přes `TokenGenerator`.
- Žádné `new` infrastruktury uvnitř doménových tříd.
