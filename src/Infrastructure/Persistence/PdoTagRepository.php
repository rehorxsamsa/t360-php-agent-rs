<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Tag\Tag;
use App\Domain\Tag\TagRepository;

final readonly class PdoTagRepository implements TagRepository
{
    public function __construct(private \PDO $pdo) {}

    public function all(): array
    {
        // Řazení podle kolace sloupce (utf8mb4_czech_ci).
        $statement = $this->pdo->prepare('SELECT id, name, slug FROM tags ORDER BY name, id');
        $statement->execute();

        $tags = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)
                || !is_numeric($row['id'] ?? null)
                || !is_string($row['name'] ?? null)
                || !is_string($row['slug'] ?? null)
            ) {
                throw new \UnexpectedValueException('Řádek tabulky tags má neočekávaný tvar.');
            }
            $tags[] = new Tag((int) $row['id'], $row['name'], $row['slug']);
        }

        return $tags;
    }
}
