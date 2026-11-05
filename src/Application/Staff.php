<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\TextParts;

final class Staff
{
    public function __construct(private Store $store, private array $superadmins, private bool $adminsCanApprove, private bool $adminsCanExport) {}

    private function super(int $id): bool
    {
        return in_array($id, $this->superadmins, true);
    }

    private function approveUsers(int $id): bool
    {
        return $this->super($id) || $this->adminsCanApprove;
    }

    private function reply(int $id, string $text, array $buttons = []): void
    {
        $parts = TextParts::split($text);
        foreach ($parts as $i => $part) {
            $this->store->queueReply($id, $part, $i === count($parts) - 1 && $buttons ? ['reply_markup' => ['inline_keyboard' => $buttons]] : []);
        }
    }

    public function message(int $id, array $message): void
    {
        $text = trim($message['text'] ?? '');
        if ($text === '' || !isset($message['text'])) {
            $this->reply($id, 'Поддерживается только обычный текст. Вложения не рассылаются.');
            return;
        }
        if (str_starts_with($text, '/')) { $this->command($id, $text); return; }
        $session = $this->store->session($id);
        if ($session && str_starts_with($session['kind'], 'app:')) { $this->applicationComment($id, $session, $text); return; }
        if ($session && $session['kind'] === 'draft:return') {
            if (!$this->super($id)) { $this->reply($id, 'Недостаточно прав.'); return; }
            $draft = $this->store->draft($session['id']);
            if (!$draft || $draft['status'] !== 'pending' || $draft['version'] !== $session['version']) { $this->stale($id); return; }
            $this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'changes']);
            $draft = $this->store->draft($draft['id']);
            $buttons = [];
            foreach (['text', 'subject'] as $field) {
                $buttons[] = [['text' => Messages::text('RU', 'draft_edit_' . $field), 'callback_data' => 'draft:' . $field . ':' . $draft['id'] . ':' . $draft['version']]];
            }
            $this->reply($draft['admin_id'], Messages::text('RU', 'draft_returned', ['comment' => $text]), $buttons);
            $this->store->saveSession($id, null);
            $this->reply($id, Messages::text('RU', 'draft_return_sent'));
            return;
        }
        if ($session && in_array($session['kind'], ['draft:subject', 'draft:text'], true)) {
            $draft = $this->store->draft($session['id']);
            if (!$draft || $draft['admin_id'] !== $id || !in_array($draft['status'], ['draft', 'changes'], true) || $draft['version'] !== $session['version']) { $this->stale($id); return; }
            $field = $session['kind'] === 'draft:subject' ? 'subject' : 'text';
            if ($field === 'subject' && (mb_strlen($text) > 200 || preg_match('/[\r\n]/', $text))) {
                $this->reply($id, Messages::text('RU', 'draft_subject_invalid'));
                return;
            }
            $this->store->saveDraft($draft['id'], $draft['version'], [$field => $text]);
            $this->store->saveSession($id, null);
            $this->preview($id, $this->store->draft($draft['id']));
            return;
        }
        $draft = $this->store->createDraft($id, $text);
        $this->store->saveSession($id, ['kind' => 'draft:subject', 'id' => $draft['id'], 'version' => $draft['version']]);
        $this->reply($id, Messages::text('RU', 'draft_subject_prompt', $draft));
    }

    private function command(int $id, string $text): void
    {
        if (preg_match('/^\/retryjob\s+([1-9][0-9]*)$/D', $text, $match)) {
            if (!$this->approveUsers($id) && !$this->adminsCanExport) { $this->reply($id, 'Недостаточно прав.'); return; }
            $this->reply($id, $this->store->retryAuxiliaryJob((int) $match[1], $id, $this->super($id)) ? 'Задание поставлено на повтор.' : 'Задание недоступно или не содержит ошибки.');
            return;
        }
        if (preg_match('/^\/unsubscribe\s+([1-9][0-9]*)$/D', $text, $match)) {
            if (!$this->super($id)) { $this->reply($id, 'Отписка пользователей доступна только суперадминистратору.'); return; }
            $this->reply($id, $this->store->unsubscribeUser((int) $match[1]) ? 'Пользователь отписан от рассылок в Telegram и на почте.' : 'Пользователь не найден среди одобренных.');
            return;
        }
        if (preg_match('/^\/userdata$/iD', $text)) {
            if (!$this->super($id) && !$this->adminsCanExport) { $this->reply($id, 'Выгрузка доступна только суперадминистратору.'); return; }
            $this->store->queueExport($id);
            $this->reply($id, '📊 Готовим Excel с общей базой отправленных заявок.');
            return;
        }
        if (preg_match('/^\/requests(?:\s+([1-9][0-9]*))?$/D', $text, $match)) {
            if (!$this->approveUsers($id)) { $this->reply($id, 'Недостаточно прав для рассмотрения заявок.'); return; }
            $users = $this->store->applications(isset($match[1]) ? (int) $match[1] : null);
            if (!$users) { $this->reply($id, 'Заявок не найдено.'); }
            foreach ($users as $user) { ApplicationCard::send($this->store, $id, $user); }
            if (count($users) === 30) { $this->reply($id, 'Показаны первые 30 заявок. После рассмотрения вызовите /requests ещё раз. Поиск: /requests ID.'); }
            return;
        }
        if (preg_match('/^\/retry\s+([1-9][0-9]*)$/D', $text, $match)) {
            $broadcast = $this->store->broadcast((int) $match[1]);
            if (!$broadcast || !$broadcast['approved_by'] || (!$this->super($id) && $broadcast['admin_id'] !== $id)) {
                $this->reply($id, 'Рассылка недоступна или ещё не одобрена.');
                return;
            }
            $this->reply($id, $this->store->retryBroadcast($broadcast['id']) ? 'Неуспешные задания поставлены на повтор.' : 'Неуспешных заданий нет.');
            return;
        }
        if ($text === '/cancel') {
            $session = $this->store->session($id);
            if ($session && in_array($session['kind'], ['draft:subject', 'draft:text'], true)) {
                $draft = $this->store->draft($session['id']);
                if ($draft && $draft['admin_id'] === $id && in_array($draft['status'], ['draft', 'changes'], true)) {
                    $this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'cancelled']);
                }
            }
            $this->store->saveSession($id, null);
            $this->reply($id, 'Текущее действие отменено.');
            return;
        }
        if (preg_match('/^\/draft\s+([1-9][0-9]*)$/D', $text, $match)) {
            $draft = $this->store->draft((int) $match[1]);
            if ($draft && ($draft['admin_id'] === $id || $this->super($id))) { $this->preview($id, $draft); } else { $this->reply($id, 'Черновик недоступен.'); }
            return;
        }
        $help = "📝 Отправьте текст объявления, затем тему письма. Рассылка начнётся только после одобрения суперадминистратором.\n/draft ID — открыть черновик\n/retry ID — повторить ошибки рассылки\n/cancel — отменить ввод\n/help — помощь";
        if ($this->super($id)) { $help = Messages::text('RU', 'superadmin_broadcast_help'); }
        if ($this->approveUsers($id)) { $help .= "\n/requests [ID] — заявки пользователей"; }
        if ($this->super($id) || $this->adminsCanExport) { $help .= "\n/userdata — общая база Excel"; }
        if ($this->approveUsers($id) || $this->adminsCanExport) { $help .= "\n/retryjob ID — повторить ошибку комментария или Excel"; }
        if ($this->super($id)) { $help .= "\n/unsubscribe ID — отписать пользователя"; }
        $this->reply($id, $help);
    }

    public function callback(int $id, string $data, array $from = []): void
    {
        if (preg_match('/^app:(approve|changes|reject|reopen):([1-9][0-9]*):([0-9]+)$/D', $data, $match)) {
            if (!$this->approveUsers($id)) { $this->reply($id, 'Недостаточно прав.'); return; }
            [$all, $action, $userId, $revision] = $match;
            $user = $this->store->user((int) $userId);
            $expected = $action === 'reopen' ? 'rejected' : 'pending';
            if (!$user || $user['revision'] !== (int) $revision || $user['status'] !== $expected) { $this->stale($id); return; }
            if (in_array($action, ['changes', 'reject'], true)) {
                $this->store->saveSession($id, ['kind' => 'app:' . $action, 'id' => (int) $userId, 'revision' => (int) $revision]);
                $this->reply($id, 'Укажите причину. Комментарий будет переведён на язык пользователя. /cancel — отменить.');
                return;
            }
            $status = $action === 'approve' ? 'approved' : 'draft';
            if ($this->store->decideApplication((int) $userId, (int) $revision, $status, $id)) {
                $this->store->queueReply((int) $userId, Messages::text($user['language'], $action === 'approve' ? 'approved' : 'reopened', $user));
                if ($action === 'approve' && !$this->store->addCatchup((int) $userId, $user['language'])) {
                    $this->store->queueReply((int) $userId, Messages::text($user['language'], 'empty'));
                }
                $this->reply($id, $action === 'approve' ? '✅ Пользователь одобрен.' : 'Повторная подача разрешена.');
            }
            return;
        }
        if (!preg_match('/^draft:(send|submit|approve|return|text|subject|cancel):([1-9][0-9]*):([0-9]+)$/D', $data, $match)) { return; }
        [$all, $action, $draftId, $version] = $match;
        $draft = $this->store->draft((int) $draftId);
        if (!$draft || $draft['version'] !== (int) $version) { $this->stale($id); return; }
        if (in_array($action, ['approve', 'return'], true)) {
            if (!$this->super($id)) { $this->reply($id, 'Только суперадминистратор может согласовать рассылку.'); return; }
            if ($draft['status'] !== 'pending') { $this->stale($id); return; }
            if ($action === 'return') {
                $this->store->saveSession($id, ['kind' => 'draft:return', 'id' => $draft['id'], 'version' => $draft['version']]);
                $this->reply($id, 'Напишите обязательный комментарий для автора. /cancel — отменить.');
                return;
            }
            $broadcast = $this->store->approveDraft($draft['id'], $draft['version'], $id);
            if ($broadcast) {
                foreach (array_unique([$id, $draft['admin_id']]) as $staff) {
                    $this->reply($staff, Messages::text('RU', $this->super($staff) ? 'broadcast_approved_staff' : 'broadcast_approved_author', $broadcast));
                }
            }
            return;
        }
        if ($draft['admin_id'] !== $id || !in_array($draft['status'], ['draft', 'changes'], true)) {
            $this->reply($id, 'Изменение недоступно. Дождитесь возврата на доработку.');
            return;
        }
        if ($action === 'send' || ($action === 'submit' && $this->super($id))) {
            if (!$this->super($id)) { $this->reply($id, Messages::text('RU', 'draft_send_denied')); return; }
            $this->sendOwnDraft($id, $draft);
            return;
        }
        if ($action === 'submit') {
            if (trim($draft['subject']) === '') { $this->reply($id, Messages::text('RU', 'draft_subject_required')); return; }
            $this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'pending']);
            $this->store->saveSession($id, null);
            $author = $this->authorLabel($id, $from);
            foreach ($this->superadmins as $super) { $this->preview($super, $this->store->draft($draft['id']), $author); }
            $this->reply($id, '📨 Объявление отправлено на согласование.');
        } elseif ($action === 'cancel') {
            $this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'cancelled']);
            $this->store->saveSession($id, null);
            $this->reply($id, 'Черновик отменён.');
        } else {
            $this->store->saveSession($id, ['kind' => 'draft:' . $action, 'id' => $draft['id'], 'version' => $draft['version']]);
            $this->editPrompt($id, $draft[$action]);
        }
    }

    private function sendOwnDraft(int $id, array $draft): void
    {
        if (trim($draft['subject']) === '') {
            $this->reply($id, Messages::text('RU', 'draft_subject_required'));
            return;
        }
        // Both transitions and the audience snapshot belong to the current Kernel transaction.
        if (!$this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'pending'])) {
            $this->stale($id);
            return;
        }
        $broadcast = $this->store->approveDraft($draft['id'], $draft['version'] + 1, $id);
        $this->store->saveSession($id, null);
        if ($broadcast) {
            $this->reply($id, Messages::text('RU', 'broadcast_started', $broadcast));
        }
    }

    private function editPrompt(int $id, string $value): void
    {
        $this->reply($id, Messages::text('RU', 'draft_edit_prompt'));
        $parts = TextParts::split($value);
        foreach ($parts as $i => $part) {
            $options = ['entities' => [['type' => 'pre', 'offset' => 0, 'length' => intdiv(strlen(mb_convert_encoding($part, 'UTF-16LE', 'UTF-8')), 2)]]];
            if ($i === count($parts) - 1) {
                $options['reply_markup'] = ['force_reply' => true, 'input_field_placeholder' => Messages::text('RU', 'draft_edit_placeholder')];
            }
            $this->store->queueReply($id, $part, $options);
        }
    }

    private function applicationComment(int $id, array $session, string $text): void
    {
        if (!$this->approveUsers($id)) { $this->reply($id, 'Недостаточно прав.'); return; }
        $user = $this->store->user($session['id']);
        $status = $session['kind'] === 'app:reject' ? 'rejected' : 'changes';
        if (!$user || !$this->store->decideApplication($user['id'], $session['revision'], $status, $id)) { $this->stale($id); return; }
        $options = $status === 'changes' ? ['reply_markup' => ['inline_keyboard' => [[
            ['text' => Messages::text($user['language'], 'edit'), 'callback_data' => 'reg:edit'],
        ]]]] : [];
        $this->store->queueComment($user['id'], $user['language'], $text, Messages::text($user['language'], $status), $id, $options);
        $this->store->saveSession($id, null);
        $this->reply($id, 'Решение сохранено. Комментарий поставлен на перевод и доставку.');
    }

    private function authorLabel(int $id, array $from): string
    {
        $username = trim($from['username'] ?? '');
        if ($username !== '') {
            return '@' . $username;
        }
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        return $name !== '' ? $name : (string) $id;
    }

    private function preview(int $id, array $draft, ?string $author = null): void
    {
        $rows = [];
        $suffix = $draft['id'] . ':' . $draft['version'];
        $ownEditable = $draft['admin_id'] === $id && in_array($draft['status'], ['draft', 'changes'], true);
        $direct = $ownEditable && $this->super($id);
        if ($draft['status'] === 'pending' && $this->super($id)) {
            $actions = ['approve' => 'draft_approve', 'return' => 'draft_return'];
        } elseif ($ownEditable) {
            $actions = [$direct ? 'send' : 'submit' => $direct ? 'draft_send' : 'draft_submit', 'text' => 'draft_edit_text', 'subject' => 'draft_edit_subject', 'cancel' => 'draft_cancel'];
        } else {
            $actions = [];
        }
        foreach ($actions as $action => $key) { $rows[] = [['text' => Messages::text('RU', $key), 'callback_data' => 'draft:' . $action . ':' . $suffix]]; }
        $content = Messages::text('RU', 'draft_subject_label') . ' ' . $draft['subject'] . "\n\n" . $draft['text'];
        if ($direct) {
            $this->reply($id, $content . "\n\n" . Messages::text('RU', 'draft_send_prompt'), $rows);
            return;
        }
        $signature = $this->super($id) ? "\n" . Messages::text('RU', 'draft_author', ['author' => $author ?? (string) $draft['admin_id']]) : '';
        $this->reply($id, Messages::text('RU', 'draft_preview_header', ['id' => $draft['id'], 'status' => $draft['status']]) . $signature . "\n" . $content, $rows);
    }

    private function stale(int $id): void
    {
        $this->store->saveSession($id, null);
        $this->reply($id, 'Это действие устарело или уже выполнено. Откройте актуальную заявку /requests ID или черновик /draft ID.');
    }
}
