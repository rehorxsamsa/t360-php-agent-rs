<?php

declare(strict_types=1);

namespace App\Http\View;

/**
 * Vykresluje čisté PHP šablony z jednoho adresáře do rozvržení `layout`.
 * Název šablony je jen malá písmena, číslice, `_`, `-` a `/` (žádné `..`, žádná přípona).
 */
final readonly class TemplateRenderer
{
    public function __construct(private string $directory) {}

    /**
     * @param array<string, mixed> $data proměnné dostupné v šabloně
     * @throws TemplateNotFound neplatný nebo neexistující název šablony
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $data);

        if ($layout === null) {
            return $content;
        }

        // Rozvržení dostane už vykreslené HTML jako $content.
        return $this->renderFile($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $name, array $data): string
    {
        if (preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~', $name) !== 1) {
            throw new TemplateNotFound(sprintf('Neplatný název šablony: %s', $name));
        }

        $file = $this->directory . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new TemplateNotFound(sprintf('Šablona %s neexistuje.', $name));
        }

        $level = ob_get_level();
        ob_start();

        try {
            // Statická closure = šablona nevidí $this ani lokální proměnné rendereru.
            (static function (string $templateFile, array $templateData): void {
                extract($templateData, EXTR_SKIP);
                require $templateFile;
            })($file, $data);

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        }
    }
}
