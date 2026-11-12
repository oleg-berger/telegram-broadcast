<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use Broadcast\Application\TelegramGateway;
use Broadcast\Domain\ApiFailure;

final class CommandMenu
{
    public function install(TelegramGateway $telegram, Config $config): void
    {
        $translations = [
            '' => ['Register or check status', 'Change language', 'Help'],
            'ru' => ['Регистрация или статус заявки', 'Сменить язык', 'Помощь'],
            'en' => ['Register or check status', 'Change language', 'Help'],
            'es' => ['Registro o estado de la solicitud', 'Cambiar idioma', 'Ayuda'],
            'fr' => ['Inscription ou état de la demande', 'Changer de langue', 'Aide'],
        ];
        foreach ($translations as $language => $labels) {
            $commands = [];
            foreach (['start', 'language', 'help'] as $i => $command) { $commands[] = ['command' => $command, 'description' => $labels[$i]]; }
            if ($config->whatsapp !== []) {
                $linkLabels = [
                    '' => ['Connect another messenger', 'Get help connecting accounts'],
                    'ru' => ['Подключить второй мессенджер', 'Помощь с привязкой аккаунта'],
                    'en' => ['Connect another messenger', 'Get help connecting accounts'],
                    'es' => ['Conectar otro mensajero', 'Ayuda para vincular cuentas'],
                    'fr' => ['Associer une autre messagerie', 'Aide pour associer les comptes'],
                ];
                foreach (['link', 'linkhelp'] as $i => $command) {
                    $commands[] = ['command' => $command, 'description' => $linkLabels[$language][$i]];
                }
            }
            $this->call($telegram, $commands, ['type' => 'default'], $language);
        }
        foreach (array_unique([...$config->adminIds, ...$config->superadminIds]) as $id) {
            $super = in_array($id, $config->superadminIds, true);
            $items = ['start' => 'Инструкция', 'help' => 'Помощь', 'draft' => 'Открыть черновик по ID', 'cancel' => 'Отменить ввод', 'retry' => 'Повторить ошибки рассылки'];
            if ($super || $config->adminsCanApproveUsers) { $items['requests'] = 'Заявки пользователей'; }
            if ($super || $config->adminsCanExportUsers) { $items['userdata'] = 'Выгрузить общую базу Excel'; }
            if ($super || $config->adminsCanApproveUsers || $config->adminsCanExportUsers) { $items['retryjob'] = 'Повторить ошибку комментария или Excel'; }
            if ($super) { $items['unsubscribe'] = 'Отписать пользователя'; }
            if ($super && $config->whatsapp !== []) {
                $items['links'] = 'Запросы привязки мессенджеров';
                $items['linkresolve'] = 'Решение по привязке с причиной';
            }
            $commands = [];
            foreach ($items as $command => $description) { $commands[] = compact('command', 'description'); }
            foreach (array_keys($translations) as $language) { $this->call($telegram, $commands, ['type' => 'chat', 'chat_id' => $id], $language); }
        }
    }

    /** Menu installs are a one-shot burst; ride out short Telegram rate limits. */
    private function call(TelegramGateway $telegram, array $commands, array $scope, string $language): void
    {
        $attempts = 0;
        while (true) {
            try {
                $telegram->setCommands($commands, $scope, $language);
                return;
            } catch (ApiFailure $failure) {
                if (!$failure->transient || ++$attempts >= 3) { throw $failure; }
                sleep(max(2, $failure->retryAfter ?? 0));
            }
        }
    }
}
