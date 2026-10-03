<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Category\Category;
use App\Domain\Category\CategoryRepository;

final readonly class PdoCategoryRepository implements CategoryRepository
{
    public function __construct(private \PDO $pdo) {}

    public function all(): array
    {
        // Řazení podle kolace sloupce (utf8mb4_czech_ci).
        $statement = $this->pdo->prepare('SELECT id, name, slug FROM categories ORDER BY name, id');
        $statement->execute();

        $categories = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)
                || !is_numeric($row['id'] ?? null)
                || !is_string($row['name'] ?? null)
                || !is_string($row['slug'] ?? null)
            ) {
                throw new \UnexpectedValueException('Řádek tabulky categories má neočekávaný tvar.');
            }
            $categories[] = new Category((int) $row['id'], $row['name'], $row['slug']);
        }

        return $categories;
    }
}
