<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\PhoneNumber;

final class Registration
{
    public function __construct(private Store $store, private array $reviewers) {}

    public function message(int $id, array $message): void
    {
        $text = trim($message['text'] ?? '');
        $user = $this->store->user($id);
        if (!$user) {
            if ($text === '/help') {
                $this->store->queuePhoto($id, Messages::text('RU', 'help'));
                return;
            }
            if ($text !== '/start') {
                $this->store->queueReply($id, '/start — Русский / English / Español / Français');
                return;
            }
            $user = ['id' => $id, 'language' => 'RU', 'step' => 'language', 'name' => '', 'company' => '', 'country' => '', 'phone' => '', 'email' => '', 'subscribed' => 0, 'completed' => 0, 'choosing_language' => 1, 'status' => 'draft', 'revision' => 0, 'edit_field' => ''];
            $this->store->saveUser($user);
            $this->reply($user, 'intro');
        }
        if ($text === '/language') {
            $user['choosing_language'] = 1;
            $this->store->saveUser($user);
            $this->reply($user, 'language', Messages::languageKeyboard());
            return;
        }
        if ($text === '/start') {
            if ($user['status'] === 'approved') {
                $user['subscribed'] = 1;
                $this->store->saveUser($user);
            }
            $this->prompt($user);
            return;
        }
        if ($text === '/help') {
            $this->store->queuePhoto($id, Messages::text($user['language'], 'help'));
            return;
        }
        if (str_starts_with($text, '/')) { $this->reply($user, 'help'); return; }
        if (!in_array($user['status'], ['draft', 'changes', 'unsubscribed'], true)) { $this->prompt($user); return; }
        if ($text === Messages::text($user['language'], 'back')) { $this->back($user); return; }
        if ($user['choosing_language'] || in_array($user['step'], ['language', 'begin', 'review', 'country_confirm'], true)) { $this->prompt($user); return; }
        $step = $user['step'];
        if ($step === 'phone') {
            if (isset($message['contact'])) {
                if (($message['contact']['user_id'] ?? null) !== $id) { $this->reply($user, 'own_contact'); return; }
                $text = '+' . ltrim((string) $message['contact']['phone_number'], '+');
            }
            $value = PhoneNumber::normalize($text);
            if ($value === null) { $this->reply($user, 'invalid_phone'); return; }
            if ($this->store->contactTaken('phone', $value, $id)) { $this->reply($user, 'duplicate_phone'); return; }
            $user['phone'] = $value;
            $country = PhoneNumber::country($value, $user['language']);
            // A suggestion is not a confirmed country and is recomputed for the prompt.
            $user['step'] = $country ? 'country_confirm' : 'country';
        } elseif ($step === 'email') {
            if (strlen($text) > 254 || !filter_var($text, FILTER_VALIDATE_EMAIL)) { $this->reply($user, 'invalid_email'); return; }
            if ($this->store->contactTaken('email', $text, $id)) { $this->reply($user, 'duplicate_email'); return; }
            $user['email'] = $text;
            $user['step'] = 'review';
            $user['edit_field'] = '';
        } elseif (in_array($step, ['name', 'company', 'country'], true)) {
            if ($text === '' || mb_strlen($text) > 200) { $this->reply($user, 'invalid'); return; }
            $user[$step] = $text;
            $user['step'] = $user['edit_field'] !== '' ? 'review' : ['name' => 'company', 'company' => 'phone', 'country' => 'email'][$step];
            $user['edit_field'] = '';
        } else {
            $this->prompt($user);
            return;
        }
        $this->store->saveUser($user);
        $this->prompt($user);
    }

