<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the fields required to make a deposit request a payable payment intent:
 * an opaque capability token for the public payment page, the QR kind that
 * tells the UI whether the code is a provider QR or an informational link,
 * and the settlement snapshot used when confirming the payment.
 */
final class m261106_000016_add_payment_intent_fields extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%deposit_requests}}', 'pay_token', $this->string(64)->null());
        $this->addColumn('{{%deposit_requests}}', 'qr_kind', $this->string(32)->notNull()->defaultValue('none'));
        $this->addColumn('{{%deposit_requests}}', 'paid_amount', $this->decimal(20, 2)->null());
        $this->addColumn('{{%deposit_requests}}', 'paid_at', $this->dateTime()->null());
        $this->createIndex('ux_deposit_requests_pay_token', '{{%deposit_requests}}', 'pay_token', true);
    }

    public function safeDown(): void
    {
        $this->dropIndex('ux_deposit_requests_pay_token', '{{%deposit_requests}}');
        $this->dropColumn('{{%deposit_requests}}', 'paid_at');
        $this->dropColumn('{{%deposit_requests}}', 'paid_amount');
        $this->dropColumn('{{%deposit_requests}}', 'qr_kind');
        $this->dropColumn('{{%deposit_requests}}', 'pay_token');
    }
}
