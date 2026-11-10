<?php

declare(strict_types=1);

use yii\db\Migration;

class m261108_000016_add_account_security_module extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%users}}', 'two_factor_enabled', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%users}}', 'two_factor_secret', $this->string(255)->null());
        $this->addColumn('{{%users}}', 'two_factor_confirmed_at', $this->dateTime()->null());
        $this->addColumn('{{%users}}', 'two_factor_recovery_codes', $this->text()->null());

        $this->createTable('{{%password_reset_tokens}}', [
            'id' => $this->bigPrimaryKey(),
            'user_id' => $this->bigInteger()->notNull(),
            'token_hash' => $this->char(64)->notNull(),
            'expires_at' => $this->dateTime()->notNull(),
            'consumed_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('ux_password_reset_token_hash', '{{%password_reset_tokens}}', 'token_hash', true);
        $this->createIndex('ix_password_reset_user_expiry', '{{%password_reset_tokens}}', ['user_id', 'expires_at']);
        $this->addForeignKey('fk_password_reset_user', '{{%password_reset_tokens}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('{{%user_auth_challenges}}', [
            'id' => $this->bigPrimaryKey(),
            'user_id' => $this->bigInteger()->notNull(),
            'challenge_hash' => $this->char(64)->notNull(),
            'type' => $this->string(30)->notNull(),
            'expires_at' => $this->dateTime()->notNull(),
            'consumed_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('ux_user_auth_challenge_hash', '{{%user_auth_challenges}}', 'challenge_hash', true);
        $this->createIndex('ix_user_auth_challenge_user', '{{%user_auth_challenges}}', ['user_id', 'type', 'expires_at']);
        $this->addForeignKey('fk_user_auth_challenge_user', '{{%user_auth_challenges}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_user_auth_challenge_user', '{{%user_auth_challenges}}');
        $this->dropTable('{{%user_auth_challenges}}');
        $this->dropForeignKey('fk_password_reset_user', '{{%password_reset_tokens}}');
        $this->dropTable('{{%password_reset_tokens}}');
        $this->dropColumn('{{%users}}', 'two_factor_recovery_codes');
        $this->dropColumn('{{%users}}', 'two_factor_confirmed_at');
        $this->dropColumn('{{%users}}', 'two_factor_secret');
        $this->dropColumn('{{%users}}', 'two_factor_enabled');
    }
}
