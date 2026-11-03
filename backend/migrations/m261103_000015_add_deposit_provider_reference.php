<?php

declare(strict_types=1);

use yii\db\Migration;

/** Provider-side payment identifiers for idempotent funding reconciliation. */
final class m261103_000015_add_deposit_provider_reference extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%deposit_requests}}', 'provider_payment_id', $this->string(160)->null()->after('qr_expires_at'));
        $this->addColumn('{{%deposit_requests}}', 'provider_status', $this->string(40)->null()->after('provider_payment_id'));
        $this->createIndex('ux_deposit_provider_payment_id', '{{%deposit_requests}}', 'provider_payment_id', true);
    }

    public function safeDown(): void
    {
        $this->dropIndex('ux_deposit_provider_payment_id', '{{%deposit_requests}}');
        $this->dropColumn('{{%deposit_requests}}', 'provider_status');
        $this->dropColumn('{{%deposit_requests}}', 'provider_payment_id');
    }
}
