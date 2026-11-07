<?php

use yii\db\Migration;

class m261101_000008_add_email_verification_and_payment_details extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%beneficiaries}}', 'payout_card_token', $this->text()->null());
        $this->addColumn('{{%beneficiaries}}', 'payout_card_last4', $this->string(4)->null());
        $this->createTable('{{%email_verification_tokens}}', [
            'id' => $this->bigPrimaryKey(),
            'user_id' => $this->bigInteger()->notNull(),
            'token_hash' => $this->char(64)->notNull(),
            'expires_at' => $this->dateTime()->notNull(),
            'consumed_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('ux_email_verification_token_hash', '{{%email_verification_tokens}}', 'token_hash', true);
        $this->createIndex('ix_email_verification_user_expiry', '{{%email_verification_tokens}}', ['user_id', 'expires_at']);
        $this->addForeignKey('fk_email_verification_user', '{{%email_verification_tokens}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');
        $this->insert('{{%payment_methods}}', [
            'code' => 'card_transfer',
            'name' => 'Transferencia a tarjeta',
            'country_id' => 2,
            'direction' => 'outbound',
            'requires_bank_account' => false,
            'is_active' => true,
        ]);
    }

    public function safeDown()
    {
        $this->delete('{{%payment_methods}}', ['code' => 'card_transfer']);
        $this->dropForeignKey('fk_email_verification_user', '{{%email_verification_tokens}}');
        $this->dropTable('{{%email_verification_tokens}}');
        $this->dropColumn('{{%beneficiaries}}', 'payout_card_last4');
        $this->dropColumn('{{%beneficiaries}}', 'payout_card_token');
    }
}