    public function callback(int $id, string $data): void
    {
        $user = $this->store->user($id);
        if (!$user) { return; }
        if (str_starts_with($data, 'language:')) {
            $language = substr($data, 9);
            if (!$user['choosing_language'] || !isset(Messages::LANGUAGES[$language])) { return; }
            $user['language'] = $language;
            $user['choosing_language'] = 0;
            if ($user['step'] === 'language') { $user['step'] = 'begin'; }
            $this->store->saveUser($user);
            if ($user['status'] === 'approved') { $this->reply($user, 'changed'); } else { $this->prompt($user); }
            return;
        }
        if (!in_array($user['status'], ['draft', 'changes', 'unsubscribed'], true)) { $this->prompt($user); return; }
        if ($data === 'reg:back') { $this->back($user); return; }
        if ($data === 'reg:begin' && $user['step'] === 'begin') {
            $user['step'] = 'name';
        } elseif ($data === 'reg:country:other' && in_array($user['step'], ['country_confirm', 'country'], true)) {
            $user['step'] = 'country';
        } elseif ($data === 'reg:country:yes' && $user['step'] === 'country_confirm') {
            $country = PhoneNumber::country($user['phone'], $user['language']);
            if (!$country) { return; }
            $user['country'] = $country;
            $user['step'] = $user['edit_field'] !== '' ? 'review' : 'email';
            $user['edit_field'] = '';
        } elseif ($data === 'reg:edit' && $user['step'] === 'review') {
            $buttons = [];
            foreach (['name', 'company', 'phone', 'country', 'email'] as $field) { $buttons[] = [$this->button($user, 'field_' . $field, 'reg:field:' . $field)]; }
            $this->reply($user, 'choose_field', ['reply_markup' => ['inline_keyboard' => $buttons]]);
            return;
        } elseif (str_starts_with($data, 'reg:field:') && $user['step'] === 'review') {
            $field = substr($data, 10);
            if (!in_array($field, ['name', 'company', 'phone', 'country', 'email'], true)) { return; }
            $user['step'] = $field;
            $user['edit_field'] = $field;
        } elseif ($data === 'reg:submit' && $user['step'] === 'review') {
            foreach (['name', 'company', 'phone', 'country', 'email'] as $field) {
                if (trim($user[$field]) === '') {
                    $user['step'] = $field;
                    $this->store->saveUser($user);
                    $this->prompt($user);
                    return;
                }
            }
            foreach (['phone', 'email'] as $field) {
                if ($this->store->contactTaken($field, $user[$field], $id)) {
                    $user['step'] = $field;
                    $user['edit_field'] = $field;
                    $this->store->saveUser($user);
                    $this->reply($user, 'duplicate_' . $field);
                    $this->prompt($user);
                    return;
                }
            }
            if ($this->store->submitApplication($id)) {
                $this->reply($user, 'submitted', ['reply_markup' => ['remove_keyboard' => true]]);
                $user = $this->store->user($id);
                foreach ($this->reviewers as $reviewer) { ApplicationCard::send($this->store, $reviewer, $user); }
            }
            return;
        } else {
            return;
        }
        $this->store->saveUser($user);
        $this->prompt($user);
    }

    private function back(array $user): void
    {
        if ($user['choosing_language'] || in_array($user['step'], ['language', 'begin'], true)) {
            $this->prompt($user);
            return;
        }
        if ($user['edit_field'] !== '') {
            $user['step'] = 'review';
            $user['edit_field'] = '';
        } else {
            $user['step'] = ['name' => 'begin', 'company' => 'name', 'phone' => 'company', 'country' => 'phone', 'country_confirm' => 'phone', 'email' => 'country', 'review' => 'email'][$user['step']] ?? $user['step'];
        }
        $this->store->saveUser($user);
        $this->prompt($user);
    }

    private function button(array $user, string $key, string $data): array
    {
        return ['text' => Messages::text($user['language'], $key), 'callback_data' => $data];
    }

    private function reply(array $user, string $key, array $options = [], array $values = []): void
    {
        if (isset($options['reply_markup']['inline_keyboard'])) {
            // Inline markup cannot remove a persistent reply keyboard in the same message.
            $this->store->queueReply($user['id'], Messages::text($user['language'], 'inline_navigation'), ['reply_markup' => ['remove_keyboard' => true]]);
        }
        $this->store->queueReply($user['id'], Messages::text($user['language'], $key, $values), $options);
    }

    private function prompt(array $user): void
    {
        if ($user['choosing_language']) {
            $this->reply($user, 'language', Messages::languageKeyboard());
            return;
        }
        if (in_array($user['status'], ['pending', 'rejected', 'approved'], true)) {
            $this->reply($user, $user['status'] === 'approved' ? 'active' : $user['status']);
            return;
        }
        $key = $user['step'];
        $values = [];
        $options = ['reply_markup' => ['keyboard' => [[['text' => Messages::text($user['language'], 'back')]]], 'resize_keyboard' => true]];
        if ($key === 'begin') {
            $options = ['reply_markup' => ['inline_keyboard' => [[$this->button($user, 'begin_button', 'reg:begin')]]]];
        }
        if ($key === 'phone') {
            array_unshift($options['reply_markup']['keyboard'], [['text' => Messages::text($user['language'], 'contact'), 'request_contact' => true]]);
        }
        if ($key === 'country_confirm') {
            $values = ['country' => PhoneNumber::country($user['phone'], $user['language']) ?? ''];
            $options = ['reply_markup' => ['inline_keyboard' => [[$this->button($user, 'yes', 'reg:country:yes')], [$this->button($user, 'other_country', 'reg:country:other')], [$this->button($user, 'back', 'reg:back')]]]];
        }
        if ($key === 'review') {
            $values = $user;
            $values['language'] = Messages::LANGUAGES[$user['language']];
            $options = ['reply_markup' => ['inline_keyboard' => [[$this->button($user, 'submit', 'reg:submit')], [$this->button($user, 'edit', 'reg:edit')], [$this->button($user, 'back', 'reg:back')]]]];
        }
        $this->reply($user, $key, $options, $values);
    }
}
