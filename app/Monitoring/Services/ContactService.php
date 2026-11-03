<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Contact;

/** 電話通知の担当者名簿（v2 第7.2）。番号は E.164 で保存し、画面・監査では伏字。 */
class ContactService
{
    public function __construct(private readonly AuditService $audit) {}

    public static function normalizePhone(string $phone): string
    {
        $p = preg_replace('/[\s\-()]/', '', trim($phone));
        if (preg_match('/^0\d{9,10}$/', $p)) {
            $p = '+81'.substr($p, 1); // 国内表記（090…）を E.164 へ
        }
        if (! preg_match('/^\+[1-9]\d{6,14}$/', $p)) {
            throw new \InvalidArgumentException('電話番号の形式が正しくありません（例: 090-1234-5678 / +819012345678）。');
        }

        return $p;
    }

    public function save(?Contact $contact, array $data, array $actor, string $reason): Contact
    {
        if (mb_strlen(trim($reason)) < 2) {
            throw new \InvalidArgumentException('変更理由を入力してください。');
        }
        $name = trim((string) ($data['name'] ?? $contact?->name ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('氏名を入力してください。');
        }
        foreach (['active_from', 'active_to'] as $k) {
            if (! empty($data[$k]) && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $data[$k])) {
                throw new \InvalidArgumentException('在席時間は HH:MM で入力してください。');
            }
        }
        $before = $contact ? $this->snapshot($contact) : null;
        $contact ??= new Contact(['order_no' => (int) (Contact::query()->max('order_no') ?? 0) + 10, 'enabled' => true]);
        $contact->name = $name;
        if (! empty($data['phone'])) {
            $contact->phone_e164 = self::normalizePhone((string) $data['phone']);
        }
        if (! $contact->phone_e164) {
            throw new \InvalidArgumentException('電話番号を入力してください。');
        }
        foreach (['active_from', 'active_to'] as $k) {
            if (array_key_exists($k, $data)) {
                $contact->{$k} = $data[$k] ?: null;
            }
        }
        if (array_key_exists('enabled', $data)) {
            $contact->enabled = (bool) $data['enabled'];
        }
        $contact->updated_user_id = $actor['id'] ?? null;
        $contact->updated_name = $actor['name'] ?? null;
        $contact->reason = $reason;
        $contact->save();
        $this->audit->record($actor, $before ? 'contact.update' : 'contact.create', 'contact', (string) $contact->id, $before, $this->snapshot($contact), $reason);

        return $contact;
    }

    /** 順序の入れ替え（id の配列の順に 10, 20, 30…） */
    public function reorder(array $ids, array $actor, string $reason): void
    {
        foreach (array_values($ids) as $i => $id) {
            Contact::query()->whereKey((int) $id)->update(['order_no' => ($i + 1) * 10]);
        }
        $this->audit->record($actor, 'contact.reorder', 'contact', 'all', null, ['order' => array_map('intval', $ids)], $reason ?: '順序変更');
    }

    private function snapshot(Contact $c): array
    {
        return ['name' => $c->name, 'phone' => $c->maskedPhone(), 'order_no' => $c->order_no, 'enabled' => (bool) $c->enabled, 'active_from' => $c->active_from, 'active_to' => $c->active_to];
    }
}
