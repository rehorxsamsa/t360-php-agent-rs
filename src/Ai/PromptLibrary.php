<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Verzované systémové prompty ze souborů `src/Ai/Prompts/*.md` (žádné prompty rozházené v kódu).
 */
final readonly class PromptLibrary
{
    public function __construct(private string $directory) {}

    /**
     * @param string $name jméno souboru bez přípony, např. `01-excerpt`
     * @throws \RuntimeException neplatné jméno nebo chybějící či prázdný soubor
     */
    public function system(string $name): string
    {
        if (preg_match('/^[0-9]{2}-[a-z-]+$/', $name) !== 1) {
            throw new \RuntimeException('Neplatné jméno promptu.');
        }

        $path = $this->directory . '/' . $name . '.md';
        $content = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException(sprintf('Prompt %s.md neexistuje nebo je prázdný.', $name));
        }

        return $content;
    }
}
