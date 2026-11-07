<?php
require 'vendor/autoload.php';
$config = require 'config/web.php';
$app = new \yii\web\Application($config);
$db = $app->getDb();
try {
    $rows = $db->createCommand(
        "SELECT a.public_id,a.country_id,c.iso2 AS country_code,a.currency_code,a.bank_code,a.bank_name,
                a.account_holder_name,a.legal_entity_name,a.account_type,a.account_mask,a.routing_mask,
                a.environment,a.status,a.valid_from,a.valid_until,a.notes,a.created_at,a.updated_at,
                STRING_AGG(fam.payment_method_code, ',' ORDER BY fam.payment_method_code) AS methods
         FROM {{%platform_funding_accounts}} a
         INNER JOIN {{%countries}} c ON c.id=a.country_id
         LEFT JOIN {{%funding_account_methods}} fam ON fam.funding_account_id=a.id
         GROUP BY a.id, c.iso2 ORDER BY a.created_at DESC"
    )->queryAll();
    echo "SUCCESS: " . count($rows) . " rows\n";
    print_r($rows);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
