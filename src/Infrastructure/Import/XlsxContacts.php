<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Import;

use Broadcast\Domain\PhoneNumber;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

final class XlsxContacts
{
    /** @return array{rows:array,errors:array,warnings:array,total:int,sha256:string} */
    public function read(string $path, array $skipRows = [], array $continuationRows = []): array
    {
        $result = ['rows' => [], 'errors' => [], 'warnings' => [], 'total' => 0, 'sha256' => ''];
        $reader = new Reader();
        try {
            if (!is_file($path) || filesize($path) > 20_000_000) { throw new RuntimeException(); }
            $result['sha256'] = hash_file('sha256', $path);
            $reader->open($path);
            $sheets = 0;
            $seen = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                if (++$sheets > 1) { throw new RuntimeException(); }
                $columns = null;
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $values = $row->toArray();
                    if ($columns === null) {
                        $columns = $this->columns($values);
                        continue;
                    }
                    if (!array_filter($values, static fn ($value) => $value !== null && $value !== '')) { continue; }
                    if (++$result['total'] > 100_000) { throw new RuntimeException(); }
                    if (in_array($number, $skipRows, true)) {
                        $result['warnings'][] = ['row' => $number, 'code' => 'explicitly_skipped'];
                        continue;
                    }
                    $phone = $values[$columns['phone']] ?? '';
                    $language = $values[$columns['language']] ?? '';
                    $email = isset($columns['email']) ? ($values[$columns['email']] ?? '') : '';
                    if (in_array($number, $continuationRows, true)) {
                        $previous = $result['rows'][array_key_last($result['rows'])] ?? null;
                        if ($phone !== '' || $language !== '' || $email !== '' || $previous === null || $previous['row'] !== $number - 1) {
                            $result['errors'][] = ['row' => $number, 'code' => 'invalid_continuation'];
                        } else {
                            $result['warnings'][] = ['row' => $number, 'code' => 'contact_continuation', 'source_row' => $previous['row']];
                        }
                        continue;
                    }
                    $code = null;
                    // Recognize only a literal international number; never evaluate formulas.
                    if (is_string($phone) && preg_match('/^=\+[1-9][0-9]{6,14}$/D', $phone)) {
                        $phone = substr($phone, 1);
                        $result['warnings'][] = ['row' => $number, 'code' => 'literal_phone_formula_normalized'];
                    }
                    if (is_string($phone) && preg_match('/^[1-9][0-9\s()\-]+$/uD', trim($phone))) {
                        $phone = '+' . trim($phone);
                        $result['warnings'][] = ['row' => $number, 'code' => 'missing_plus_normalized'];
                    }
                    if ($phone === '' && $language === '') {
                        $code = 'missing_contact';
                    } elseif (!is_string($phone)) {
                        $code = 'phone_must_be_text';
                    } elseif (!is_string($language) || !is_string($email)) {
                        $code = 'invalid_cell_type';
                    } elseif (str_starts_with(trim($phone), '=') || str_starts_with(trim($email), '=') || str_starts_with(trim($language), '=')) {
                        $code = 'formula_not_allowed';
                    } else {
                        $phone = PhoneNumber::normalize(trim($phone));
                        $language = self::language($language);
                        $email = trim($email);
                        $code = $phone === null ? 'invalid_phone' : ($language === null ? 'invalid_language'
                            : ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) ? 'invalid_email' : null));
                        if ($code === null && isset($seen[$phone])) { $code = 'duplicate_phone'; }
                    }
                    if ($code !== null) {
                        $result['errors'][] = ['row' => $number, 'code' => $code];
                        continue;
                    }
                    $seen[$phone] = true;
                    $result['rows'][] = ['phone' => $phone, 'language' => $language, 'email' => $email === '' ? null : $email, 'row' => $number];
                }
            }
            if ($sheets !== 1) { throw new RuntimeException(); }
        } catch (\Throwable) {
            throw new RuntimeException('Contact workbook could not be read.');
        } finally {
            $reader->close();
        }
        return $result;
    }

    private function columns(array $values): array
    {
        $headers = array_map(static fn ($v) => is_string($v) ? mb_strtolower(trim($v), 'UTF-8') : '', $values);
        // The supplied legacy workbook has unlabeled company/phone/name columns.
        if (array_slice($headers, 0, 5) === ['', '', '', 'язык рассылки', 'страна']) {
            return ['phone' => 1, 'language' => 3] + (isset($headers[5]) && in_array($headers[5], ['email', 'почта'], true) ? ['email' => 5] : []);
        }
        $columns = [];
        foreach ($headers as $index => $header) {
            $field = match ($header) {
                'phone', 'телефон', 'номер телефона' => 'phone',
                'language', 'язык', 'язык рассылки' => 'language',
                'email', 'e-mail', 'почта', 'электронная почта' => 'email',
                default => null,
            };
            if ($field !== null) {
                if (isset($columns[$field])) { throw new RuntimeException(); }
                $columns[$field] = $index;
            }
        }
        if (!isset($columns['phone'], $columns['language'])) { throw new RuntimeException(); }
        return $columns;
    }

    public static function language(string $value): ?string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return match ($value) {
            'ru', 'rus', 'russian', 'русский' => 'RU',
            'en', 'eng', 'en-gb', 'english', 'английский' => 'EN-GB',
            'es', 'esp', 'spanish', 'español', 'испанский' => 'ES',
            'fr', 'fra', 'french', 'français', 'французский' => 'FR',
            default => null,
        };
    }
}
