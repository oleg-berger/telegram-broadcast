<?php
declare(strict_types=1);

namespace Broadcast\Application;

/** Static translations keep registration independent of the translation API. */
final class Messages
{
    public const LANGUAGES = ['RU' => 'Русский', 'EN-GB' => 'English', 'ES' => 'Español', 'FR' => 'Français'];

    private static ?array $texts = null;

    public static function text(string $language, string $key, array $values = []): string
    {
        self::$texts ??= json_decode(file_get_contents(__DIR__ . '/ui.json'), true, flags: JSON_THROW_ON_ERROR);
        $index = array_search($language, array_keys(self::LANGUAGES), true);
        $text = self::$texts[$key][$index === false ? 0 : $index];
        foreach ($values as $name => $value) { $text = str_replace('{' . $name . '}', (string) $value, $text); }
        return $text;
    }

    public static function attachmentDoneKeyboard(int $draftId, int $version): array
    {
        return ['reply_markup' => ['inline_keyboard' => [[[
            'text' => self::text('RU', 'attachment_finish'),
            'callback_data' => 'draft:done:' . $draftId . ':' . $version,
            'style' => 'success',
        ]]]]];
    }

    public static function languageKeyboard(): array
    {
        $buttons = [];
        foreach (self::LANGUAGES as $code => $label) { $buttons[] = ['text' => $label, 'callback_data' => 'language:' . $code]; }
        return ['reply_markup' => ['inline_keyboard' => array_chunk($buttons, 2)]];
    }
}
