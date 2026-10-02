---
paths:
  - "database/**"
---
# Pravidla pro databázi
- Commitnutou migraci neměnit, psát novou. Každá migrace má `up` i `down`.
- `utf8mb4_czech_ci`, InnoDB, cizí klíče s explicitním `ON DELETE`.
- Do seedů nikdy reálná hesla ani osobní údaje.
