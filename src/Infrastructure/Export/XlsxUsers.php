<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Export;

use Broadcast\Application\UserExporter;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

final class XlsxUsers implements UserExporter
{
    public function export(array $users): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bot-users-');
        $options = new Options();
        $options->setColumnWidth(24, 1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13);
        $options->setColumnWidth(36, 6);
        $writer = new Writer($options);
        try {
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Пользователи');
            $sheet->setSheetView((new SheetView())->setFreezeRow(2));
            $sheet->setAutoFilter(new AutoFilter(0, 1, 12, count($users) + 1));
            $style = (new Style())->setFontBold()->setBackgroundColor('DCEEF0');
            $writer->addRow(Row::fromValues(['ID пользователя', 'Имя', 'Страна', 'Организация', 'Телефон', 'Email', 'Язык', 'Статус', 'Дата подачи (UTC)', 'Дата одобрения (UTC)', 'Telegram ID', 'WhatsApp ID', 'Подтверждённый номер WhatsApp'], $style));
            foreach ($users as $user) {
                $values = [$user['id'], $user['name'], $user['country'], $user['company'], $user['phone'], $user['email'], $user['language'], $user['status'], $user['submitted_at'] ? gmdate('Y-m-d H:i:s', $user['submitted_at']) : '', $user['approved_at'] ? gmdate('Y-m-d H:i:s', $user['approved_at']) : '', $user['telegram_id'] ?? '', $user['whatsapp_id'] ?? '', $user['whatsapp_verified_phone'] ?? ''];
                $writer->addRow(new Row(array_map(static fn ($value) => new StringCell((string) $value, null), $values)));
            }
            $writer->close();
            return $path;
        } catch (\Throwable $exception) {
            if (is_file($path)) { unlink($path); }
            throw $exception;
        }
    }
}
