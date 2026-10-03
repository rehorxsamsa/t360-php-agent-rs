<?php

declare(strict_types=1);

use App\Infrastructure\Seed\Seed;

/**
 * Ukázková data (plán 004, §5): 3 rubriky, 5 štítků, 16 článků ve všech stavech.
 *
 * Doplňuje jen chybějící záznamy podle slugu, nic nemaže ani nepřepisuje, takže je
 * opakované spuštění bezpečné. Žádné osobní údaje, `created_by`/`updated_by` zůstávají NULL.
 * Obsah je záměrně česky (testovací data), identifikátory anglicky.
 */
return new class implements Seed {
    /** @var list<array{0: string, 1: string}> slug => název rubriky */
    private const array CATEGORIES = [
        ['zpravy', 'Zprávy'],
        ['technologie', 'Technologie'],
        ['veda-a-vyzkum', 'Věda a výzkum'],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const array TAGS = [
        ['php', 'PHP'],
        ['docker', 'Docker'],
        ['bezpecnost', 'Bezpečnost'],
        ['umela-inteligence', 'Umělá inteligence'],
        ['pristupnost', 'Přístupnost'],
    ];

    public function run(\PDO $pdo): array
    {
        /** @var array<string, int> $categoryIds slug => id */
        $categoryIds = [];
        $newCategories = 0;
        foreach (self::CATEGORIES as [$slug, $name]) {
            $id = $this->findId($pdo, 'categories', $slug);
            if ($id === null) {
                $stmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (:name, :slug)');
                $stmt->execute(['name' => $name, 'slug' => $slug]);
                $id = (int) $pdo->lastInsertId();
                ++$newCategories;
            }
            $categoryIds[$slug] = $id;
        }

        /** @var array<string, int> $tagIds slug => id */
        $tagIds = [];
        $newTags = 0;
        foreach (self::TAGS as [$slug, $name]) {
            $id = $this->findId($pdo, 'tags', $slug);
            if ($id === null) {
                $stmt = $pdo->prepare('INSERT INTO tags (name, slug) VALUES (:name, :slug)');
                $stmt->execute(['name' => $name, 'slug' => $slug]);
                $id = (int) $pdo->lastInsertId();
                ++$newTags;
            }
            $tagIds[$slug] = $id;
        }

        $newArticles = 0;
        foreach ($this->articles() as $article) {
            $articleId = $this->findId($pdo, 'articles', $article['slug']);
            if ($articleId === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO articles (category_id, title, slug, excerpt, body, status, published_at)
                     VALUES (:category_id, :title, :slug, :excerpt, :body, :status, :published_at)'
                );
                $stmt->execute([
                    'category_id' => $categoryIds[$article['category']],
                    'title' => $article['title'],
                    'slug' => $article['slug'],
                    'excerpt' => $article['excerpt'],
                    'body' => $article['body'],
                    'status' => $article['status'],
                    'published_at' => $article['published_at'],
                ]);
                $articleId = (int) $pdo->lastInsertId();
                ++$newArticles;
            }

            foreach ($article['tags'] as $tagSlug) {
                $check = $pdo->prepare(
                    'SELECT 1 FROM article_tags WHERE article_id = :article_id AND tag_id = :tag_id'
                );
                $check->execute(['article_id' => $articleId, 'tag_id' => $tagIds[$tagSlug]]);
                if ($check->fetchColumn() === false) {
                    $link = $pdo->prepare(
                        'INSERT INTO article_tags (article_id, tag_id) VALUES (:article_id, :tag_id)'
                    );
                    $link->execute(['article_id' => $articleId, 'tag_id' => $tagIds[$tagSlug]]);
                }
            }
        }

        return ['rubriky' => $newCategories, 'štítky' => $newTags, 'články' => $newArticles];
    }

    /** Tabulka je vždy z pevného seznamu v této třídě, nikdy ze vstupu. */
    private function findId(\PDO $pdo, string $table, string $slug): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @return list<array{slug: string, title: string, category: string, status: string,
     *     published_at: ?string, tags: list<string>, excerpt: string, body: string}>
     */
    private function articles(): array
    {
        return [
            [
                'slug' => 'prvni-clanek',
                'title' => 'Vítejte v nové redakci',
                'category' => 'zpravy',
                'status' => 'published',
                'published_at' => '2026-09-01 08:00:00',
                'tags' => [],
                'excerpt' => 'Redakční systém je v provozu. Krátce popisujeme, co na webu najdete a jak jej budeme rozvíjet.',
                'body' => <<<'MD'
                    Dnes spouštíme nový redakční web. Najdete na něm **zprávy**, články o technologiích a přehledy z vědy a výzkumu.

                    ## Co nás čeká

                    - pravidelné články z rubrik,
                    - ukázky bezpečné práce s obsahem,
                    - postupné přidávání dalších funkcí.

                    Budeme rádi, když se k nám budete vracet.
                    MD,
            ],
            [
                'slug' => 'docker-pro-vyvojare',
                'title' => 'Docker pro vývojáře: proč na něm záleží',
                'category' => 'technologie',
                'status' => 'published',
                'published_at' => '2026-09-02 08:00:00',
                'tags' => ['docker'],
                'excerpt' => 'Kontejnery zjednodušují vývojové prostředí. Podívejte se, jak vypadá běžný denní postup.',
                'body' => <<<'MD'
                    Kontejner zabalí aplikaci i s jejími závislostmi, takže **stejné prostředí** běží u každého člena týmu.

                    ## Typický postup

                    1. naklonujete repozitář,
                    2. spustíte `docker compose up -d`,
                    3. otevřete aplikaci v prohlížeči.

                    Žádné ruční instalace PHP ani databáze na hostitelském stroji nejsou potřeba.
                    MD,
            ],
            [
                'slug' => 'nova-studie-o-spanku',
                'title' => 'Nová studie: spánek ovlivňuje paměť víc, než se čekalo',
                'category' => 'veda-a-vyzkum',
                'status' => 'published',
                'published_at' => '2026-09-03 08:00:00',
                'tags' => [],
                'excerpt' => 'Ukázkový text o vědecké studii spánku. Data jsou vymyšlená a slouží jen k předvedení vzhledu webu.',
                'body' => <<<'MD'
                    Výzkumný tým v **ukázkovém příběhu** sledoval dobrovolníky po dobu několika týdnů. Ti, kdo spali pravidelně, si lépe pamatovali nové informace.

                    > Pravidelnost je důležitější než samotná délka spánku.

                    Studie je smyšlená, slouží jen jako ukázka textu v rubrice Věda a výzkum.
                    MD,
            ],
            [
                'slug' => 'pristupnost-webu-v-praxi',
                'title' => 'Přístupnost webu v praxi',
                'category' => 'technologie',
                'status' => 'published',
                'published_at' => '2026-09-04 08:00:00',
                'tags' => ['pristupnost'],
                'excerpt' => 'Několik jednoduchých zásad, díky kterým je web použitelný pro všechny čtenáře včetně čteček obrazovky.',
                'body' => <<<'MD'
                    Přístupný web se obejde bez složitých triků. Stačí dodržet několik základních zásad.

                    ## Základní pravidla

                    - používejte **sémantické** značky (nadpisy, seznamy, `nav`, `main`),
                    - zajistěte dostatečný kontrast textu,
                    - ovládání musí jít projít klávesnicí,
                    - odkaz „Přejít na obsah“ ušetří uživatelům čtečky spoustu kroků.

                    Přístupnost prospívá všem, nejen lidem s handicapem.
                    MD,
            ],
            [
                'slug' => 'jak-funguje-hashovani-hesel',
                'title' => 'Jak funguje hashování hesel',
                'category' => 'technologie',
                'status' => 'published',
                'published_at' => '2026-09-05 08:00:00',
                'tags' => ['bezpecnost', 'php'],
                'excerpt' => 'Hesla se nikdy neukládají v čitelné podobě. Vysvětlujeme, proč a čím je nahradit.',
                'body' => <<<'MD'
                    Kdyby útočník získal databázi s hesly v čitelné podobě, mohl by se přihlásit kamkoli. Proto se ukládá jen **otisk** (hash).

                    V PHP k tomu slouží funkce `password_hash()` a `password_verify()`:

                    ```php
                    $hash = password_hash($password, PASSWORD_ARGON2ID);
                    $ok = password_verify($password, $hash);
                    ```

                    Algoritmus je záměrně pomalý a ke každému heslu přidává náhodnou sůl.
                    MD,
            ],
            [
                'slug' => 'jazykove-modely-v-redakci',
                'title' => 'Jazykové modely v redakci: pomocník, ne autor',
                'category' => 'veda-a-vyzkum',
                'status' => 'published',
                'published_at' => '2026-09-06 08:00:00',
                'tags' => ['umela-inteligence'],
                'excerpt' => 'Umělá inteligence umí navrhnout perex nebo štítky. Výsledek ale vždy musí zkontrolovat člověk.',
                'body' => <<<'MD'
                    Jazykové modely dokážou **shrnout** text, navrhnout titulek nebo doporučit štítky.

                    ## Pravidla pro bezpečné použití

                    - výstup modelu je nedůvěryhodný vstup,
                    - nikdy se nevykonává a vždy se escapuje,
                    - o zveřejnění rozhoduje člověk.

                    Teprve kombinace automatizace a lidské kontroly dává smysluplný výsledek.
                    MD,
            ],
            [
                'slug' => 'kvantove-pocitace-bez-mysticismu',
                'title' => 'Kvantové počítače bez mysticismu',
                'category' => 'veda-a-vyzkum',
                'status' => 'published',
                'published_at' => '2026-09-07 08:00:00',
                'tags' => [],
                'excerpt' => 'Co kvantové počítače opravdu umějí a co zatím zůstává jen příslibem.',
                'body' => <<<'MD'
                    Kvantový počítač nenahradí ten běžný. Hodí se na **úzkou skupinu úloh**, například simulaci molekul.

                    Kvantové bity mohou být ve stavu 0 i 1 současně, což se ale v praxi velmi obtížně udržuje.

                    Zatím jde především o výzkum, praktické nasazení je otázkou dalších let.
                    MD,
            ],
            [
                'slug' => 'mestska-knihovna-prodlouzila-otevreci-dobu',
                'title' => 'Městská knihovna prodloužila otevírací dobu',
                'category' => 'zpravy',
                'status' => 'published',
                'published_at' => '2026-09-08 08:00:00',
                'tags' => [],
                'excerpt' => 'Ukázková zpráva: knihovna v podvečer otevírá o dvě hodiny déle. Údaje jsou vymyšlené.',
                'body' => <<<'MD'
                    Městská knihovna od tohoto týdne otevírá **o dvě hodiny déle**. Změnu uvítali zejména studenti a lidé, kteří pracují do odpoledne.

                    Nová otevírací doba platí od pondělí do pátku. O víkendech zůstává beze změn.

                    Jde o smyšlenou zprávu určenou k předvedení vzhledu webu.
                    MD,
            ],
            [
                'slug' => 'php-84-nove-vlastnosti',
                'title' => 'PHP 8.4: nové vlastnosti jazyka',
                'category' => 'technologie',
                'status' => 'published',
                'published_at' => '2026-09-09 08:00:00',
                'tags' => ['php'],
                'excerpt' => 'Přehled novinek, které se v praxi hodí nejvíc: hooky vlastností a asymetrická viditelnost.',
                'body' => <<<'MD'
                    Verze 8.4 přináší několik užitečných novinek.

                    ## Hlavní změny

                    - **hooky vlastností** (`get` a `set` přímo u vlastnosti),
                    - asymetrická viditelnost (veřejné čtení, soukromý zápis),
                    - typované konstanty tříd.

                    Více se dočtete na [oficiálním webu PHP](https://www.php.net/).
                    MD,
            ],
            [
                'slug' => 'recyklace-plastu-nove-metody',
                'title' => 'Recyklace plastů: nové metody třídění',
                'category' => 'veda-a-vyzkum',
                'status' => 'published',
                'published_at' => '2026-09-10 08:00:00',
                'tags' => [],
                'excerpt' => 'Ukázkový text o třídění plastového odpadu. Čísla a instituce jsou smyšlené.',
                'body' => <<<'MD'
                    Třídění plastů je jedním z nejnáročnějších kroků recyklace. Nové postupy využívají **kamery a strojové učení**.

                    Ukázkový výzkumný tým uvádí, že metoda rozpozná druh plastu během zlomku sekundy.

                    Text je vymyšlený a slouží jen jako testovací obsah.
                    MD,
            ],
            [
                'slug' => 'sablony-a-escapovani',
                'title' => 'Šablony & escapování: <script> se nespustí',
                'category' => 'technologie',
                'status' => 'published',
                'published_at' => '2026-09-11 08:00:00',
                'tags' => ['bezpecnost', 'php'],
                'excerpt' => 'Proč se výstup do HTML vždy escapuje a co se stane, když ve vstupu najdeme značku script.',
                'body' => <<<'MD'
                    Každý text od uživatele je **nedůvěryhodný**. Než se vypíše do HTML, převedou se znaky `<`, `>`, `&` a uvozovky na entity.

                    Díky tomu se i titulek, který obsahuje značku script, zobrazí jako obyčejný text a nespustí se.

                    ## Zásady

                    - escapovat vždy při výstupu, ne při ukládání,
                    - v šabloně používat jediný pomocný nástroj,
                    - výjimky pečlivě označit a zdůvodnit.
                    MD,
            ],
            [
                'slug' => 'ukazka-markdownu',
                'title' => 'Ukázka Markdownu',
                'category' => 'technologie',
                'status' => 'published',
                'published_at' => '2026-09-12 08:00:00',
                'tags' => ['bezpecnost', 'php'],
                'excerpt' => 'Přehled podporované podmnožiny Markdownu včetně dvou útoků, které se vykreslí jen jako text.',
                'body' => <<<'MD'
                    Text článku se píše v **Markdownu**. Podporujeme tučné písmo, *kurzívu* a vložený kód, například `echo "ahoj";`.

                    ## Podnadpis

                    Odrážky:

                    - první položka,
                    - druhá položka,
                    - třetí položka.

                    Číslovaný seznam:

                    1. nejdřív naplánujeme,
                    2. potom napíšeme testy,
                    3. nakonec implementujeme.

                    > Citace: bezpečný web escapuje, než začne značkovat.

                    Blok kódu v PHP:

                    ```php
                    <?php
                    echo htmlspecialchars($title, ENT_QUOTES);
                    ```

                    Odkaz na [dokumentaci PHP](https://www.php.net/) se vykreslí jako odkaz.

                    ## Útoky, které se nespustí

                    Syrové HTML se vypíše jako text: <script>alert("xss")</script>

                    Nebezpečné schéma odkazu se zahodí a zůstane jen text: [nebezpečný odkaz](javascript:alert(1))
                    MD,
            ],
            [
                'slug' => 'rozepsany-koncept',
                'title' => 'Rozepsaný koncept',
                'category' => 'zpravy',
                'status' => 'draft',
                'published_at' => null,
                'tags' => [],
                'excerpt' => 'Tento článek je zatím jen koncept a veřejnost ho nevidí.',
                'body' => <<<'MD'
                    Tento text je **rozpracovaný**. Zatím obsahuje jen pár vět a čeká na dokončení.
                    MD,
            ],
            [
                'slug' => 'druhy-koncept',
                'title' => 'Druhý koncept: umělá inteligence a redaktoři',
                'category' => 'veda-a-vyzkum',
                'status' => 'draft',
                'published_at' => null,
                'tags' => ['umela-inteligence'],
                'excerpt' => 'Nedokončený text o spolupráci redaktorů s jazykovými modely.',
                'body' => <<<'MD'
                    Koncept článku o tom, jak mohou redaktoři využít jazykové modely.

                    - osnova je hotová,
                    - ukázky teprve doplníme.
                    MD,
            ],
            [
                'slug' => 'archivni-clanek',
                'title' => 'Archivní článek: starší zpráva z léta',
                'category' => 'zpravy',
                'status' => 'archived',
                'published_at' => '2026-08-15 08:00:00',
                'tags' => [],
                'excerpt' => 'Starší zpráva, která byla přesunuta do archivu a veřejně už není dostupná.',
                'body' => <<<'MD'
                    Tato zpráva byla uveřejněna v létě a později **archivována**. Na veřejném webu se nezobrazuje.
                    MD,
            ],
            [
                'slug' => 'planovany-clanek',
                'title' => 'Plánovaný článek: zveřejní se v budoucnu',
                'category' => 'zpravy',
                'status' => 'published',
                'published_at' => '2099-01-01 08:00:00',
                'tags' => [],
                'excerpt' => 'Článek má stav publikováno, ale datum zveřejnění je v budoucnosti, takže zatím zůstává skrytý.',
                'body' => <<<'MD'
                    Tento článek je **naplánovaný** na pozdější datum. Dokud ho nenastane, veřejnost ho neuvidí.
                    MD,
            ],
        ];
    }
};
