---
name: tutorial-kapitola
description: Šablona a styl kapitol v docs/tutorial.html — struktura krok za krokem, ověřené příkazy, kvíz se skrytými odpověďmi a slovníček. Načti před každou úpravou tutoriálu.
---

# Kapitola tutoriálu

## Struktura každé kapitoly AI příkladu
```html
<section id="ai-NN" class="kapitola">
  <h2>NN. Název příkladu</h2>
  <p class="cil">Co postavíme a proč to v reálné redakci dává smysl (2–3 věty).</p>
  <h3>Jak to funguje</h3>        <!-- Mermaid diagram v <pre class="mermaid"> -->
  <h3>Krok 1 – …</h3> … <h3>Krok N – …</h3>   <!-- kód + vysvětlení řádek po řádku -->
  <h3>Spusť to</h3>              <!-- CLI příkaz + URL v administraci + očekávaný výstup -->
  <h3>Kolik to stojí</h3>        <!-- tokeny a odhad ceny z ai_volani -->
  <h3>Bezpečnost</h3>            <!-- které riziko řešíme a jak -->
  <h3>Jak to postavili agenti</h3> <!-- který agent, jaký prompt, co hook zachytil -->
  <h3>Vyzkoušej sám</h3>         <!-- 2–3 úkoly -->
  <details class="kviz"><summary>Kvíz (3 otázky)</summary> … odpovědi ve vnořeném <details> … </details>
  <dl class="slovnicek"> pojmy kapitoly </dl>
</section>
```

## Styl
- Česky, tykání, krátké věty, aktivní slovesa. Žádný marketing.
- Každý příkaz ověřen spuštěním. Výstupy zkrácené, ale skutečné.
- Kód s komentáři jen tam, kde vysvětlují *proč*.
- Odkazy na soubory relativně k repu (`src/Ai/Priklady/Priklad01Perex.php`).
- Nepoužívej externí skripty kromě Mermaid z cdnjs/jsdelivr (CSP tutoriálu to dovoluje).
