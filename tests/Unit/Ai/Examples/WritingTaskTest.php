<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\WritingAction;
use App\Ai\Examples\WritingTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 008, AC 13: vstup asistenta psaní (akce a text). */
final class WritingTaskTest extends TestCase
{
    public function test_action_values_are_form_and_cli_contract(): void
    {
        self::assertSame(
            ['pokracuj' => 'Pokračovat v textu', 'zkrat' => 'Zkrátit', 'zjednodus' => 'Zjednodušit'],
            array_combine(
                array_map(static fn(WritingAction $action): string => $action->value, WritingAction::cases()),
                array_map(static fn(WritingAction $action): string => $action->label(), WritingAction::cases()),
            ),
        );
        foreach (WritingAction::cases() as $action) {
            self::assertNotSame('', trim($action->instruction()));
        }
    }

    /** @return iterable<string, array{string, WritingAction}> */
    public static function validActions(): iterable
    {
        yield 'pokracuj' => ['pokracuj', WritingAction::Continue];
        yield 'zkrat' => ['zkrat', WritingAction::Shorten];
        yield 'zjednodus' => ['zjednodus', WritingAction::Simplify];
    }

    #[DataProvider('validActions')]
    public function test_valid_input_is_trimmed(string $action, WritingAction $expected): void
    {
        $task = WritingTask::fromInput($action, "  \n Odstavec textu. \n ");

        self::assertSame($expected, $task->action);
        self::assertSame('Odstavec textu.', $task->text);
    }

    public function test_text_of_exactly_5000_characters_is_accepted(): void
    {
        $text = str_repeat('ž', 5000);

        self::assertSame($text, WritingTask::fromInput('zkrat', $text)->text);
        self::assertSame(5000, WritingTask::MAX_LENGTH);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty action' => ['', 'Text.', 'Vyberte akci.'];
        yield 'unknown action' => ['xyz', 'Text.', 'Vyberte akci.'];
        yield 'action in other case' => ['ZKRAT', 'Text.', 'Vyberte akci.'];
        yield 'empty text' => ['zkrat', '', 'Zadejte text.'];
        yield 'whitespace text' => ['zkrat', " \n\t ", 'Zadejte text.'];
        yield 'text 5001 characters' => ['zkrat', str_repeat('ž', 5001), 'Text je pro asistenta příliš dlouhý (max. 5 000 znaků).'];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected_with_czech_message(string $action, string $text, string $message): void
    {
        $this->expectException(InvalidExampleInput::class);
        $this->expectExceptionMessage($message);

        WritingTask::fromInput($action, $text);
    }
}
